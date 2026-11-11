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
use core_external\external_api;
use local_arcs\local\source\source;
use local_arcs\tests\exporter_trait;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/completion/completion_completion.php');

#[\PHPUnit\Framework\Attributes\CoversClass(get_course_completions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\course_completions::class)]
/**
 * Tests for local_arcs_get_course_completions.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_course_completions
 * @covers     \local_arcs\local\source\course_completions
 */
final class get_course_completions_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @param int[] $ids Ids to fetch.
     * @param int[] $courseids Courses whose completions to fetch.
     * @return array
     */
    private function call(int $after = 0, int $limit = source::DEFAULT_LIMIT, array $ids = [], array $courseids = []): array {
        return external_api::clean_returnvalue(
            get_course_completions::execute_returns(),
            get_course_completions::execute($after, $limit, $ids, $courseids)
        );
    }

    /**
     * Enrols a new user in a course and records their course completion, as the completion API does.
     *
     * @param \stdClass $course The course.
     * @param int|null $timecompleted When they completed it; null to mark them enrolled only.
     * @return int The course completion id.
     */
    private function completion(\stdClass $course, ?int $timecompleted): int {
        $user = $this->getDataGenerator()->create_and_enrol($course);
        $completion = new \completion_completion(['course' => $course->id, 'userid' => $user->id]);
        if ($timecompleted === null) {
            $completion->mark_enrolled(1700000000);
        } else {
            $completion->mark_complete($timecompleted);
        }
        return (int) $completion->id;
    }

    /**
     * A site with no course completions returns none.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->assertSame(['coursecompletions' => [], 'next' => null], $this->call());
    }

    /**
     * Every field is returned; timecompleted is null until the course is complete.
     */
    public function test_execute_returns_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $enrolled = $this->completion($course, null);
        $completed = $this->completion($course, 1700000500);
        $records = $DB->get_records('course_completions', null, 'id');

        $rows = $this->call()['coursecompletions'];

        $this->assertSame([
            'id' => $enrolled,
            'userid' => (int) $records[$enrolled]->userid,
            'course' => (int) $course->id,
            'timeenrolled' => 1700000000,
            'timestarted' => 0,
            'timecompleted' => null,
            'reaggregate' => 0,
        ], $rows[0]);
        $this->assertSame($completed, $rows[1]['id']);
        $this->assertSame(1700000500, $rows[1]['timecompleted']);
    }

    /**
     * A reader can page with next; ids return exactly the existing completions.
     */
    public function test_execute_pages_and_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $one = $this->completion($course, 1700000000);
        $two = $this->completion($course, 1700000000);

        $page1 = $this->call(0, 1);
        $page2 = $this->call($page1['next'], 1);

        $this->assertSame([$one], array_column($page1['coursecompletions'], 'id'));
        $this->assertSame([$two], array_column($page2['coursecompletions'], 'id'));
        $this->assertNull($page2['next']);
        $this->assertSame([$two], array_column($this->call(0, 10, [$two, 999999])['coursecompletions'], 'id'));
    }

    /**
     * With courseids, only those courses' completions are returned; a course without any returns none.
     */
    public function test_execute_by_course(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $other = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $one = $this->completion($course, 1700000000);
        $this->completion($other, 1700000000);

        $this->assertSame([$one], array_column($this->call(0, 10, [], [(int) $course->id])['coursecompletions'], 'id'));
        $this->assertSame([], $this->call(0, 10, [], [999999])['coursecompletions']);
    }

    /**
     * ids and courseids together are refused.
     */
    public function test_execute_ids_with_filter(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(invalid_parameter_exception::class);
        get_course_completions::execute(0, 10, [1], [2]);
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_course_completions::execute();
    }
}
