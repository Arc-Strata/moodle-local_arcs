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
require_once($CFG->dirroot . '/mod/assign/locallib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(get_assign_submissions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\assign_submissions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\attempts::class)]
/**
 * Tests for local_arcs_get_assign_submissions.
 *
 * Submissions and grades are made through mod_assign's own API, as its pages do.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_assign_submissions
 * @covers     \local_arcs\local\source\assign_submissions
 * @covers     \local_arcs\local\source\attempts
 */
final class get_assign_submissions_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @param int[] $ids Ids to fetch.
     * @param int[] $courseids Courses whose submissions to fetch.
     * @param int[] $cmids Assignments, by course module, whose submissions to fetch.
     * @param bool $includedetail Whether to fetch each graded submission's fillings.
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
        $this->set_exporter();
        return external_api::clean_returnvalue(
            get_assign_submissions::execute_returns(),
            get_assign_submissions::execute($after, $limit, $ids, $courseids, $cmids, $includedetail)
        );
    }

    /**
     * The submissions in a page fetched with includedetail.
     *
     * @return array[]
     */
    private function detail(): array {
        return $this->call(0, source::DEFAULT_LIMIT, [], [], [], true)['assignsubmissions'];
    }

    /**
     * Makes a two-criterion rubric the assignment's grading method, as the rubric editor does.
     *
     * @param \assign $assign The assignment.
     * @return \gradingform_rubric_controller
     */
    private function rubric(\assign $assign): \gradingform_rubric_controller {
        $this->setAdminUser();
        return $this->getDataGenerator()->get_plugin_generator('gradingform_rubric')->create_instance(
            $assign->get_context(),
            'mod_assign',
            'submissions',
            'Rubric',
            '',
            [
                'Spelling' => ['Many mistakes' => 0, 'Few mistakes' => 3, 'No mistakes' => 5],
                'Pictures' => ['None' => 0, 'Some' => 5],
            ]
        );
    }

    /**
     * Makes a two-criterion marking guide the assignment's grading method, as the guide editor does.
     *
     * @param \assign $assign The assignment.
     * @return \gradingform_guide_controller
     */
    private function guide(\assign $assign): \gradingform_guide_controller {
        $this->setAdminUser();
        $criterion = ['description' => 'For students', 'descriptionmarkers' => 'For markers', 'maxscore' => 10];
        return $this->getDataGenerator()->get_plugin_generator('gradingform_guide')->create_instance(
            $assign->get_context(),
            'mod_assign',
            'submissions',
            'Guide',
            '',
            ['Spelling' => $criterion, 'Pictures' => $criterion]
        );
    }

    /**
     * Grades the student's latest attempt with the assignment's rubric or marking guide, as the grading form does.
     *
     * @param \assign $assign The assignment.
     * @param \stdClass $student The student.
     * @param \gradingform_controller $controller The rubric or guide.
     * @param float[] $scores The score for each criterion, by criterion name; for a rubric, the chosen level's.
     * @param array $data Other form data, such as workflowstate.
     */
    private function grade_with(
        \assign $assign,
        \stdClass $student,
        \gradingform_controller $controller,
        array $scores,
        array $data = []
    ): void {
        $method = $controller instanceof \gradingform_rubric_controller ? 'rubric' : 'guide';
        $generator = $this->getDataGenerator()->get_plugin_generator('gradingform_' . $method);
        $values = array_map(fn(float $score): array => ['score' => $score, 'remark' => 'Remark'], $scores);
        $form = $generator->get_submitted_form_data($controller, 0, $values);
        $this->grade($assign, $student, 0, $data + ['advancedgrading' => $form]);
    }

    /**
     * A rubric's criterion and level ids, by criterion description and level score.
     *
     * @param \gradingform_rubric_controller $controller The rubric.
     * @return array[] [criterion description => ['id' => int, 'levels' => [score => level id]]]
     */
    private function rubric_ids(\gradingform_rubric_controller $controller): array {
        $ids = [];
        foreach ($controller->get_definition(true)->rubric_criteria as $criterion) {
            $levels = [];
            foreach ($criterion['levels'] as $level) {
                $levels[(int) $level['score']] = (int) $level['id'];
            }
            $ids[$criterion['description']] = ['id' => (int) $criterion['id'], 'levels' => $levels];
        }
        return $ids;
    }

    /**
     * Creates an assignment with online text submissions and no drafts, so saving submits.
     *
     * @param \stdClass $course The course.
     * @param array $settings Other settings.
     * @return \assign
     */
    private function assign(\stdClass $course, array $settings = []): \assign {
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
     * @param \stdClass $student The student.
     * @return int The student's latest submission id.
     */
    private function submit(\assign $assign, \stdClass $student): int {
        $this->setUser($student);
        $data = (object) [
            'userid' => $student->id,
            'onlinetext_editor' => ['itemid' => file_get_unused_draft_itemid(), 'text' => 'Text', 'format' => FORMAT_HTML],
        ];
        $sink = $this->redirectMessages();
        $notices = [];
        $this->assertTrue($assign->save_submission($data, $notices));
        $sink->close();
        $this->setAdminUser();
        return (int) $assign->get_user_submission($student->id, false)->id;
    }

    /**
     * Grades the student's latest attempt as an administrator, as the grading form does.
     *
     * @param \assign $assign The assignment.
     * @param \stdClass $student The student.
     * @param float $grade The grade.
     * @param array $data Other form data, such as workflowstate or addattempt.
     */
    private function grade(\assign $assign, \stdClass $student, float $grade, array $data = []): void {
        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $assign->save_grade($student->id, (object) ($data + ['grade' => $grade, 'attemptnumber' => -1]));
        $sink->close();
    }

    /**
     * The ids of the submissions in a result.
     *
     * @param array $result The cleaned result.
     * @return int[]
     */
    private function ids(array $result): array {
        return array_column($result['assignsubmissions'], 'id');
    }

    /**
     * A site with no submissions returns none.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();

        $this->assertSame(['assignsubmissions' => [], 'next' => null], $this->call());
    }

    /**
     * Every field is returned for a graded submission.
     */
    public function test_execute_returns_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->assign($course, ['grade' => 80]);
        $id = $this->submit($assign, $student);
        $this->grade($assign, $student, 64.5);
        $submission = $DB->get_record('assign_submission', ['id' => $id]);
        $grade = $DB->get_record('assign_grades', ['assignment' => $assign->get_instance()->id, 'userid' => $student->id]);

        $row = $this->call()['assignsubmissions'][0];

        $this->assertSame([
            'id' => $id,
            'userid' => (int) $student->id,
            'coursemoduleid' => (int) $assign->get_course_module()->id,
            'attempt' => 1,
            'status' => 'submitted',
            'timecreated' => (int) $submission->timecreated,
            'timemodified' => (int) $submission->timemodified,
            'timestarted' => null,
            'grade' => 64.5,
            'grademax' => 80.0,
            'scaleid' => null,
            // Grade penalties (Moodle 5.0+) record 0 when none applies; earlier versions have none.
            'penalty' => array_key_exists('penalty', $DB->get_columns('assign_grades')) ? 0.0 : null,
            'timegraded' => (int) $grade->timemodified,
            'passed' => null,
        ], $row);
    }

    /**
     * Without a grade (none given, or "no grade"), grade and timegraded are null.
     */
    public function test_execute_without_grade(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->assign($course);
        $this->submit($assign, $student);
        $this->submit($assign, $other);
        $this->grade($assign, $other, -1);

        foreach ($this->call()['assignsubmissions'] as $row) {
            $this->assertNull($row['grade']);
            $this->assertNull($row['timegraded']);
            $this->assertNull($row['penalty']);
            $this->assertSame(100.0, $row['grademax']);
        }
    }

    /**
     * A scale grade is returned as the position on the scale, with the scale's id and no maximum.
     */
    public function test_execute_scale_grade(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Poor,Fair,Good']);
        $assign = $this->assign($course, ['grade' => -$scale->id]);
        $this->submit($assign, $student);
        $this->grade($assign, $student, 2);

        $row = $this->call()['assignsubmissions'][0];

        $this->assertSame(2.0, $row['grade']);
        $this->assertNull($row['grademax']);
        $this->assertSame((int) $scale->id, $row['scaleid']);
    }

    /**
     * A late penalty, from Moodle 5.0, is returned as the percentage deducted.
     */
    public function test_execute_penalty(): void {
        global $DB;
        $this->resetAfterTest();
        if (!array_key_exists('penalty', $DB->get_columns('assign_grades'))) {
            $this->markTestSkipped('Grade penalties exist from Moodle 5.0.');
        }
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->assign($course);
        $this->submit($assign, $student);
        $this->grade($assign, $student, 50);
        // Applying a penalty needs a penalty plugin; the column is what the source reads.
        $DB->set_field('assign_grades', 'penalty', 12.5, ['userid' => $student->id]);

        $this->assertSame(12.5, $this->call()['assignsubmissions'][0]['penalty']);
    }

    /**
     * Each attempt is its own row, numbered from 1, with its own grade.
     */
    public function test_execute_attempts(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->assign($course, ['attemptreopenmethod' => 'manual', 'maxattempts' => -1]);
        $first = $this->submit($assign, $student);
        $this->grade($assign, $student, 40, ['addattempt' => 1]);
        $second = $this->submit($assign, $student);
        $this->grade($assign, $student, 70);

        $rows = $this->call()['assignsubmissions'];

        $this->assertSame([$first, $second], array_column($rows, 'id'));
        $this->assertSame([1, 2], array_column($rows, 'attempt'));
        $this->assertSame([40.0, 70.0], array_column($rows, 'grade'));
    }

    /**
     * Under blind marking, grades are withheld until identities are revealed.
     */
    public function test_execute_blind_marking(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->assign($course, ['blindmarking' => 1]);
        $this->submit($assign, $student);
        $this->grade($assign, $student, 50);

        $row = $this->call()['assignsubmissions'][0];
        $this->assertNull($row['grade']);
        $this->assertNull($row['timegraded']);
        $this->assertSame(100.0, $row['grademax']);

        $this->setAdminUser();
        $assign->reveal_identities();

        $this->assertSame(50.0, $this->call()['assignsubmissions'][0]['grade']);
    }

    /**
     * Under a marking workflow, a grade is withheld until the user's grade is released.
     */
    public function test_execute_marking_workflow(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->assign($course, ['markingworkflow' => 1]);
        $this->submit($assign, $student);
        $this->grade($assign, $student, 50, ['workflowstate' => 'readyforrelease']);

        $this->assertNull($this->call()['assignsubmissions'][0]['grade']);

        $this->grade($assign, $student, 50, ['workflowstate' => 'released']);

        $this->assertSame(50.0, $this->call()['assignsubmissions'][0]['grade']);
    }

    /**
     * With anonymous marking, a released grade is returned while identities are still hidden.
     */
    public function test_execute_anonymous_marking(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->assign($course, ['blindmarking' => 1, 'markingworkflow' => 1, 'markinganonymous' => 1]);
        $this->submit($assign, $student);
        $this->grade($assign, $student, 50, ['workflowstate' => 'released']);

        $this->assertSame(50.0, $this->call()['assignsubmissions'][0]['grade']);
    }

    /**
     * In a team submission, the members' own rows are returned and the group's row is not.
     */
    public function test_execute_team_submission(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $one = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $two = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $one->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $two->id]);
        $assign = $this->assign($course, ['teamsubmission' => 1]);
        $this->submit($assign, $one);
        $this->assertTrue($DB->record_exists('assign_submission', ['assignment' => $assign->get_instance()->id, 'userid' => 0]));

        $rows = $this->call()['assignsubmissions'];

        $this->assertEqualsCanonicalizing([(int) $one->id, (int) $two->id], array_column($rows, 'userid'));
    }

    /**
     * Without includedetail, submissions carry no grading definition or fillings.
     */
    public function test_execute_without_detail_has_no_fillings(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->assign($course);
        $rubric = $this->rubric($assign);
        $this->submit($assign, $student);
        $this->grade_with($assign, $student, $rubric, ['Spelling' => 5, 'Pictures' => 0]);

        $row = $this->call()['assignsubmissions'][0];

        $this->assertArrayNotHasKey('gradingdefinitionid', $row);
        $this->assertArrayNotHasKey('fillings', $row);
    }

    /**
     * With includedetail, a submission graded with a rubric carries the level chosen for each criterion.
     */
    public function test_execute_rubric_fillings(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->assign($course);
        $rubric = $this->rubric($assign);
        $this->submit($assign, $student);
        $this->grade_with($assign, $student, $rubric, ['Spelling' => 3, 'Pictures' => 5]);
        $ids = $this->rubric_ids($rubric);

        $row = $this->detail()[0];

        $this->assertSame(80.0, $row['grade']);
        $this->assertSame((int) $rubric->get_definition()->id, $row['gradingdefinitionid']);
        $this->assertSame([
            ['criterionid' => $ids['Spelling']['id'], 'levelid' => $ids['Spelling']['levels'][3], 'score' => 3.0],
            ['criterionid' => $ids['Pictures']['id'], 'levelid' => $ids['Pictures']['levels'][5], 'score' => 5.0],
        ], $row['fillings']);
    }

    /**
     * With includedetail, a submission graded with a marking guide carries the score given for each criterion.
     */
    public function test_execute_guide_fillings(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->assign($course);
        $guide = $this->guide($assign);
        $this->submit($assign, $student);
        $this->grade_with($assign, $student, $guide, ['Spelling' => 7.5, 'Pictures' => 4]);
        $criteria = array_column($guide->get_definition(true)->guide_criteria, 'id', 'shortname');

        $row = $this->detail()[0];

        $this->assertSame((int) $guide->get_definition()->id, $row['gradingdefinitionid']);
        $this->assertSame([
            ['criterionid' => (int) $criteria['Spelling'], 'levelid' => null, 'score' => 7.5],
            ['criterionid' => (int) $criteria['Pictures'], 'levelid' => null, 'score' => 4.0],
        ], $row['fillings']);
    }

    /**
     * Simple direct grading, and a submission not yet graded, carry no definition and no fillings.
     */
    public function test_execute_detail_without_advanced_grading(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $one = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $two = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $simple = $this->assign($course);
        $this->submit($simple, $one);
        $this->grade($simple, $one, 50);
        $rubric = $this->assign($course);
        $this->rubric($rubric);
        $this->submit($rubric, $two);

        $rows = $this->detail();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row['gradingdefinitionid']);
            $this->assertSame([], $row['fillings']);
        }
    }

    /**
     * Fillings are withheld with the grade: under a marking workflow until released, and under blind
     * marking until identities are revealed.
     */
    public function test_execute_detail_withheld(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $one = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $two = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $workflow = $this->assign($course, ['markingworkflow' => 1]);
        $workflowrubric = $this->rubric($workflow);
        $this->submit($workflow, $one);
        $this->grade_with($workflow, $one, $workflowrubric, ['Spelling' => 5, 'Pictures' => 5], [
            'workflowstate' => 'readyforrelease',
        ]);
        $blind = $this->assign($course, ['blindmarking' => 1]);
        $blindrubric = $this->rubric($blind);
        $this->submit($blind, $two);
        $this->grade_with($blind, $two, $blindrubric, ['Spelling' => 5, 'Pictures' => 5]);

        foreach ($this->detail() as $row) {
            $this->assertNull($row['grade']);
            $this->assertNull($row['gradingdefinitionid']);
            $this->assertSame([], $row['fillings']);
        }

        $this->grade_with($workflow, $one, $workflowrubric, ['Spelling' => 5, 'Pictures' => 5], [
            'workflowstate' => 'released',
        ]);
        $this->setAdminUser();
        $blind->reveal_identities();

        foreach ($this->detail() as $row) {
            $this->assertSame(100.0, $row['grade']);
            $this->assertCount(2, $row['fillings']);
        }
    }

    /**
     * Regrading returns only the current fillings, not the archived ones.
     */
    public function test_execute_detail_regrade(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->assign($course);
        $rubric = $this->rubric($assign);
        $this->submit($assign, $student);
        $this->grade_with($assign, $student, $rubric, ['Spelling' => 0, 'Pictures' => 0]);
        $this->grade_with($assign, $student, $rubric, ['Spelling' => 5, 'Pictures' => 0]);
        $this->assertSame(2, $DB->count_records('grading_instances'));

        $fillings = $this->detail()[0]['fillings'];

        $this->assertSame([5.0, 0.0], array_column($fillings, 'score'));
    }

    /**
     * After the rubric is edited and its grades marked for regrading, the fillings are still returned;
     * a filling whose level was deleted has no level and no score.
     */
    public function test_execute_detail_rubric_edited_after_grading(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->assign($course);
        $rubric = $this->rubric($assign);
        $this->submit($assign, $student);
        $this->grade_with($assign, $student, $rubric, ['Spelling' => 3, 'Pictures' => 5]);
        $ids = $this->rubric_ids($rubric);

        $definition = $rubric->get_definition_for_editing();
        unset($definition->rubric['criteria'][$ids['Spelling']['id']]['levels'][$ids['Spelling']['levels'][3]]);
        $definition->rubric['regrade'] = 1;
        $rubric->update_definition($definition);
        $this->assertTrue($DB->record_exists('grading_instances', ['status' => \gradingform_instance::INSTANCE_STATUS_NEEDUPDATE]));

        $this->assertSame([
            ['criterionid' => $ids['Spelling']['id'], 'levelid' => null, 'score' => null],
            ['criterionid' => $ids['Pictures']['id'], 'levelid' => $ids['Pictures']['levels'][5], 'score' => 5.0],
        ], $this->detail()[0]['fillings']);
    }

    /**
     * Only the fillings of the assignment's active grading method are returned.
     */
    public function test_execute_detail_after_method_switch(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->assign($course);
        $rubric = $this->rubric($assign);
        $this->submit($assign, $student);
        $this->grade_with($assign, $student, $rubric, ['Spelling' => 3, 'Pictures' => 5]);

        get_grading_manager($assign->get_context(), 'mod_assign', 'submissions')->set_active_method('');

        $row = $this->detail()[0];
        $this->assertSame(80.0, $row['grade']);
        $this->assertNull($row['gradingdefinitionid']);
        $this->assertSame([], $row['fillings']);
    }

    /**
     * In a team submission graded for the whole team, each member's row carries its own fillings.
     */
    public function test_execute_detail_team_submission(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $one = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $two = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $one->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $two->id]);
        $assign = $this->assign($course, ['teamsubmission' => 1]);
        $rubric = $this->rubric($assign);
        $this->submit($assign, $one);
        $this->grade_with($assign, $one, $rubric, ['Spelling' => 3, 'Pictures' => 5], ['applytoall' => 1]);

        $rows = $this->detail();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame((int) $rubric->get_definition()->id, $row['gradingdefinitionid']);
            $this->assertSame([3.0, 5.0], array_column($row['fillings'], 'score'));
        }
    }

    /**
     * With includedetail, a page holds at most DETAIL_MAX_LIMIT submissions, and more ids are refused.
     */
    public function test_execute_detail_page_size(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::DETAIL_MAX_LIMIT));
        get_assign_submissions::execute(0, source::DEFAULT_LIMIT, range(1, source::DETAIL_MAX_LIMIT + 1), [], [], true);
    }

    /**
     * Submissions to assignments on the site course are left out.
     */
    public function test_execute_leaves_out_site_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $kept = $this->submit($this->assign($course), $student);
        $this->setAdminUser();
        $this->submit($this->assign(get_site()), get_admin());

        $this->assertSame([$kept], $this->ids($this->call()));
    }

    /**
     * A reader can page with next; ids return exactly the existing submissions.
     */
    public function test_execute_pages_and_ids(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->assign($course);
        $one = $this->submit($assign, $this->getDataGenerator()->create_and_enrol($course, 'student'));
        $two = $this->submit($assign, $this->getDataGenerator()->create_and_enrol($course, 'student'));

        $page1 = $this->call(0, 1);
        $page2 = $this->call($page1['next'], 1);

        $this->assertSame([$one], $this->ids($page1));
        $this->assertSame([$two], $this->ids($page2));
        $this->assertNull($page2['next']);
        $this->assertSame([$two], $this->ids($this->call(0, 10, [$two, 999999])));
    }

    /**
     * With courseids, only those courses' submissions are returned; with coursemoduleids, only those assignments'.
     */
    public function test_execute_by_course_and_by_module(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $first = $this->assign($course);
        $second = $this->assign($course);
        $one = $this->submit($first, $student);
        $two = $this->submit($second, $student);
        $othercourse = $this->getDataGenerator()->create_course();
        $this->submit($this->assign($othercourse), $this->getDataGenerator()->create_and_enrol($othercourse, 'student'));

        $this->assertSame([$one, $two], $this->ids($this->call(0, 10, [], [(int) $course->id])));
        $this->assertSame([$two], $this->ids($this->call(0, 10, [], [], [(int) $second->get_course_module()->id])));
        $this->assertSame([], $this->ids($this->call(0, 10, [], [999999])));
    }

    /**
     * courseids and coursemoduleids together are refused, as is either with ids.
     */
    public function test_execute_filters_not_combined(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        try {
            get_assign_submissions::execute(0, 10, [1], [], [2]);
            $this->fail('ids with coursemoduleids should be refused');
        } catch (invalid_parameter_exception $e) {
            $this->assertInstanceOf(invalid_parameter_exception::class, $e);
        }
        $this->expectException(invalid_parameter_exception::class);
        get_assign_submissions::execute(0, 10, [], [1], [2]);
    }

    /**
     * More courseids than the maximum are refused with limit_exceeded.
     */
    public function test_execute_too_many_course_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::MAX_LIMIT));
        get_assign_submissions::execute(0, 10, [], range(1, source::MAX_LIMIT + 1));
    }

    /**
     * A site where the assignment module has been uninstalled is refused with module_not_installed.
     */
    public function test_execute_module_not_installed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        // Uninstalling a module deletes its modules row (and its tables, which tests cannot drop).
        $DB->delete_records('modules', ['name' => 'assign']);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('module_not_installed', 'local_arcs', 'assign'));
        get_assign_submissions::execute();
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_assign_submissions::execute();
    }
}
