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

#[\PHPUnit\Framework\Attributes\CoversClass(get_courses::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\courses::class)]
/**
 * Tests for local_arcs_get_courses.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_courses
 * @covers     \local_arcs\local\source\courses
 */
final class get_courses_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @param int[] $ids Ids to fetch.
     * @param int[] $categoryids Categories whose courses to fetch.
     * @return array
     */
    private function call(int $after = 0, int $limit = source::DEFAULT_LIMIT, array $ids = [], array $categoryids = []): array {
        return external_api::clean_returnvalue(
            get_courses::execute_returns(),
            get_courses::execute($after, $limit, $ids, $categoryids)
        );
    }

    /**
     * The ids of the courses in a result.
     *
     * @param array $result The cleaned result.
     * @return int[]
     */
    private function ids(array $result): array {
        return array_column($result['courses'], 'id');
    }

    /**
     * A site with no courses returns none: the site course is never returned.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->assertSame(['courses' => [], 'next' => null], $this->call());
        $this->assertSame([], $this->call(0, 10, [SITEID])['courses']);
    }

    /**
     * Every field is returned, raw.
     */
    public function test_execute_returns_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'shortname' => 'FS101',
            'fullname' => 'Fire safety',
            'idnumber' => 'C-1',
            'format' => 'topics',
            'visible' => 0,
            'startdate' => 1700000000,
            'enddate' => 1710000000,
            'enablecompletion' => 1,
        ]);
        // Set directly: the generator cleans names, and the export must not.
        $DB->set_field('course', 'fullname', 'Fire & <b>safety</b>', ['id' => $course->id]);
        $record = $DB->get_record('course', ['id' => $course->id]);

        $this->assertSame([[
            'id' => (int) $course->id,
            'category' => (int) $category->id,
            'shortname' => 'FS101',
            'fullname' => 'Fire & <b>safety</b>',
            'idnumber' => 'C-1',
            'format' => 'topics',
            'visible' => false,
            'startdate' => 1700000000,
            'enddate' => 1710000000,
            'enablecompletion' => true,
            'timecreated' => (int) $record->timecreated,
            'timemodified' => (int) $record->timemodified,
            'deletioninprogress' => false,
        ]], $this->call()['courses']);
    }

    /**
     * A reader can page through courses with next.
     */
    public function test_execute_pages_with_cursor(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $one = $this->getDataGenerator()->create_course();
        $two = $this->getDataGenerator()->create_course();

        $first = $this->call(0, 1);
        $second = $this->call($first['next'], 1);

        $this->assertSame([(int) $one->id], $this->ids($first));
        $this->assertSame([(int) $two->id], $this->ids($second));
        $this->assertNull($second['next']);
    }

    /**
     * With ids, exactly the existing courses other than the site course are returned.
     */
    public function test_execute_by_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $one = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_course();

        $this->assertSame([(int) $one->id], $this->ids($this->call(0, 10, [SITEID, (int) $one->id, 999999])));
    }

    /**
     * With categoryids, courses in those categories and the categories below them are returned.
     */
    public function test_execute_by_category_subtree(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $generator = $this->getDataGenerator();
        $top = $generator->create_category();
        $child = $generator->create_category(['parent' => $top->id]);
        $other = $generator->create_category();
        $intop = $generator->create_course(['category' => $top->id]);
        $inchild = $generator->create_course(['category' => $child->id]);
        $generator->create_course(['category' => $other->id]);

        $this->assertSame([(int) $intop->id, (int) $inchild->id], $this->ids($this->call(0, 10, [], [(int) $top->id])));
    }

    /**
     * ids and categoryids together are refused.
     */
    public function test_execute_ids_with_filter(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(invalid_parameter_exception::class);
        get_courses::execute(0, 10, [2], [1]);
    }

    /**
     * A limit above the maximum is refused with limit_exceeded.
     */
    public function test_execute_limit_exceeded(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::MAX_LIMIT));
        get_courses::execute(0, source::MAX_LIMIT + 1);
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_courses::execute();
    }
}
