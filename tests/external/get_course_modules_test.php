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
use local_arcs\local\source\course_modules;
use local_arcs\local\source\source;
use local_arcs\tests\exporter_trait;

#[\PHPUnit\Framework\Attributes\CoversClass(get_course_modules::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(course_modules::class)]
/**
 * Tests for local_arcs_get_course_modules.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_course_modules
 * @covers     \local_arcs\local\source\course_modules
 */
final class get_course_modules_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @param int[] $ids Ids to fetch.
     * @param int[] $courseids Courses whose modules to fetch.
     * @return array
     */
    private function call(int $after = 0, int $limit = source::DEFAULT_LIMIT, array $ids = [], array $courseids = []): array {
        return external_api::clean_returnvalue(
            get_course_modules::execute_returns(),
            get_course_modules::execute($after, $limit, $ids, $courseids)
        );
    }

    /**
     * The ids of the course modules in a result.
     *
     * @param array $result The cleaned result.
     * @return int[]
     */
    private function ids(array $result): array {
        return array_column($result['coursemodules'], 'id');
    }

    /**
     * A site with no courses returns no modules.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->assertSame(['coursemodules' => [], 'next' => null], $this->call());
    }

    /**
     * Every field is returned, with the module type and the instance name.
     */
    public function test_execute_returns_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Induction',
            'idnumber' => 'P-1',
            'visible' => 0,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1,
            'completionexpected' => 1700000000,
        ]);
        $cm = $DB->get_record('course_modules', ['id' => $page->cmid]);

        $this->assertSame([[
            'id' => (int) $cm->id,
            'course' => (int) $course->id,
            'modname' => 'page',
            'instance' => (int) $page->id,
            'instancename' => 'Induction',
            'section' => (int) $cm->section,
            'idnumber' => 'P-1',
            'visible' => false,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionexpected' => 1700000000,
            'completiongradeitemnumber' => null,
            'completionview' => true,
            'added' => (int) $cm->added,
            'deletioninprogress' => false,
        ]], $this->call()['coursemodules']);
    }

    /**
     * Instance names are read once per module type per page: the reads do not grow with the page.
     */
    public function test_instance_names_read_per_type(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        for ($i = 0; $i < 4; $i++) {
            foreach (['page', 'label', 'url'] as $modname) {
                $generator->create_module($modname, ['course' => $course->id, 'name' => "{$modname} {$i}"]);
            }
        }
        $source = new course_modules($DB);

        $before = $DB->perf_get_reads();
        $small = $source->page(0, 3);
        $smallreads = $DB->perf_get_reads() - $before;
        $before = $DB->perf_get_reads();
        $large = $source->page(0, 12);
        $largereads = $DB->perf_get_reads() - $before;

        $this->assertCount(3, $small['rows']);
        $this->assertCount(12, $large['rows']);
        $this->assertSame(['page 3', 'label 3', 'url 3'], array_column(array_slice($large['rows'], 9), 'instancename'));
        $this->assertSame($smallreads, $largereads);
    }

    /**
     * A module whose type is not an installed plugin is returned without an instance name.
     */
    public function test_execute_module_type_not_installed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $moduleid = $DB->insert_record('modules', (object) ['name' => 'nosuchmodule', 'visible' => 1]);
        $DB->set_field('course_modules', 'module', $moduleid, ['id' => $page->cmid]);

        $row = $this->call()['coursemodules'][0];

        $this->assertSame('nosuchmodule', $row['modname']);
        $this->assertNull($row['instancename']);
    }

    /**
     * Modules in the site course are not returned, even by id.
     */
    public function test_execute_leaves_out_site_course(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $frontpage = $this->getDataGenerator()->create_module('page', ['course' => SITEID]);

        $this->assertSame([], $this->call()['coursemodules']);
        $this->assertSame([], $this->call(0, 10, [(int) $frontpage->cmid])['coursemodules']);
    }

    /**
     * With ids, exactly the existing modules are returned.
     */
    public function test_execute_by_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $one = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->assertSame([(int) $one->cmid], $this->ids($this->call(0, 10, [(int) $one->cmid, 999999])));
    }

    /**
     * With courseids, only those courses' modules are returned, a page at a time.
     */
    public function test_execute_by_course_pages(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $other = $generator->create_course();
        $first = $generator->create_module('page', ['course' => $course->id]);
        $generator->create_module('page', ['course' => $other->id]);
        $second = $generator->create_module('page', ['course' => $course->id]);

        $page1 = $this->call(0, 1, [], [(int) $course->id]);
        $page2 = $this->call($page1['next'], 1, [], [(int) $course->id]);

        $this->assertSame([(int) $first->cmid], $this->ids($page1));
        $this->assertSame([(int) $second->cmid], $this->ids($page2));
        $this->assertNull($page2['next']);
    }

    /**
     * A course with no modules returns none.
     */
    public function test_execute_by_course_without_modules(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();

        $this->assertSame(['coursemodules' => [], 'next' => null], $this->call(0, 10, [], [(int) $course->id]));
    }

    /**
     * ids and courseids together are refused.
     */
    public function test_execute_ids_with_filter(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(invalid_parameter_exception::class);
        get_course_modules::execute(0, 10, [1], [2]);
    }

    /**
     * More courseids than the maximum are refused with limit_exceeded.
     */
    public function test_execute_too_many_course_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::MAX_LIMIT));
        get_course_modules::execute(0, 10, [], range(1, source::MAX_LIMIT + 1));
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_course_modules::execute();
    }
}
