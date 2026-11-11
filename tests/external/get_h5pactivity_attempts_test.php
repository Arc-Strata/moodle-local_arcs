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

#[\PHPUnit\Framework\Attributes\CoversClass(get_h5pactivity_attempts::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\h5pactivity_attempts::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\attempts::class)]
/**
 * Tests for local_arcs_get_h5pactivity_attempts.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_h5pactivity_attempts
 * @covers     \local_arcs\local\source\h5pactivity_attempts
 * @covers     \local_arcs\local\source\attempts
 */
final class get_h5pactivity_attempts_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @param int[] $ids Ids to fetch.
     * @param int[] $courseids Courses whose attempts to fetch.
     * @param int[] $cmids H5P activities, by course module, whose attempts to fetch.
     * @return array
     */
    private function call(
        int $after = 0,
        int $limit = source::DEFAULT_LIMIT,
        array $ids = [],
        array $courseids = [],
        array $cmids = []
    ): array {
        return external_api::clean_returnvalue(
            get_h5pactivity_attempts::execute_returns(),
            get_h5pactivity_attempts::execute($after, $limit, $ids, $courseids, $cmids)
        );
    }

    /**
     * Creates an H5P activity in a course.
     *
     * @param int $courseid The course.
     * @return \stdClass The activity, with cmid.
     */
    private function activity(int $courseid): \stdClass {
        return $this->getDataGenerator()->create_module('h5pactivity', ['course' => $courseid]);
    }

    /**
     * Creates an attempt for a new user, as the H5P activity generator does.
     *
     * @param \stdClass $activity The activity.
     * @param array $data Attempt fields to set (rawscore, maxscore, success and so on).
     * @return int The attempt id.
     */
    private function attempt(\stdClass $activity, array $data = []): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        /** @var \mod_h5pactivity_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_h5pactivity');
        $generator->create_attempt(['h5pactivityid' => $activity->id, 'userid' => $user->id] + $data);
        return (int) $DB->get_field('h5pactivity_attempts', 'id', ['h5pactivityid' => $activity->id, 'userid' => $user->id]);
    }

    /**
     * The ids of the attempts in a result.
     *
     * @param array $result The cleaned result.
     * @return int[]
     */
    private function ids(array $result): array {
        return array_column($result['h5pactivityattempts'], 'id');
    }

    /**
     * A site with no H5P activity attempts returns none.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->assertSame(['h5pactivityattempts' => [], 'next' => null], $this->call());
    }

    /**
     * Every field is returned; passed is the content's success, and null when it did not report one.
     */
    public function test_execute_returns_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $activity = $this->activity((int) $this->getDataGenerator()->create_course()->id);
        $passed = $this->attempt($activity, ['rawscore' => 3, 'maxscore' => 4, 'duration' => 25, 'success' => 1]);
        $unknown = $this->attempt($activity);
        $DB->set_field('h5pactivity_attempts', 'success', null, ['id' => $unknown]);
        $DB->set_field('h5pactivity_attempts', 'completion', null, ['id' => $unknown]);
        $record = $DB->get_record('h5pactivity_attempts', ['id' => $passed]);

        $rows = $this->call()['h5pactivityattempts'];

        $this->assertSame([
            'id' => $passed,
            'userid' => (int) $record->userid,
            'coursemoduleid' => (int) $activity->cmid,
            'attempt' => 1,
            'rawscore' => 3,
            'maxscore' => 4,
            'scaled' => 0.75,
            'duration' => 25,
            'completion' => true,
            'success' => true,
            'timecreated' => (int) $record->timecreated,
            'timemodified' => (int) $record->timemodified,
            'passed' => true,
        ], $rows[0]);
        $this->assertNull($rows[1]['completion']);
        $this->assertNull($rows[1]['success']);
        $this->assertNull($rows[1]['passed']);
    }

    /**
     * Attempts at H5P activities on the site course are left out.
     */
    public function test_execute_leaves_out_site_course(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $kept = $this->attempt($this->activity((int) $this->getDataGenerator()->create_course()->id));
        $this->attempt($this->activity(SITEID));

        $this->assertSame([$kept], $this->ids($this->call()));
    }

    /**
     * A reader can page with next; ids return exactly the existing attempts.
     */
    public function test_execute_pages_and_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $activity = $this->activity((int) $this->getDataGenerator()->create_course()->id);
        $one = $this->attempt($activity);
        $two = $this->attempt($activity);

        $page1 = $this->call(0, 1);
        $page2 = $this->call($page1['next'], 1);

        $this->assertSame([$one], $this->ids($page1));
        $this->assertSame([$two], $this->ids($page2));
        $this->assertNull($page2['next']);
        $this->assertSame([$two], $this->ids($this->call(0, 10, [$two, 999999])));
    }

    /**
     * With courseids, only those courses' attempts are returned; with coursemoduleids, only those activities'.
     */
    public function test_execute_by_course_and_by_module(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $first = $this->activity((int) $course->id);
        $second = $this->activity((int) $course->id);
        $one = $this->attempt($first);
        $two = $this->attempt($second);
        $this->attempt($this->activity((int) $this->getDataGenerator()->create_course()->id));

        $this->assertSame([$one, $two], $this->ids($this->call(0, 10, [], [(int) $course->id])));
        $this->assertSame([$two], $this->ids($this->call(0, 10, [], [], [(int) $second->cmid])));
        $this->assertSame([], $this->ids($this->call(0, 10, [], [999999])));
    }

    /**
     * courseids and coursemoduleids together are refused, as is either with ids.
     */
    public function test_execute_filters_not_combined(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        try {
            get_h5pactivity_attempts::execute(0, 10, [1], [], [2]);
            $this->fail('ids with coursemoduleids should be refused');
        } catch (invalid_parameter_exception $e) {
            $this->assertInstanceOf(invalid_parameter_exception::class, $e);
        }
        $this->expectException(invalid_parameter_exception::class);
        get_h5pactivity_attempts::execute(0, 10, [], [1], [2]);
    }

    /**
     * More courseids than the maximum are refused with limit_exceeded.
     */
    public function test_execute_too_many_course_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::MAX_LIMIT));
        get_h5pactivity_attempts::execute(0, 10, [], range(1, source::MAX_LIMIT + 1));
    }

    /**
     * A site where the H5P activity module has been uninstalled is refused with module_not_installed.
     */
    public function test_execute_module_not_installed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        // Uninstalling a module deletes its modules row (and its tables, which tests cannot drop).
        $DB->delete_records('modules', ['name' => 'h5pactivity']);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('module_not_installed', 'local_arcs', 'h5pactivity'));
        get_h5pactivity_attempts::execute();
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_h5pactivity_attempts::execute();
    }
}
