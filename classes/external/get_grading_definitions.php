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
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_arcs\local\source\grading_definitions;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_grading_definitions.
 *
 * It takes two filters, courseids and coursemoduleids, which cannot be combined with each other or
 * with ids. Every definition carries its criteria, so pages hold at most DETAIL_MAX_LIMIT rows.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_grading_definitions extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters() + [
            'courseids' => self::filter_parameter('Return only the definitions of assignments in these courses'),
            'coursemoduleids' => self::filter_parameter('Return only the definitions of these assignments, by course module id'),
        ]);
    }

    /**
     * Returns a page of assignments' rubrics and marking guides.
     *
     * @param int $after Cursor: return definitions with a higher id.
     * @param int $limit Most definitions, or ids, to return; pages hold at most DETAIL_MAX_LIMIT.
     * @param int[] $ids Return only these definitions; after is then ignored.
     * @param int[] $courseids Return only the definitions of assignments in these courses.
     * @param int[] $coursemoduleids Return only the definitions of these assignments, by course module id.
     * @return array
     * @throws moodle_exception module_not_installed, when the assignment module is not installed.
     */
    public static function execute(
        int $after = 0,
        int $limit = source::DEFAULT_LIMIT,
        array $ids = [],
        array $courseids = [],
        array $coursemoduleids = []
    ): array {
        global $DB;

        [
            'after' => $after,
            'limit' => $limit,
            'ids' => $ids,
            'courseids' => $courseids,
            'coursemoduleids' => $coursemoduleids,
        ] = self::validate_parameters(self::execute_parameters(), [
            'after' => $after,
            'limit' => $limit,
            'ids' => $ids,
            'courseids' => $courseids,
            'coursemoduleids' => $coursemoduleids,
        ]);
        $context = \core\context\system::instance();
        self::validate_context($context);
        require_capability('local/arcs:export', $context);

        if ($courseids && $coursemoduleids) {
            throw new invalid_parameter_exception('courseids and coursemoduleids cannot be combined');
        }
        $courseids = self::check_filter($ids, $courseids);
        $coursemoduleids = self::check_filter($ids, $coursemoduleids);
        $source = new grading_definitions($DB, $courseids, $coursemoduleids);
        if (!$source->is_installed()) {
            throw new moodle_exception('module_not_installed', 'local_arcs', '', $source->modname());
        }
        $page = self::read($source, $after, $limit, $ids, true);
        return ['gradingdefinitions' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::paged_returns('gradingdefinitions', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Grading definition id'),
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module id of the assignment'),
            'method' => new external_value(PARAM_ALPHA, 'Grading method: rubric or guide (marking guide)'),
            'active' => new external_value(PARAM_BOOL, 'Whether it is the assignment\'s grading method now'),
            'timemodified' => new external_value(PARAM_INT, 'When the definition last changed'),
            'criteria' => new external_multiple_structure(self::criterion_structure(), 'The criteria, in order'),
        ]));
    }

    /**
     * Describes one criterion of a rubric or marking guide.
     *
     * @return external_single_structure
     */
    private static function criterion_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Criterion id'),
            'sortorder' => new external_value(PARAM_INT, 'Position of the criterion in the definition'),
            'description' => new external_value(
                PARAM_RAW,
                'A rubric criterion\'s description, as plain text; null for a marking guide',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'shortname' => new external_value(
                PARAM_RAW,
                'A marking guide criterion\'s name, raw; null for a rubric',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'maxscore' => new external_value(
                PARAM_FLOAT,
                'A marking guide criterion\'s maximum score; null for a rubric',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'levels' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Level id'),
                    'score' => new external_value(PARAM_FLOAT, 'The score the level gives'),
                    'definition' => new external_value(PARAM_RAW, 'The level\'s definition, as plain text'),
                ]),
                'A rubric criterion\'s levels, by score; empty for a marking guide'
            ),
        ]);
    }
}
