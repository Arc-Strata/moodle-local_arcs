<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_arcs;

use local_arcs\tests\changes_trait;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/course/externallib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
/**
 * Tests that core actions on grade items and grades record change log rows.
 *
 * Each test performs the real core action; none calls the observer directly.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\observer
 */
final class observer_grades_test extends \advanced_testcase {
    use changes_trait;

    /** @var \stdClass A course with its gradebook set up. */
    private \stdClass $course;

    /** @var \stdClass A user. */
    private \stdClass $user;

    /**
     * Resets the database the slow way, so the external observers run (see changes_trait), and
     * creates a course with its course total, and a user.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->course = $this->getDataGenerator()->create_course();
        \grade_category::fetch_course_category($this->course->id);
        $this->user = $this->getDataGenerator()->create_user();
    }

    /**
     * A grade item change row in the course.
     *
     * @param int $id The grade item id.
     * @param string $op upsert or delete.
     * @return array
     */
    private function item_change(int $id, string $op): array {
        return $this->change('grade_item', $id, $op, null, (int) $this->course->id);
    }

    /**
     * A grade change row for the user in the course.
     *
     * @param int $id The grade id.
     * @param string $op upsert or delete.
     * @return array
     */
    private function grade_change(int $id, string $op): array {
        return $this->change('grade', $id, $op, (int) $this->user->id, (int) $this->course->id);
    }

    /**
     * The user's grade id on an item.
     *
     * @param int $itemid The grade item.
     * @return int
     */
    private function grade_id(int $itemid): int {
        global $DB;
        return (int) $DB->get_field('grade_grades', 'id', ['itemid' => $itemid, 'userid' => $this->user->id], MUST_EXIST);
    }

    /**
     * Grades the user in an assignment, as the assignment does, through grade_update.
     *
     * @param \stdClass $assign The assignment.
     * @param float $grade The grade.
     */
    private function grade_assignment(\stdClass $assign, float $grade): void {
        $grades = ['userid' => $this->user->id, 'rawgrade' => $grade];
        grade_update('mod/assign', $this->course->id, 'mod', 'assign', $assign->id, 0, $grades);
    }

    /**
     * An assignment's grade item.
     *
     * @param \stdClass $assign The assignment.
     * @return \grade_item
     */
    private function assignment_item(\stdClass $assign): \grade_item {
        $params = ['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id, 'courseid' => $this->course->id];
        return \grade_item::fetch($params);
    }

    /**
     * Creating, updating and deleting a grade item record grade item changes.
     */
    public function test_grade_item_lifecycle(): void {
        $this->clear_changes();
        $record = $this->getDataGenerator()->create_grade_item(['courseid' => $this->course->id]);
        $id = (int) $record->id;
        $this->assertContains($this->item_change($id, 'upsert'), $this->changes());

        $item = \grade_item::fetch(['id' => $id]);
        $this->clear_changes();
        $item->itemname = 'Renamed';
        $item->update();
        $this->assertContains($this->item_change($id, 'upsert'), $this->changes());

        $this->clear_changes();
        $item->delete();
        $this->assertContains($this->item_change($id, 'delete'), $this->changes());
    }

    /**
     * Hiding an item with its grades records only the item; its grades are refetched through it.
     */
    public function test_grade_item_hidden(): void {
        $record = $this->getDataGenerator()->create_grade_item(['courseid' => $this->course->id]);
        $item = \grade_item::fetch(['id' => $record->id]);
        $item->update_final_grade($this->user->id, 40, 'test');
        $this->clear_changes();

        $item->set_hidden(1, true);

        $changes = $this->changes();
        $this->assertContains($this->item_change((int) $item->id, 'upsert'), $changes);
        $this->assertSame(['grade_item'], array_values(array_unique(array_column($changes, 'entity'))));
    }

    /**
     * An activity grading a user through grade_update records a grade upsert.
     */
    public function test_activity_grade(): void {
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 100]);
        $this->clear_changes();

        $this->grade_assignment($assign, 80);

        $item = $this->assignment_item($assign);
        $this->assertContains($this->grade_change($this->grade_id((int) $item->id), 'upsert'), $this->changes());
    }

    /**
     * Overriding a grade in the gradebook records a grade upsert.
     */
    public function test_override(): void {
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 100]);
        $this->grade_assignment($assign, 80);
        $item = $this->assignment_item($assign);
        $this->clear_changes();

        $item->update_final_grade($this->user->id, 95, 'gradebook');

        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $this->user->id]);
        $this->assertNotEmpty($grade->overridden);
        $this->assertContains($this->grade_change((int) $grade->id, 'upsert'), $this->changes());
    }

    /**
     * Recalculating a course's grades records an upsert for each changed grade, such as the course total.
     */
    public function test_regrade(): void {
        $record = $this->getDataGenerator()->create_grade_item(['courseid' => $this->course->id]);
        \grade_item::fetch(['id' => $record->id])->update_final_grade($this->user->id, 60, 'test');
        $this->clear_changes();

        grade_regrade_final_grades($this->course->id);

        $courseitem = \grade_item::fetch_course_item($this->course->id);
        $this->assertContains($this->grade_change($this->grade_id((int) $courseitem->id), 'upsert'), $this->changes());
    }

    /**
     * Deleting a user's grades records a grade delete for each.
     */
    public function test_grade_user_delete(): void {
        $record = $this->getDataGenerator()->create_grade_item(['courseid' => $this->course->id]);
        \grade_item::fetch(['id' => $record->id])->update_final_grade($this->user->id, 60, 'test');
        $id = $this->grade_id((int) $record->id);
        $this->clear_changes();

        grade_user_delete($this->user->id);

        $this->assertSame([$this->grade_change($id, 'delete')], $this->changes());
    }

    /**
     * Deleting an activity deletes its grade item, which is recorded.
     */
    public function test_course_module_deleted(): void {
        $this->setAdminUser();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 100]);
        $item = $this->assignment_item($assign);
        $this->clear_changes();

        \core_course_external::delete_modules([$assign->cmid]);

        $this->assertContains($this->item_change((int) $item->id, 'delete'), $this->changes());
    }

    /**
     * Site course grade items and grades are not recorded.
     */
    public function test_site_course_ignored(): void {
        $this->clear_changes();

        $record = $this->getDataGenerator()->create_grade_item(['courseid' => SITEID]);
        $item = \grade_item::fetch(['id' => $record->id]);
        $item->update_final_grade($this->user->id, 60, 'test');
        $item->delete();

        $this->assertSame([], $this->changes());
    }
}
