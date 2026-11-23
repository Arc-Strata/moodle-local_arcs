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
require_once($CFG->dirroot . '/grade/grading/lib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(get_grading_definitions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\grading_definitions::class)]
/**
 * Tests for local_arcs_get_grading_definitions.
 *
 * Rubrics and marking guides are made through their plugins' test generators, which save them as
 * the editors do.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_grading_definitions
 * @covers     \local_arcs\local\source\grading_definitions
 */
final class get_grading_definitions_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @param int[] $ids Ids to fetch.
     * @param int[] $courseids Courses whose assignments' definitions to fetch.
     * @param int[] $cmids Assignments, by course module, whose definitions to fetch.
     * @return array
     */
    private function call(
        int $after = 0,
        int $limit = source::DEFAULT_LIMIT,
        array $ids = [],
        array $courseids = [],
        array $cmids = []
    ): array {
        $this->set_exporter();
        return external_api::clean_returnvalue(
            get_grading_definitions::execute_returns(),
            get_grading_definitions::execute($after, $limit, $ids, $courseids, $cmids)
        );
    }

    /**
     * The ids of the definitions in a result.
     *
     * @param array $result The cleaned result.
     * @return int[]
     */
    private function ids(array $result): array {
        return array_column($result['gradingdefinitions'], 'id');
    }

    /**
     * Creates an assignment.
     *
     * @param \stdClass $course The course.
     * @return \stdClass The assignment's course module.
     */
    private function assign(\stdClass $course): \stdClass {
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        return get_coursemodule_from_instance('assign', $instance->id);
    }

    /**
     * Makes a rubric the assignment's grading method.
     *
     * @param \stdClass $cm The assignment's course module.
     * @param array $criteria Levels (definition => score) by criterion description.
     * @return int The definition's id.
     */
    private function rubric(\stdClass $cm, array $criteria = ['Spelling' => ['Poor' => 0, 'Good' => 5]]): int {
        $this->setAdminUser();
        $controller = $this->getDataGenerator()->get_plugin_generator('gradingform_rubric')->create_instance(
            \core\context\module::instance($cm->id),
            'mod_assign',
            'submissions',
            'Rubric',
            'Rubric description',
            $criteria
        );
        return (int) $controller->get_definition()->id;
    }

    /**
     * Makes a marking guide the assignment's grading method.
     *
     * @param \stdClass $cm The assignment's course module.
     * @return int The definition's id.
     */
    private function guide(\stdClass $cm): int {
        $this->setAdminUser();
        $controller = $this->getDataGenerator()->get_plugin_generator('gradingform_guide')->create_instance(
            \core\context\module::instance($cm->id),
            'mod_assign',
            'submissions',
            'Guide',
            'Guide description',
            [
                'Spelling' => ['description' => 'For students', 'descriptionmarkers' => 'For markers', 'maxscore' => 10],
                'Pictures' => ['description' => 'For students', 'descriptionmarkers' => 'For markers', 'maxscore' => 2.5],
            ]
        );
        return (int) $controller->get_definition()->id;
    }

    /**
     * A site with no definitions returns none.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();

        $this->assertSame(['gradingdefinitions' => [], 'next' => null], $this->call());
    }

    /**
     * A rubric is returned with its criteria in order, each with its levels by score, as plain text.
     */
    public function test_execute_rubric(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->assign($course);
        $id = $this->rubric($cm, [
            "Spelling\nand grammar" => ['Many & frequent mistakes' => 0, 'None' => 4, 'Few' => 2],
            'Pictures' => ['Present' => 1],
        ]);
        $criteria = $DB->get_records('gradingform_rubric_criteria', ['definitionid' => $id], 'sortorder');
        [$spelling, $pictures] = array_values($criteria);
        // A rubric restored from elsewhere can hold HTML.
        $DB->set_field('gradingform_rubric_levels', 'definition', '<p>Is <em>present</em></p>', ['criterionid' => $pictures->id]);
        $DB->set_field('gradingform_rubric_levels', 'definitionformat', FORMAT_HTML, ['criterionid' => $pictures->id]);
        $levels = $DB->get_records_menu('gradingform_rubric_levels', null, '', 'definition, id');
        $timemodified = (int) $DB->get_field('grading_definitions', 'timemodified', ['id' => $id]);

        $this->assertSame(['gradingdefinitions' => [[
            'id' => $id,
            'coursemoduleid' => (int) $cm->id,
            'method' => 'rubric',
            'active' => true,
            'timemodified' => $timemodified,
            'criteria' => [
                [
                    'id' => (int) $spelling->id,
                    'sortorder' => (int) $spelling->sortorder,
                    'description' => "Spelling\nand grammar",
                    'shortname' => null,
                    'maxscore' => null,
                    'levels' => [
                        ['id' => (int) $levels['Many & frequent mistakes'], 'score' => 0.0,
                            'definition' => 'Many & frequent mistakes'],
                        ['id' => (int) $levels['Few'], 'score' => 2.0, 'definition' => 'Few'],
                        ['id' => (int) $levels['None'], 'score' => 4.0, 'definition' => 'None'],
                    ],
                ],
                [
                    'id' => (int) $pictures->id,
                    'sortorder' => (int) $pictures->sortorder,
                    'description' => 'Pictures',
                    'shortname' => null,
                    'maxscore' => null,
                    'levels' => [
                        ['id' => (int) $levels['<p>Is <em>present</em></p>'], 'score' => 1.0, 'definition' => 'Is _present_'],
                    ],
                ],
            ],
        ]], 'next' => null], $this->call());
    }

    /**
     * A marking guide is returned with its criteria's names and maximum scores, and not their descriptions.
     */
    public function test_execute_guide(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->assign($course);
        $id = $this->guide($cm);
        $criteria = $DB->get_records_menu('gradingform_guide_criteria', ['definitionid' => $id], '', 'shortname, id');

        $row = $this->call()['gradingdefinitions'][0];

        $this->assertSame('guide', $row['method']);
        $this->assertTrue($row['active']);
        $this->assertSame([
            ['id' => (int) $criteria['Spelling'], 'sortorder' => 1, 'description' => null, 'shortname' => 'Spelling',
                'maxscore' => 10.0, 'levels' => []],
            ['id' => (int) $criteria['Pictures'], 'sortorder' => 2, 'description' => null, 'shortname' => 'Pictures',
                'maxscore' => 2.5, 'levels' => []],
        ], $row['criteria']);
    }

    /**
     * Switching the assignment's grading method keeps both definitions; only the active one says so.
     */
    public function test_execute_active_after_method_switch(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->assign($course);
        $rubric = $this->rubric($cm);
        $guide = $this->guide($cm);

        $this->assertSame([$rubric => false, $guide => true], array_column($this->call()['gradingdefinitions'], 'active', 'id'));

        get_grading_manager(\core\context\module::instance($cm->id), 'mod_assign', 'submissions')->set_active_method('');

        $this->assertSame([$rubric => false, $guide => false], array_column($this->call()['gradingdefinitions'], 'active', 'id'));
    }

    /**
     * Definitions outside assignments' submissions areas, and in assignments on the site course, are left out.
     */
    public function test_execute_leaves_out_other_areas_and_site_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $this->getDataGenerator()->get_plugin_generator('gradingform_rubric')->get_test_rubric(
            \core\context\module::instance($forum->cmid),
            'mod_forum',
            'forum'
        );
        $this->rubric($this->assign(get_site()));

        $this->assertSame([], $this->call()['gradingdefinitions']);
    }

    /**
     * Definitions page by cursor and are fetched by id.
     */
    public function test_execute_pages_and_ids(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $first = $this->rubric($this->assign($course));
        $second = $this->guide($this->assign($course));
        $third = $this->rubric($this->assign($course));

        $page = $this->call(0, 2);
        $this->assertSame([$first, $second], $this->ids($page));
        $this->assertSame($second, $page['next']);
        $page = $this->call($page['next'], 2);
        $this->assertSame([$third], $this->ids($page));
        $this->assertNull($page['next']);

        $this->assertSame([$first, $third], $this->ids($this->call(0, 10, [$third, $first, $first, 999999])));
    }

    /**
     * A page holds at most DETAIL_MAX_LIMIT definitions whatever limit says, and more ids are refused.
     */
    public function test_execute_page_size(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::DETAIL_MAX_LIMIT));
        get_grading_definitions::execute(0, source::DEFAULT_LIMIT, range(1, source::DETAIL_MAX_LIMIT + 1));
    }

    /**
     * Definitions are filtered by course and by course module.
     */
    public function test_execute_by_course_and_by_module(): void {
        $this->resetAfterTest();
        $one = $this->getDataGenerator()->create_course();
        $two = $this->getDataGenerator()->create_course();
        $cm = $this->assign($one);
        $first = $this->rubric($cm);
        $second = $this->guide($this->assign($one));
        $third = $this->rubric($this->assign($two));

        $this->assertSame([$first, $second], $this->ids($this->call(0, 10, [], [$one->id])));
        $this->assertSame([$third], $this->ids($this->call(0, 10, [], [$two->id])));
        $this->assertSame([$first], $this->ids($this->call(0, 10, [], [], [$cm->id])));
    }

    /**
     * The two filters cannot be combined.
     */
    public function test_execute_filters_not_combined(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(invalid_parameter_exception::class);
        get_grading_definitions::execute(0, 10, [], [1], [2]);
    }

    /**
     * A site where the assignment module has been uninstalled is refused with module_not_installed.
     */
    public function test_execute_module_not_installed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $DB->delete_records('modules', ['name' => 'assign']);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('module_not_installed', 'local_arcs', 'assign'));
        get_grading_definitions::execute();
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_grading_definitions::execute();
    }
}
