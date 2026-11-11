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

#[\PHPUnit\Framework\Attributes\CoversClass(get_grade_items::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\grade_items::class)]
/**
 * Tests for local_arcs_get_grade_items.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_grade_items
 * @covers     \local_arcs\local\source\grade_items
 */
final class get_grade_items_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @param int[] $ids Ids to fetch.
     * @param int[] $courseids Courses whose grade items to fetch.
     * @return array
     */
    private function call(int $after = 0, int $limit = source::DEFAULT_LIMIT, array $ids = [], array $courseids = []): array {
        return external_api::clean_returnvalue(
            get_grade_items::execute_returns(),
            get_grade_items::execute($after, $limit, $ids, $courseids)
        );
    }

    /**
     * The ids of the grade items in a result.
     *
     * @param array $result The cleaned result.
     * @return int[]
     */
    private function ids(array $result): array {
        return array_column($result['gradeitems'], 'id');
    }

    /**
     * The id of a course's course total item.
     *
     * @param int $courseid The course.
     * @return int
     */
    private function course_item_id(int $courseid): int {
        return (int) \grade_item::fetch_course_item($courseid)->id;
    }

    /**
     * A site with no grade items returns none.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->assertSame(['gradeitems' => [], 'next' => null], $this->call());
    }

    /**
     * Every field of a manual item is returned; the course total has no name and no module.
     */
    public function test_execute_returns_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $manual = $this->getDataGenerator()->create_grade_item([
            'courseid' => $course->id,
            'itemname' => 'Essay <b>one</b>',
            'idnumber' => 'E1',
            'grademax' => 50,
            'gradepass' => 25.5,
        ]);
        $courseitemid = $this->course_item_id((int) $course->id);
        $record = $DB->get_record('grade_items', ['id' => $manual->id]);

        $rows = array_column($this->call()['gradeitems'], null, 'id');

        $this->assertSame([
            'id' => (int) $manual->id,
            'courseid' => (int) $course->id,
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'coursemoduleid' => null,
            'itemname' => 'Essay <b>one</b>',
            'idnumber' => 'E1',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademin' => 0.0,
            'grademax' => 50.0,
            'gradepass' => 25.5,
            'scaleid' => null,
            'scaleitems' => [],
            'hidden' => 0,
            'needsupdate' => (bool) $record->needsupdate,
            'timemodified' => (int) $record->timemodified,
        ], $rows[$manual->id]);
        $this->assertSame('course', $rows[$courseitemid]['itemtype']);
        $this->assertNull($rows[$courseitemid]['itemname']);
        $this->assertNull($rows[$courseitemid]['coursemoduleid']);
    }

    /**
     * An activity's item carries its course module id.
     */
    public function test_execute_returns_course_module(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        $params = ['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id, 'courseid' => $course->id];
        $item = \grade_item::fetch($params);

        $row = $this->call(0, 10, [(int) $item->id])['gradeitems'][0];

        $this->assertSame('mod', $row['itemtype']);
        $this->assertSame('assign', $row['itemmodule']);
        $this->assertSame((int) $assign->id, $row['iteminstance']);
        $this->assertSame((int) $assign->cmid, $row['coursemoduleid']);
    }

    /**
     * A scale item carries its scale's items, trimmed, lowest first; the scale is read once per page.
     */
    public function test_execute_returns_scale_items(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Poor, Fair ,Good']);
        $first = $this->getDataGenerator()->create_grade_item(['courseid' => $course->id, 'scaleid' => $scale->id]);
        $second = $this->getDataGenerator()->create_grade_item(['courseid' => $course->id, 'scaleid' => $scale->id]);

        $reads = $DB->perf_get_reads();
        $rows = $this->call(0, 10, [(int) $first->id])['gradeitems'];
        $onereads = $DB->perf_get_reads() - $reads;
        $reads = $DB->perf_get_reads();
        $this->call(0, 10, [(int) $first->id, (int) $second->id]);
        $tworeads = $DB->perf_get_reads() - $reads;

        $this->assertSame(GRADE_TYPE_SCALE, $rows[0]['gradetype']);
        $this->assertSame((int) $scale->id, $rows[0]['scaleid']);
        $this->assertSame(['Poor', 'Fair', 'Good'], $rows[0]['scaleitems']);
        $this->assertSame(1.0, $rows[0]['grademin']);
        $this->assertSame(3.0, $rows[0]['grademax']);
        $this->assertLessThanOrEqual($onereads + 1, $tworeads);
    }

    /**
     * A reader can page with next; ids return exactly the existing items.
     */
    public function test_execute_pages_and_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $manual = (int) $this->getDataGenerator()->create_grade_item(['courseid' => $course->id])->id;
        $all = [$this->course_item_id((int) $course->id), $manual];
        sort($all);

        $page1 = $this->call(0, 1);
        $page2 = $this->call($page1['next'], 1);

        $this->assertSame([$all[0]], $this->ids($page1));
        $this->assertSame([$all[1]], $this->ids($page2));
        $this->assertNull($page2['next']);
        $this->assertSame([$manual], $this->ids($this->call(0, 10, [$manual, 999999])));
    }

    /**
     * With courseids, only those courses' items are returned; a course with none returns none.
     */
    public function test_execute_by_course(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $empty = $this->getDataGenerator()->create_course();
        $manual = (int) $this->getDataGenerator()->create_grade_item(['courseid' => $course->id])->id;
        $this->getDataGenerator()->create_grade_item(['courseid' => $other->id]);
        $expected = [$this->course_item_id((int) $course->id), $manual];
        sort($expected);

        $page1 = $this->call(0, 1, [], [(int) $course->id]);
        $page2 = $this->call($page1['next'], 1, [], [(int) $course->id]);

        $this->assertSame($expected, array_merge($this->ids($page1), $this->ids($page2)));
        $this->assertSame([], $this->ids($this->call(0, 10, [], [(int) $empty->id])));
    }

    /**
     * Site course grade items (front page activities) are never returned.
     */
    public function test_execute_leaves_out_site_course(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $siteitem = (int) $this->getDataGenerator()->create_grade_item(['courseid' => SITEID])->id;

        $this->assertSame([], $this->ids($this->call()));
        $this->assertSame([], $this->ids($this->call(0, 10, [$siteitem])));
    }

    /**
     * ids and courseids together are refused.
     */
    public function test_execute_ids_with_courseids(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(invalid_parameter_exception::class);
        get_grade_items::execute(0, 10, [1], [2]);
    }

    /**
     * More courseids than the maximum are refused with limit_exceeded.
     */
    public function test_execute_too_many_course_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::MAX_LIMIT));
        get_grade_items::execute(0, 10, [], range(1, source::MAX_LIMIT + 1));
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_grade_items::execute();
    }
}
