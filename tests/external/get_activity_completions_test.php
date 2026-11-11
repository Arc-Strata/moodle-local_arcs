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
require_once($CFG->libdir . '/completionlib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(get_activity_completions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\activity_completions::class)]
/**
 * Tests for local_arcs_get_activity_completions.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_activity_completions
 * @covers     \local_arcs\local\source\activity_completions
 */
final class get_activity_completions_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @param int[] $ids Ids to fetch.
     * @param int[] $courseids Courses whose completions to fetch.
     * @param int[] $cmids Course modules whose completions to fetch.
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
            get_activity_completions::execute_returns(),
            get_activity_completions::execute($after, $limit, $ids, $courseids, $cmids)
        );
    }

    /**
     * Creates a page with manual completion tracking in a course.
     *
     * @param \stdClass $course The course, with completion enabled.
     * @param array $options Extra module settings.
     * @return \cm_info
     */
    private function activity(\stdClass $course, array $options = []): \cm_info {
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL] + $options
        );
        return get_fast_modinfo($course->id)->get_cm($page->cmid);
    }

    /**
     * Enrols a new user and marks an activity complete for them, as the completion API does.
     *
     * @param \cm_info $cm The activity.
     * @return int The activity completion id.
     */
    private function complete(\cm_info $cm): int {
        global $DB;
        $user = $this->getDataGenerator()->create_and_enrol($cm->get_course());
        (new \completion_info($cm->get_course()))->update_state($cm, COMPLETION_COMPLETE, $user->id);
        return (int) $DB->get_field('course_modules_completion', 'id', ['coursemoduleid' => $cm->id, 'userid' => $user->id]);
    }

    /**
     * The ids of the activity completions in a result.
     *
     * @param array $result The cleaned result.
     * @return int[]
     */
    private function ids(array $result): array {
        return array_column($result['activitycompletions'], 'id');
    }

    /**
     * A site with no activity completions returns none.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->assertSame(['activitycompletions' => [], 'next' => null], $this->call());
    }

    /**
     * Every field is returned; viewed is true only when a view is recorded.
     */
    public function test_execute_returns_fields_and_viewed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $cm = $this->activity($course, ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1]);
        $viewer = $this->getDataGenerator()->create_and_enrol($course);
        $completion = new \completion_info($course);
        $completion->set_module_viewed($cm, $viewer->id);
        // Another activity, marked complete without a view.
        $other = $this->complete($this->activity($course));
        $viewed = $DB->get_record('course_modules_completion', ['coursemoduleid' => $cm->id, 'userid' => $viewer->id]);

        $rows = $this->call()['activitycompletions'];

        $this->assertSame([
            'id' => (int) $viewed->id,
            'coursemoduleid' => (int) $cm->id,
            'userid' => (int) $viewer->id,
            'completionstate' => COMPLETION_COMPLETE,
            'viewed' => true,
            'timemodified' => (int) $viewed->timemodified,
        ], $rows[0]);
        $this->assertSame($other, $rows[1]['id']);
        $this->assertFalse($rows[1]['viewed']);
    }

    /**
     * A reader can page with next; ids return exactly the existing completions.
     */
    public function test_execute_pages_and_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $cm = $this->activity($course);
        $one = $this->complete($cm);
        $two = $this->complete($cm);

        $page1 = $this->call(0, 1);
        $page2 = $this->call($page1['next'], 1);

        $this->assertSame([$one], $this->ids($page1));
        $this->assertSame([$two], $this->ids($page2));
        $this->assertNull($page2['next']);
        $this->assertSame([$two], $this->ids($this->call(0, 10, [$two, 999999])));
    }

    /**
     * With courseids, only those courses' completions are returned; with coursemoduleids, only those modules'.
     */
    public function test_execute_by_course_and_by_module(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $other = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $cm = $this->activity($course);
        $secondcm = $this->activity($course);
        $one = $this->complete($cm);
        $two = $this->complete($secondcm);
        $this->complete($this->activity($other));

        $this->assertSame([$one, $two], $this->ids($this->call(0, 10, [], [(int) $course->id])));
        $this->assertSame([$two], $this->ids($this->call(0, 10, [], [], [(int) $secondcm->id])));
        $this->assertSame([], $this->ids($this->call(0, 10, [], [999999])));
    }

    /**
     * courseids and coursemoduleids together are refused, as is either with ids.
     */
    public function test_execute_filters_not_combined(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        try {
            get_activity_completions::execute(0, 10, [1], [], [2]);
            $this->fail('ids with coursemoduleids should be refused');
        } catch (invalid_parameter_exception $e) {
            $this->assertInstanceOf(invalid_parameter_exception::class, $e);
        }
        $this->expectException(invalid_parameter_exception::class);
        get_activity_completions::execute(0, 10, [], [1], [2]);
    }

    /**
     * More coursemoduleids than the maximum are refused with limit_exceeded.
     */
    public function test_execute_too_many_course_module_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::MAX_LIMIT));
        get_activity_completions::execute(0, 10, [], [], range(1, source::MAX_LIMIT + 1));
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_activity_completions::execute();
    }
}
