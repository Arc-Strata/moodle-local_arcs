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

#[\PHPUnit\Framework\Attributes\CoversClass(get_user_enrolments::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\user_enrolments::class)]
/**
 * Tests for local_arcs_get_user_enrolments.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_user_enrolments
 * @covers     \local_arcs\local\source\user_enrolments
 */
final class get_user_enrolments_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @param int[] $ids Ids to fetch.
     * @param int[] $courseids Courses whose enrolments to fetch.
     * @param int[] $enrolids Enrolment method instances whose enrolments to fetch.
     * @return array
     */
    private function call(
        int $after = 0,
        int $limit = source::DEFAULT_LIMIT,
        array $ids = [],
        array $courseids = [],
        array $enrolids = []
    ): array {
        return external_api::clean_returnvalue(
            get_user_enrolments::execute_returns(),
            get_user_enrolments::execute($after, $limit, $ids, $courseids, $enrolids)
        );
    }

    /**
     * The ids of the user enrolments in a result.
     *
     * @param array $result The cleaned result.
     * @return int[]
     */
    private function ids(array $result): array {
        return array_column($result['userenrolments'], 'id');
    }

    /**
     * The id of a user's enrolment in a course.
     *
     * @param int $userid The user.
     * @param int $courseid The course.
     * @return int
     */
    private function enrolment_id(int $userid, int $courseid): int {
        global $DB;
        return (int) $DB->get_field_sql(
            'SELECT ue.id FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid WHERE ue.userid = ? AND e.courseid = ?',
            [$userid, $courseid]
        );
    }

    /**
     * A site with no enrolments returns none.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->assertSame(['userenrolments' => [], 'next' => null], $this->call());
    }

    /**
     * Every field is returned, with the enrolment method and the user's roles in the course, in role order.
     */
    public function test_execute_returns_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student', 'manual', 1700000000, 1710000000, ENROL_USER_SUSPENDED);
        $generator->role_assign('teacher', $user->id, \core\context\course::instance($course->id)->id);
        // A role elsewhere is not a role in the course.
        $generator->role_assign('manager', $user->id, \core\context\coursecat::instance($course->category)->id);
        $ue = $DB->get_record('user_enrolments', ['id' => $this->enrolment_id($user->id, $course->id)]);
        $roles = $DB->get_records_menu('role', null, '', 'shortname, id');

        $this->assertSame([[
            'id' => (int) $ue->id,
            'enrolid' => (int) $ue->enrolid,
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'enrol' => 'manual',
            'enrolstatus' => ENROL_INSTANCE_ENABLED,
            'status' => ENROL_USER_SUSPENDED,
            'timestart' => 1700000000,
            'timeend' => 1710000000,
            'timecreated' => (int) $ue->timecreated,
            'timemodified' => (int) $ue->timemodified,
            'roles' => [
                ['roleid' => (int) $roles['teacher'], 'shortname' => 'teacher', 'archetype' => 'teacher'],
                ['roleid' => (int) $roles['student'], 'shortname' => 'student', 'archetype' => 'student'],
            ],
        ]], $this->call()['userenrolments']);
    }

    /**
     * A role assigned twice in the course (manually and by an enrolment method) is listed once.
     */
    public function test_execute_lists_each_role_once(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $studentid = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        role_assign($studentid, $user->id, \core\context\course::instance($course->id)->id, 'enrol_self', 1);

        $this->assertSame(
            [['roleid' => $studentid, 'shortname' => 'student', 'archetype' => 'student']],
            $this->call()['userenrolments'][0]['roles']
        );
    }

    /**
     * A custom role carries the archetype it was made from, and an empty string when it has none.
     */
    public function test_execute_returns_role_archetypes(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $learnerid = create_role('Apprentice', 'apprentice', '', 'student');
        $observerid = create_role('Observer', 'observer', '');
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $learnerid);
        role_assign($observerid, $user->id, \core\context\course::instance($course->id)->id);

        $this->assertSame([
            ['roleid' => $learnerid, 'shortname' => 'apprentice', 'archetype' => 'student'],
            ['roleid' => $observerid, 'shortname' => 'observer', 'archetype' => ''],
        ], $this->call()['userenrolments'][0]['roles']);
    }

    /**
     * Roles are read once per page: the reads do not grow with the page.
     */
    public function test_roles_read_once_per_page(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        for ($i = 0; $i < 10; $i++) {
            $this->getDataGenerator()->enrol_user($this->getDataGenerator()->create_user()->id, $course->id, 'student');
        }
        $source = new \local_arcs\local\source\user_enrolments($DB);

        $before = $DB->perf_get_reads();
        $small = $source->page(0, 2);
        $smallreads = $DB->perf_get_reads() - $before;
        $before = $DB->perf_get_reads();
        $large = $source->page(0, 10);
        $largereads = $DB->perf_get_reads() - $before;

        $this->assertCount(2, $small['rows']);
        $this->assertCount(10, $large['rows']);
        $this->assertSame('student', $large['rows'][9]['roles'][0]['shortname']);
        $this->assertSame($smallreads, $largereads);
    }

    /**
     * A reader can page through enrolments with next; with ids, exactly the existing ones are returned.
     */
    public function test_execute_pages_and_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $one = $this->getDataGenerator()->create_and_enrol($course);
        $two = $this->getDataGenerator()->create_and_enrol($course);
        $first = $this->enrolment_id($one->id, $course->id);
        $second = $this->enrolment_id($two->id, $course->id);

        $page1 = $this->call(0, 1);
        $page2 = $this->call($page1['next'], 1);

        $this->assertSame([$first], $this->ids($page1));
        $this->assertSame([$second], $this->ids($page2));
        $this->assertNull($page2['next']);
        $this->assertSame([$second], $this->ids($this->call(0, 10, [$second, 999999])));
    }

    /**
     * With courseids, only those courses' enrolments are returned, a page at a time; a course without any returns none.
     */
    public function test_execute_by_course(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $other = $generator->create_course();
        $empty = $generator->create_course();
        $one = $generator->create_and_enrol($course);
        $generator->create_and_enrol($other);
        $two = $generator->create_and_enrol($course);

        $page1 = $this->call(0, 1, [], [(int) $course->id]);
        $page2 = $this->call($page1['next'], 1, [], [(int) $course->id]);

        $this->assertSame([$this->enrolment_id($one->id, $course->id)], $this->ids($page1));
        $this->assertSame([$this->enrolment_id($two->id, $course->id)], $this->ids($page2));
        $this->assertNull($page2['next']);
        $this->assertSame([], $this->call(0, 10, [], [(int) $empty->id])['userenrolments']);
    }

    /**
     * With enrolids, only enrolments through those enrolment method instances are returned, paged by cursor.
     */
    public function test_execute_by_enrolment_method(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $one = $generator->create_and_enrol($course, 'student', null, 'manual');
        $generator->create_and_enrol($course, 'student', null, 'self');
        $two = $generator->create_and_enrol($course, 'student', null, 'manual');
        $manual = (int) $DB->get_field('enrol', 'id', ['courseid' => $course->id, 'enrol' => 'manual']);

        $page1 = $this->call(0, 1, [], [], [$manual]);
        $page2 = $this->call($page1['next'], 1, [], [], [$manual]);

        $this->assertSame([$this->enrolment_id($one->id, $course->id)], $this->ids($page1));
        $this->assertSame([$this->enrolment_id($two->id, $course->id)], $this->ids($page2));
        $this->assertNull($page2['next']);
        $this->assertSame([], $this->call(0, 10, [], [], [-1])['userenrolments']);
    }

    /**
     * courseids and enrolids together are refused, as is enrolids with ids.
     */
    public function test_execute_course_and_enrolment_method_filters(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        try {
            get_user_enrolments::execute(0, 10, [], [1], [2]);
            $this->fail('courseids with enrolids should be refused');
        } catch (invalid_parameter_exception $e) {
            $this->assertStringContainsString('cannot be combined', $e->getMessage());
        }
        $this->expectException(invalid_parameter_exception::class);
        get_user_enrolments::execute(0, 10, [1], [], [2]);
    }

    /**
     * More enrolids than the maximum are refused with limit_exceeded.
     */
    public function test_execute_too_many_enrolment_method_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::MAX_LIMIT));
        get_user_enrolments::execute(0, 10, [], [], range(1, source::MAX_LIMIT + 1));
    }

    /**
     * ids and courseids together are refused.
     */
    public function test_execute_ids_with_filter(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(invalid_parameter_exception::class);
        get_user_enrolments::execute(0, 10, [1], [2]);
    }

    /**
     * More courseids than the maximum are refused with limit_exceeded.
     */
    public function test_execute_too_many_course_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::MAX_LIMIT));
        get_user_enrolments::execute(0, 10, [], range(1, source::MAX_LIMIT + 1));
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_user_enrolments::execute();
    }
}
