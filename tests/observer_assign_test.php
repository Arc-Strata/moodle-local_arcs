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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
/**
 * Tests that assignment submissions, grading and settings record change log rows.
 *
 * Each test acts through mod_assign's own API, as its submission and grading pages do; none
 * calls the observer directly.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\observer
 */
final class observer_assign_test extends \advanced_testcase {
    use changes_trait;

    /** @var \stdClass A course. */
    private \stdClass $course;

    /** @var \stdClass A student in the course. */
    private \stdClass $student;

    /**
     * Resets the database the slow way, so the external observers run (see changes_trait), and
     * creates a course with a student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Creates an assignment with online text submissions, as an administrator.
     *
     * @param array $settings Settings other than the defaults (drafts off, so saving submits).
     * @param \stdClass|null $course The course; the test's course by default.
     * @return \assign
     */
    private function assign(array $settings = [], ?\stdClass $course = null): \assign {
        $this->setAdminUser();
        $course = $course ?? $this->course;
        $instance = $this->getDataGenerator()->create_module('assign', $settings + [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'submissiondrafts' => 0,
        ]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        return new \assign(\core\context\module::instance($cm->id), $cm, $course);
    }

    /**
     * Saves a submission as the student, as the submission page does.
     *
     * @param \assign $assign The assignment.
     * @param \stdClass|null $student The student; the test's student by default.
     */
    private function save(\assign $assign, ?\stdClass $student = null): void {
        $student = $student ?? $this->student;
        $this->setUser($student);
        $data = (object) [
            'userid' => $student->id,
            'onlinetext_editor' => ['itemid' => file_get_unused_draft_itemid(), 'text' => 'Text', 'format' => FORMAT_HTML],
        ];
        $sink = $this->redirectMessages();
        $notices = [];
        $this->assertTrue($assign->save_submission($data, $notices));
        $sink->close();
    }

    /**
     * Grades the student's latest attempt as an administrator, as the grading form does.
     *
     * @param \assign $assign The assignment.
     * @param array $data Form data other than the grade, such as workflowstate or addattempt.
     * @param \stdClass|null $student The student; the test's student by default.
     */
    private function grade(\assign $assign, array $data = [], ?\stdClass $student = null): void {
        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $assign->save_grade(($student ?? $this->student)->id, (object) ($data + ['grade' => 50, 'attemptnumber' => -1]));
        $sink->close();
    }

    /**
     * The student's submission for an attempt.
     *
     * @param \assign $assign The assignment.
     * @param int $attemptnumber Moodle's attempt number, from 0.
     * @param \stdClass|null $student The student; the test's student by default.
     * @return int
     */
    private function submission_id(\assign $assign, int $attemptnumber = 0, ?\stdClass $student = null): int {
        return (int) $assign->get_user_submission(($student ?? $this->student)->id, false, $attemptnumber)->id;
    }

    /**
     * An assign_submission upsert for a submission of the student's.
     *
     * @param int $id The submission.
     * @param \stdClass|null $student The student; the test's student by default.
     * @return array
     */
    private function upsert(int $id, ?\stdClass $student = null): array {
        return $this->change('assign_submission', $id, 'upsert', (int) ($student ?? $this->student)->id, (int) $this->course->id);
    }

    /**
     * An assign_grading upsert for an assignment.
     *
     * @param \assign $assign The assignment.
     * @return array
     */
    private function grading(\assign $assign): array {
        return $this->change('assign_grading', (int) $assign->get_course_module()->id, 'upsert', null, (int) $this->course->id);
    }

    /**
     * The assignment change log rows, oldest first, without duplicates. Grading also records grades,
     * and settings course modules, which other tests cover.
     *
     * @return array[]
     */
    private function assign_changes(): array {
        $changes = array_filter(
            $this->changes(),
            fn(array $change): bool => in_array($change['entity'], ['assign_submission', 'assign_grading'])
        );
        return array_values(array_unique($changes, SORT_REGULAR));
    }

    /**
     * Saving a draft, submitting it, reverting it to draft and removing it each record the submission.
     */
    public function test_submission_lifecycle(): void {
        $assign = $this->assign(['submissiondrafts' => 1]);

        $this->clear_changes();
        $this->save($assign);
        $upsert = $this->upsert($this->submission_id($assign));
        $this->assertSame([$upsert], $this->assign_changes());

        $this->clear_changes();
        $sink = $this->redirectMessages();
        $assign->submit_for_grading((object) ['userid' => $this->student->id], []);
        $sink->close();
        $this->assertSame([$upsert], $this->assign_changes());

        $this->setAdminUser();
        $this->clear_changes();
        $assign->revert_to_draft($this->student->id);
        $this->assertSame([$upsert], $this->assign_changes());

        $this->clear_changes();
        $assign->remove_submission($this->student->id);
        $this->assertSame([$upsert], $this->assign_changes());
    }

    /**
     * Grading records the graded attempt's submission, including a student who has not submitted,
     * for whom grading creates one.
     */
    public function test_graded(): void {
        $assign = $this->assign();
        $this->save($assign);
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $this->clear_changes();
        $this->grade($assign);
        $this->grade($assign, [], $other);

        $this->assertSame([
            $this->upsert($this->submission_id($assign)),
            $this->upsert($this->submission_id($assign, 0, $other), $other),
        ], $this->assign_changes());
    }

    /**
     * Allowing another attempt records the graded attempt only: Moodle adds the new attempt without
     * an event (the contract lists this). The learner's first save in it records it, as does
     * copying the previous attempt.
     */
    public function test_new_attempt(): void {
        $assign = $this->assign(['attemptreopenmethod' => 'manual', 'maxattempts' => -1]);
        $this->save($assign);

        $this->clear_changes();
        $this->grade($assign, ['addattempt' => 1]);
        $this->assertSame([$this->upsert($this->submission_id($assign))], $this->assign_changes());

        $this->clear_changes();
        $this->setUser($this->student);
        $notices = [];
        $sink = $this->redirectMessages();
        $this->assertTrue($assign->copy_previous_attempt($notices));
        $sink->close();
        $this->assertSame([$this->upsert($this->submission_id($assign, 1))], $this->assign_changes());
    }

    /**
     * A marking workflow state change records each of the student's submissions, since it decides
     * whether every attempt's grade is released.
     */
    public function test_workflow_state_updated(): void {
        $assign = $this->assign(['markingworkflow' => 1, 'attemptreopenmethod' => 'manual', 'maxattempts' => -1]);
        $this->save($assign);
        $this->grade($assign, ['addattempt' => 1, 'workflowstate' => 'inmarking']);

        $this->clear_changes();
        $this->grade($assign, ['workflowstate' => 'released', 'attemptnumber' => 0]);

        $this->assertSame([
            $this->upsert($this->submission_id($assign)),
            $this->upsert($this->submission_id($assign, 1)),
        ], $this->assign_changes());
    }

    /**
     * Under blind marking, grading records nothing (Moodle fires no event while it withholds the
     * grade, and so does the plugin); revealing identities records an assign_grading upsert.
     */
    public function test_identities_revealed(): void {
        $assign = $this->assign(['blindmarking' => 1]);
        $this->save($assign);

        $this->clear_changes();
        $this->grade($assign);
        $this->assertSame([], $this->assign_changes());

        $assign->reveal_identities();
        $this->assertSame([$this->grading($assign)], $this->assign_changes());
    }

    /**
     * In a team submission, saving records an assign_grading upsert, since Moodle names only the
     * group's row; grading each member records each member's own submission.
     */
    public function test_team_submission(): void {
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $this->student->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $other->id]);
        $assign = $this->assign(['teamsubmission' => 1]);

