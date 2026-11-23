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
use mod_quiz\quiz_attempt;
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
 * @copyright  2026 Arcs Learning
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
     * @param bool $includedetail Whether to fetch each attempt's slots.
     * @return array
     */
    private function call(
        int $after = 0,
        int $limit = source::DEFAULT_LIMIT,
        array $ids = [],
        array $courseids = [],
        array $cmids = [],
        bool $includedetail = false
    ): array {
        return external_api::clean_returnvalue(
            get_quiz_attempts::execute_returns(),
            get_quiz_attempts::execute($after, $limit, $ids, $courseids, $cmids, $includedetail)
        );
    }

    /**
     * Calls the function with detail, and returns the attempts.
     *
     * @param int[] $ids Ids to fetch; empty for all.
     * @return array[]
     */
    private function call_with_detail(array $ids = []): array {
        return $this->call(0, source::DEFAULT_LIMIT, $ids, [], [], true)['quizattempts'];
    }

    /**
     * The question in a quiz's slot.
     *
     * @param quiz_settings $quiz The quiz.
     * @param int $slot The slot.
     * @return int The question id.
     */
    private function questionid(quiz_settings $quiz, int $slot): int {
        return (int) $quiz->get_structure()->get_question_in_slot($slot)->questionid;
    }

    /**
     * An expected slot of a true/false question marked out of 1.
     *
     * @param int $slot The slot.
     * @param int $questionid The question.
     * @param string $questionname The question's name.
     * @param int $sectionfirstslot The first slot of its section.
     * @param string $sectionheading Its section's heading.
     * @param float|null $fraction The fraction awarded.
     * @param string $state The question's state.
     * @return array
     */
    private function slot(
        int $slot,
        int $questionid,
        string $questionname,
        int $sectionfirstslot,
        string $sectionheading,
        ?float $fraction,
        string $state
    ): array {
        return [
            'slot' => $slot,
            'questionid' => $questionid,
            'questionname' => $questionname,
            'sectionfirstslot' => $sectionfirstslot,
            'sectionheading' => $sectionheading,
            'maxmark' => 1.0,
            'minfraction' => 0.0,
            'maxfraction' => 1.0,
            'fraction' => $fraction,
            'state' => $state,
        ];
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
     * Without includedetail, attempts carry no slots.
     */
    public function test_execute_without_detail_has_no_slots(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $this->attempt($this->quiz($this->getDataGenerator()->create_course()));

        $this->assertArrayNotHasKey('slots', $this->call()['quizattempts'][0]);
    }

    /**
     * With includedetail, each attempt carries its slots in order: the question in each, the section
     * it is in, and its mark and state from the latest step. An attempt in progress has no marks yet.
     */
    public function test_execute_returns_slots(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        /** @var \mod_quiz_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quiz = $generator->create_test_quiz(
            ['Part A', ['TF1', 1, 'truefalse'], ['TF2', 1, 'truefalse'], 'Part B', ['TF3', 2, 'truefalse']],
            ['course' => $this->getDataGenerator()->create_course()->id]
        );
        $user = $this->getDataGenerator()->create_user();
        $finished = quiz_prepare_and_start_new_attempt($quiz, 1, null, false, [], [], $user->id);
        $generator->submit_responses($finished->id, [1 => 'True', 2 => 'False', 3 => 'True'], false, true);
        $this->attempt($quiz, false);
        [$q1, $q2, $q3] = [$this->questionid($quiz, 1), $this->questionid($quiz, 2), $this->questionid($quiz, 3)];

        $rows = $this->call_with_detail();

        $this->assertSame([
            $this->slot(1, $q1, 'TF1', 1, 'Part A', 1.0, 'gradedright'),
            $this->slot(2, $q2, 'TF2', 1, 'Part A', 0.0, 'gradedwrong'),
            $this->slot(3, $q3, 'TF3', 3, 'Part B', 1.0, 'gradedright'),
        ], $rows[0]['slots']);
        $this->assertSame([
            $this->slot(1, $q1, 'TF1', 1, 'Part A', null, 'todo'),
            $this->slot(2, $q2, 'TF2', 1, 'Part A', null, 'todo'),
            $this->slot(3, $q3, 'TF3', 3, 'Part B', null, 'todo'),
        ], $rows[1]['slots']);
    }

    /**
     * An essay has no mark until it is graded by hand; then its slot has the mark and a manual state.
     * The section of a quiz without headings has an empty heading.
     */
    public function test_execute_slot_of_manually_graded_essay(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        /** @var \mod_quiz_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quiz = $generator->create_test_quiz(
            [['Essay', 1, 'essay']],
            ['course' => $this->getDataGenerator()->create_course()->id, 'preferredbehaviour' => 'deferredfeedback']
        );
        $user = $this->getDataGenerator()->create_user();
        $id = (int) quiz_prepare_and_start_new_attempt($quiz, 1, null, false, [], [], $user->id)->id;
        $generator->submit_responses($id, [1 => 'An answer'], false, true);
        $essay = $this->slot(1, $this->questionid($quiz, 1), 'Essay', 1, '', null, 'needsgrading');

        $this->assertSame([$essay], $this->call_with_detail()[0]['slots']);

        $attempt = quiz_attempt::create($id);
        $attempt->get_question_usage()->manual_grade(1, 'Good', 1, FORMAT_HTML);
        \question_engine::save_questions_usage_by_activity($attempt->get_question_usage());

        $this->assertSame(
            [array_merge($essay, ['fraction' => 1.0, 'state' => 'mangrright'])],
            $this->call_with_detail()[0]['slots']
        );
    }

    /**
     * A question's new mark is in existing attempts' slots on refetch.
     */
    public function test_execute_slot_mark_change(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $quiz = $this->quiz($this->getDataGenerator()->create_course());
        $this->attempt($quiz);
        $structure = $quiz->get_structure();

        $structure->update_slot_maxmark($structure->get_slot_by_number(1), 3);

        $this->assertSame([3.0, 1.0], array_column($this->call_with_detail()[0]['slots'], 'maxmark'));
    }

    /**
     * With includedetail, each page and each ids fetch carries its own attempts' slots.
     */
    public function test_execute_pages_and_ids_with_detail(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $quiz = $this->quiz($this->getDataGenerator()->create_course());
        $one = $this->attempt($quiz);
        $two = $this->attempt($quiz, false);

        $page1 = $this->call(0, 1, [], [], [], true);
        $page2 = $this->call($page1['next'], 1, [], [], [], true);

        $this->assertSame([$one], $this->ids($page1));
        $this->assertSame(['gradedright', 'gradedwrong'], array_column($page1['quizattempts'][0]['slots'], 'state'));
        $this->assertSame([$two], $this->ids($page2));
        $this->assertSame(['todo', 'todo'], array_column($page2['quizattempts'][0]['slots'], 'state'));
        $this->assertSame(['todo', 'todo'], array_column($this->call_with_detail([$two])[0]['slots'], 'state'));
    }

    /**
     * With includedetail, a page holds at most DETAIL_MAX_LIMIT attempts whatever limit says, and
     * more ids than that are refused.
     */
    public function test_execute_detail_page_size(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $quiz = $this->quiz($this->getDataGenerator()->create_course());
        // Attempt rows without questions are enough to count a page, and far quicker than real attempts.
        $ids = [];
        for ($n = 1; $n <= source::DETAIL_MAX_LIMIT + 1; $n++) {
            $ids[] = (int) $DB->insert_record('quiz_attempts', [
                'quiz' => $quiz->get_quizid(), 'userid' => $n + 100, 'attempt' => 1, 'uniqueid' => -$n,
                'layout' => '', 'state' => 'inprogress', 'timestart' => 1, 'timefinish' => 0, 'timemodified' => 1,
            ]);
        }

        $page = $this->call(0, source::MAX_LIMIT, [], [], [], true);

        $this->assertCount(source::DETAIL_MAX_LIMIT, $page['quizattempts']);
        $this->assertSame($ids[source::DETAIL_MAX_LIMIT - 1], $page['next']);
        $this->assertCount(source::DETAIL_MAX_LIMIT + 1, $this->call(0, source::MAX_LIMIT)['quizattempts']);
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::DETAIL_MAX_LIMIT));
        $this->call(0, source::MAX_LIMIT, $ids, [], [], true);
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
