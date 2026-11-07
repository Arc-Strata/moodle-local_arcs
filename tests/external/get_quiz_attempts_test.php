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
use mod_quiz\quiz_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(get_quiz_attempts::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\quiz_attempts::class)]
/**
 * Tests for local_arcs_get_quiz_attempts.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_quiz_attempts
 * @covers     \local_arcs\local\source\quiz_attempts
 */
final class get_quiz_attempts_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @param int[] $ids Ids to fetch.
     * @param int[] $courseids Courses whose attempts to fetch.
     * @param int[] $cmids Quizzes, by course module, whose attempts to fetch.
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
            get_quiz_attempts::execute_returns(),
            get_quiz_attempts::execute($after, $limit, $ids, $courseids, $cmids)
        );
    }

    /**
     * Creates a quiz with two true/false questions, one mark each, graded out of 10.
     *
     * @param \stdClass $course The course.
     * @return quiz_settings
     */
    private function quiz(\stdClass $course): quiz_settings {
        /** @var \mod_quiz_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        return $generator->create_test_quiz(
            [['TF1', 1, 'truefalse'], ['TF2', 1, 'truefalse']],
            ['course' => $course->id, 'grade' => 10]
        );
    }

    /**
     * Starts an attempt at a quiz for a new user, and finishes it with one question right when asked.
     *
     * @param quiz_settings $quiz The quiz.
     * @param bool $finish Whether to answer and finish the attempt.
     * @return int The attempt id.
     */
    private function attempt(quiz_settings $quiz, bool $finish = true): int {
        /** @var \mod_quiz_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $user = $this->getDataGenerator()->create_user();
        $attempt = quiz_prepare_and_start_new_attempt($quiz, 1, null, false, [], [], $user->id);
        if ($finish) {
            $generator->submit_responses($attempt->id, [1 => 'True', 2 => 'False'], false, true);
        }
        return (int) $attempt->id;
    }

    /**
     * The ids of the quiz attempts in a result.
     *
     * @param array $result The cleaned result.
     * @return int[]
     */
    private function ids(array $result): array {
        return array_column($result['quizattempts'], 'id');
    }

    /**
     * A site with no quiz attempts returns none.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->assertSame(['quizattempts' => [], 'next' => null], $this->call());
    }

    /**
     * Every field is returned: a finished attempt has its marks and grade, an attempt in progress has neither.
     */
    public function test_execute_returns_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $quiz = $this->quiz($this->getDataGenerator()->create_course());
        $finished = $this->attempt($quiz);
        $inprogress = $this->attempt($quiz, false);
        $record = $DB->get_record('quiz_attempts', ['id' => $finished]);

        $rows = $this->call()['quizattempts'];

        $this->assertSame([
            'id' => $finished,
            'userid' => (int) $record->userid,
            'coursemoduleid' => (int) $quiz->get_cmid(),
            'attempt' => 1,
            'state' => 'finished',
            'timestart' => (int) $record->timestart,
            'timefinish' => (int) $record->timefinish,
            'timemodified' => (int) $record->timemodified,
            'sumgrades' => 1.0,
            'maxsumgrades' => 2.0,
            'grade' => 5.0,
            'grademax' => 10.0,
            'passed' => null,
        ], $rows[0]);
        $this->assertSame($inprogress, $rows[1]['id']);
        $this->assertSame('inprogress', $rows[1]['state']);
        $this->assertSame(0, $rows[1]['timefinish']);
        $this->assertNull($rows[1]['sumgrades']);
        $this->assertNull($rows[1]['grade']);
    }

    /**
     * A quiz with no marks has no range to grade on, so its attempts' grade is null.
     */
    public function test_execute_grade_null_without_marks(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $quiz = $this->quiz($this->getDataGenerator()->create_course());
        $this->attempt($quiz);
        $DB->set_field('quiz', 'sumgrades', 0, ['id' => $quiz->get_quizid()]);

        $row = $this->call()['quizattempts'][0];

        $this->assertSame(0.0, $row['maxsumgrades']);
        $this->assertNull($row['grade']);
    }

    /**
     * Previews, attempts nobody has started, and attempts at quizzes on the site course are left out.
     */
    public function test_execute_leaves_out_previews_unstarted_and_site_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $quiz = $this->quiz($this->getDataGenerator()->create_course());
        $kept = $this->attempt($quiz);
        $DB->set_field('quiz_attempts', 'preview', 1, ['id' => $this->attempt($quiz)]);
        // Moodle 5.3 creates attempts in advance in this state; on 4.5 it is only a value here.
        $DB->set_field('quiz_attempts', 'state', 'notstarted', ['id' => $this->attempt($quiz, false)]);
        $this->attempt($this->quiz(get_site()));

        $this->assertSame([$kept], $this->ids($this->call()));
    }

    /**
     * A reader can page with next; ids return exactly the existing attempts.
     */
    public function test_execute_pages_and_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $quiz = $this->quiz($this->getDataGenerator()->create_course());
        $one = $this->attempt($quiz);
        $two = $this->attempt($quiz);

        $page1 = $this->call(0, 1);
        $page2 = $this->call($page1['next'], 1);

        $this->assertSame([$one], $this->ids($page1));
        $this->assertSame([$two], $this->ids($page2));
        $this->assertNull($page2['next']);
        $this->assertSame([$two], $this->ids($this->call(0, 10, [$two, 999999])));
    }

    /**
     * With courseids, only those courses' attempts are returned; with coursemoduleids, only those quizzes'.
     */
    public function test_execute_by_course_and_by_module(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->quiz($course);
        $second = $this->quiz($course);
        $one = $this->attempt($quiz);
        $two = $this->attempt($second);
        $this->attempt($this->quiz($this->getDataGenerator()->create_course()));

        $this->assertSame([$one, $two], $this->ids($this->call(0, 10, [], [(int) $course->id])));
        $this->assertSame([$two], $this->ids($this->call(0, 10, [], [], [(int) $second->get_cmid()])));
        $this->assertSame([], $this->ids($this->call(0, 10, [], [999999])));
    }

    /**
     * courseids and coursemoduleids together are refused, as is either with ids.
     */
    public function test_execute_filters_not_combined(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        try {
            get_quiz_attempts::execute(0, 10, [1], [], [2]);
            $this->fail('ids with coursemoduleids should be refused');
        } catch (invalid_parameter_exception $e) {
            $this->assertInstanceOf(invalid_parameter_exception::class, $e);
        }
        $this->expectException(invalid_parameter_exception::class);
        get_quiz_attempts::execute(0, 10, [], [1], [2]);
    }

    /**
     * More coursemoduleids than the maximum are refused with limit_exceeded.
     */
    public function test_execute_too_many_course_module_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::MAX_LIMIT));
        get_quiz_attempts::execute(0, 10, [], [], range(1, source::MAX_LIMIT + 1));
    }

    /**
     * A site where the quiz module has been uninstalled is refused with module_not_installed.
     */
    public function test_execute_module_not_installed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        // Uninstalling a module deletes its modules row (and its tables, which tests cannot drop).
        $DB->delete_records('modules', ['name' => 'quiz']);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('module_not_installed', 'local_arcs', 'quiz'));
        get_quiz_attempts::execute();
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_quiz_attempts::execute();
    }
}