        $this->clear_changes();
        $this->save($assign);
        $this->assertSame([$this->grading($assign)], $this->assign_changes());

        $this->clear_changes();
        $this->grade($assign, ['applytoall' => 1]);
        $this->assertEqualsCanonicalizing([
            $this->upsert($this->submission_id($assign)),
            $this->upsert($this->submission_id($assign, 0, $other), $other),
        ], $this->assign_changes());
    }

    /**
     * On Moodle 5.3 and later, one of several markers marking a submission records it.
     */
    public function test_submission_marked(): void {
        if (!class_exists(\mod_assign\event\submission_marked::class)) {
            $this->markTestSkipped('Multiple markers exist from Moodle 5.3.');
        }
        $assign = $this->assign(['markingworkflow' => 1, 'markingallocation' => 1, 'markercount' => 2]);
        $this->save($assign);
        $cmid = (int) $assign->get_course_module()->id;
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $markers = [
            $this->getDataGenerator()->create_and_enrol($this->course, 'teacher'),
            $this->getDataGenerator()->create_and_enrol($this->course, 'teacher'),
        ];
        foreach ($markers as $marker) {
            $generator->create_marker_allocation(['cmid' => $cmid, 'userid' => $this->student->id, 'markerid' => $marker->id]);
        }

        $this->clear_changes();
        $this->setUser($markers[0]);
        $generator->create_mark(['cmid' => $cmid, 'userid' => $this->student->id, 'markerid' => $markers[0]->id, 'mark' => 40]);

        $this->assertSame([$this->upsert($this->submission_id($assign))], $this->assign_changes());
    }

    /**
     * Saving an assignment's settings records an assign_grading upsert, as well as the course
     * module's (and its grade item's, which other tests cover); other modules' settings record no
     * assign_grading.
     */
    public function test_settings_saved(): void {
        $assign = $this->assign();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $cmid = (int) $assign->get_course_module()->id;

        $this->clear_changes();
        \core_courseformat\formatactions::cm($this->course->id)->rename($cmid, 'Renamed');
        \core_courseformat\formatactions::cm($this->course->id)->rename($page->cmid, 'Renamed');

        $this->assertEqualsCanonicalizing([
            $this->change('course_module', $cmid, 'upsert', null, (int) $this->course->id),
            $this->grading($assign),
            $this->change('course_module', (int) $page->cmid, 'upsert', null, (int) $this->course->id),
        ], array_values(array_filter(
            $this->changes(),
            fn(array $change): bool => in_array($change['entity'], ['course_module', 'assign_grading'])
        )));
    }

    /**
     * Submitting to, grading in and revealing identities in an assignment on the site course record nothing.
     */
    public function test_site_course_ignored(): void {
        $assign = $this->assign(['blindmarking' => 1], get_site());

        $this->clear_changes();
        $this->save($assign, get_admin());
        $this->grade($assign, [], get_admin());
        $assign->reveal_identities();
        \core_courseformat\formatactions::cm(SITEID)->rename($assign->get_course_module()->id, 'Renamed');

        $this->assertSame([], $this->assign_changes());
    }
}
