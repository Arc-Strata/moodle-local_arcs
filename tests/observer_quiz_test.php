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

namespace local_arcs;

use local_arcs\tests\changes_trait;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/report/reportlib.php');
require_once($CFG->dirroot . '/mod/quiz/report/overview/report.php');
require_once($CFG->dirroot . '/mod/quiz/report/overview/overview_form.php');

#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
/**
 * Tests that core actions on quiz attempts and quiz grading record change log rows.
 *
 * Each test performs the real core action; none calls the observer directly.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\observer
 */
final class observer_quiz_test extends \advanced_testcase {
    use changes_trait;

    /** @var \stdClass A course. */
    private \stdClass $course;

    /** @var quiz_settings A quiz in the course, with two true/false questions. */
    private quiz_settings $quiz;

    /** @var \stdClass A user. */
    private \stdClass $user;

    /**
     * Resets the database the slow way, so the external observers run (see changes_trait), and
     * creates a course with a quiz, and a user.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->course = $this->getDataGenerator()->create_course();
        $this->quiz = $this->generator()->create_test_quiz(
            [['TF1', 1, 'truefalse'], ['TF2', 1, 'truefalse']],
            ['course' => $this->course->id]
        );
        $this->user = $this->getDataGenerator()->create_user();
    }

    /**
     * The quiz generator.
     *
     * @return \mod_quiz_generator
     */
    private function generator(): \mod_quiz_generator {
        return $this->getDataGenerator()->get_plugin_generator('mod_quiz');
    }

    /**
     * A quiz attempt change row for the user in the course.
     *
     * @param int $id The attempt id.
     * @param string $op upsert or delete.
     * @return array
     */
    private function attempt_change(int $id, string $op): array {
        return $this->change('quiz_attempt', $id, $op, (int) $this->user->id, (int) $this->course->id);
    }

    /**
     * The change log rows for one entity, oldest first. Grading an attempt also records grades and
     * completions, which other tests cover.
     *
     * @param string $entity The entity.
     * @return array[]
     */
    private function changes_of(string $entity): array {
        return array_values(array_filter($this->changes(), fn(array $change): bool => $change['entity'] === $entity));
    }

    /**
     * Starts the user's first attempt, as the user (the quiz generator starts attempts as the current user).
     *
     * @return int The attempt id.
     */
    private function start(): int {
        return (int) quiz_prepare_and_start_new_attempt($this->quiz, 1, null, false, [], [], $this->user->id)->id;
    }

    /**
     * Answers both questions in an attempt and submits it.
     *
     * @param int $attemptid The attempt id.
     */
    private function finish(int $attemptid): void {
        $this->generator()->submit_responses($attemptid, [1 => 'True', 2 => 'True'], false, true);
    }

    /**
     * Starting and submitting an attempt each record an upsert. On 5.3, submitting also grades it (attempt_graded).
     */
    public function test_attempt_started_and_submitted(): void {
        $this->clear_changes();
        $id = $this->start();
        $this->assertSame([$this->attempt_change($id, 'upsert')], $this->changes_of('quiz_attempt'));

        $this->clear_changes();
        $this->finish($id);
        $changes = $this->changes_of('quiz_attempt');
        $this->assertNotEmpty($changes);
        $this->assertSame([$this->attempt_change($id, 'upsert')], array_values(array_unique($changes, SORT_REGULAR)));
    }

    /**
     * An attempt becoming overdue, being abandoned and being reopened each record an upsert.
     */
    public function test_attempt_overdue_abandoned_and_reopened(): void {
        $id = $this->start();

        $this->clear_changes();
        $attempt = quiz_attempt::create($id);
        $attempt->process_going_overdue(time(), false);
        $attempt = quiz_attempt::create($id);
        $attempt->process_abandon(time(), false);
        $attempt = quiz_attempt::create($id);
        $attempt->process_reopen_abandoned(time());

        $this->assertSame(array_fill(0, 3, $this->attempt_change($id, 'upsert')), $this->changes_of('quiz_attempt'));
    }

    /**
     * Regrading an attempt from the quiz's results report records an upsert.
     */
    public function test_attempt_regraded(): void {
        global $DB;
        $id = $this->start();
        $this->finish($id);
        $attempt = $DB->get_record('quiz_attempts', ['id' => $id]);
        $report = new \quiz_overview_report();
        $report->init(
            'overview',
            'quiz_overview_settings_form',
            $this->quiz->get_quiz(),
            $this->quiz->get_cm(),
            $this->course
        );

        $this->clear_changes();
        $report->regrade_attempt($attempt);

        $this->assertSame([$this->attempt_change($id, 'upsert')], $this->changes_of('quiz_attempt'));
    }

    /**
     * Deleting an attempt records a delete.
     */
    public function test_attempt_deleted(): void {
        global $DB;
        $id = $this->start();

        $this->clear_changes();
        quiz_delete_attempt($DB->get_record('quiz_attempts', ['id' => $id]), $this->quiz->get_quiz());

        $this->assertSame([$this->attempt_change($id, 'delete')], $this->changes_of('quiz_attempt'));
    }

    /**
     * A teacher's preview records nothing, from start to submission.
     */
    public function test_preview_ignored(): void {
        $this->setAdminUser();
        $quizobj = quiz_settings::create($this->quiz->get_quizid());

        $this->clear_changes();
        $attempt = quiz_prepare_and_start_new_attempt($quizobj, 1, null);
        $this->assertEquals(1, $attempt->preview);
        $this->finish((int) $attempt->id);

        $this->assertSame([], $this->changes_of('quiz_attempt'));
    }

    /**
     * Changing a question's mark, and the quiz's maximum grade, record a quiz_grading upsert for the quiz.
     */
    public function test_quiz_grading_changed(): void {
        $this->finish($this->start());
        $grading = $this->change('quiz_grading', (int) $this->quiz->get_cmid(), 'upsert', null, (int) $this->course->id);
        $structure = $this->quiz->get_structure();

        $this->clear_changes();
        $structure->update_slot_maxmark($structure->get_slot_by_number(1), 3);
        $calculator = $this->quiz->get_grade_calculator();
        $calculator->recompute_quiz_sumgrades();
        $calculator->recompute_all_attempt_sumgrades();
        $this->assertSame([$grading], $this->changes_of('quiz_grading'));

        $this->clear_changes();
        $calculator->update_quiz_maximum_grade(50);
        $this->assertSame([$grading], $this->changes_of('quiz_grading'));
    }

    /**
     * Attempts at a quiz on the site course record nothing.
     */
    public function test_site_course_ignored(): void {
        $quiz = $this->generator()->create_test_quiz([['TF1', 1, 'truefalse']], ['course' => SITEID]);

        $this->clear_changes();
        $attempt = quiz_prepare_and_start_new_attempt($quiz, 1, null, false, [], [], $this->user->id);
        $this->generator()->submit_responses($attempt->id, [1 => 'True'], false, true);

        $this->assertSame([], $this->changes_of('quiz_attempt'));
    }
}
