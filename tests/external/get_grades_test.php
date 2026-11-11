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

namespace local_arcs\external;

use core\exception\invalid_parameter_exception;
use core\exception\moodle_exception;
use core_external\external_api;
use local_arcs\local\source\source;
use local_arcs\tests\exporter_trait;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(get_grades::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\grades::class)]
/**
 * Tests for local_arcs_get_grades.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_grades
 * @covers     \local_arcs\local\source\grades
 */
final class get_grades_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @param int[] $ids Ids to fetch.
     * @param int[] $courseids Courses whose grades to fetch.
     * @param int[] $itemids Grade items whose grades to fetch.
     * @return array
     */
    private function call(
        int $after = 0,
        int $limit = source::DEFAULT_LIMIT,
        array $ids = [],
        array $courseids = [],
        array $itemids = []
    ): array {
        return external_api::clean_returnvalue(
            get_grades::execute_returns(),
            get_grades::execute($after, $limit, $ids, $courseids, $itemids)
        );
    }

    /**
     * Creates a manual grade item in a course.
     *
     * @param int $courseid The course.
     * @return \grade_item
     */
    private function item(int $courseid): \grade_item {
        $record = $this->getDataGenerator()->create_grade_item(['courseid' => $courseid]);
        return \grade_item::fetch(['id' => $record->id]);
    }

    /**
     * Grades a new user on an item, as the gradebook does.
     *
     * @param \grade_item $item The grade item.
     * @param float $grade The grade.
     * @return int The grade id.
     */
    private function grade(\grade_item $item, float $grade): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $item->update_final_grade($user->id, $grade, 'test');
        return (int) $DB->get_field('grade_grades', 'id', ['itemid' => $item->id, 'userid' => $user->id]);
    }

    /**
     * The ids of the grades in a result.
     *
     * @param array $result The cleaned result.
     * @return int[]
     */
    private function ids(array $result): array {
        return array_column($result['grades'], 'id');
    }

    /**
     * A site with no grades returns none.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->assertSame(['grades' => [], 'next' => null], $this->call());
    }

    /**
     * Every field is returned, and feedback is not.
     */
    public function test_execute_returns_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $item = $this->item((int) $course->id);
        $user = $this->getDataGenerator()->create_user();
        $item->update_final_grade($user->id, 72.5, 'test', 'Private feedback');
        $record = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $user->id]);

        $rows = $this->call(0, 10, [(int) $record->id])['grades'];

        $this->assertSame([[
            'id' => (int) $record->id,
            'itemid' => (int) $item->id,
            'userid' => (int) $user->id,
            'finalgrade' => 72.5,
            'rawgrade' => $record->rawgrade === null ? null : (float) $record->rawgrade,
            'rawgrademin' => (float) $record->rawgrademin,
            'rawgrademax' => (float) $record->rawgrademax,
            'hidden' => 0,
            'overridden' => (int) $record->overridden,
            'timemodified' => (int) $record->timemodified,
        ]], $rows);
    }

    /**
     * A grade with no final grade has nulls; a hidden one shows it.
     */
    public function test_execute_returns_nulls_and_hidden(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $item = $this->item((int) $course->id);
        $id = $this->grade($item, 50);
        $DB->update_record('grade_grades', ['id' => $id, 'finalgrade' => null, 'rawgrade' => null, 'timemodified' => null]);
        $item->set_hidden(1, true);

        $row = $this->call(0, 10, [$id])['grades'][0];

        $this->assertNull($row['finalgrade']);
        $this->assertNull($row['rawgrade']);
        $this->assertNull($row['timemodified']);
        $this->assertSame(1, $row['hidden']);
    }

    /**
     * A reader can page with next; ids return exactly the existing grades.
     */
    public function test_execute_pages_and_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $item = $this->item((int) $course->id);
        $one = $this->grade($item, 10);
        $two = $this->grade($item, 20);

        $page1 = $this->call(0, 1);
        $page2 = $this->call($page1['next'], 1);

        $this->assertSame([$one], $this->ids($page1));
        $this->assertSame([$two], $this->ids($page2));
        $this->assertNull($page2['next']);
        $this->assertSame([$two], $this->ids($this->call(0, 10, [$two, 999999])));
    }

    /**
     * With courseids, only those courses' grades are returned; with itemids, only those items'.
     */
    public function test_execute_by_course_and_by_item(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $empty = $this->getDataGenerator()->create_course();
        $item = $this->item((int) $course->id);
        $seconditem = $this->item((int) $course->id);
        $one = $this->grade($item, 10);
        $two = $this->grade($item, 20);
        $three = $this->grade($seconditem, 30);
        $this->grade($this->item((int) $other->id), 40);

        $page1 = $this->call(0, 2, [], [(int) $course->id]);
        $page2 = $this->call($page1['next'], 2, [], [(int) $course->id]);

        $this->assertSame([$one, $two, $three], array_merge($this->ids($page1), $this->ids($page2)));
        $this->assertSame([$three], $this->ids($this->call(0, 10, [], [], [(int) $seconditem->id])));
        $this->assertSame([], $this->ids($this->call(0, 10, [], [(int) $empty->id])));
    }

    /**
     * Site course grades are never returned.
     */
    public function test_execute_leaves_out_site_course(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $id = $this->grade($this->item(SITEID), 10);

        $this->assertSame([], $this->ids($this->call()));
        $this->assertSame([], $this->ids($this->call(0, 10, [$id])));
    }

    /**
     * courseids and itemids together are refused, as is either with ids.
     */
    public function test_execute_filters_not_combined(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        try {
            get_grades::execute(0, 10, [1], [], [2]);
            $this->fail('ids with itemids should be refused');
        } catch (invalid_parameter_exception $e) {
            $this->assertInstanceOf(invalid_parameter_exception::class, $e);
        }
        try {
            get_grades::execute(0, 10, [1], [2]);
            $this->fail('ids with courseids should be refused');
        } catch (invalid_parameter_exception $e) {
            $this->assertInstanceOf(invalid_parameter_exception::class, $e);
        }
        $this->expectException(invalid_parameter_exception::class);
        get_grades::execute(0, 10, [], [1], [2]);
    }

    /**
     * More itemids than the maximum are refused with limit_exceeded.
     */
    public function test_execute_too_many_item_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::MAX_LIMIT));
        get_grades::execute(0, 10, [], [], range(1, source::MAX_LIMIT + 1));
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_grades::execute();
    }
}
