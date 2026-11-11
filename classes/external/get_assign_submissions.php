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
use core_external\external_single_structure;
use core_external\external_value;
use local_arcs\local\source\assign_submissions;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_assign_submissions.
 *
 * It takes two filters, courseids and coursemoduleids, which cannot be combined with
 * each other or with ids.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_assign_submissions extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters() + [
            'courseids' => self::filter_parameter('Return only assignment submissions in these courses'),
            'coursemoduleids' => self::filter_parameter('Return only submissions to these assignments, by course module id'),
        ]);
    }

    /**
     * Returns a page of assignment submissions.
     *
     * @param int $after Cursor: return submissions with a higher id.
     * @param int $limit Most submissions, or ids, to return.
     * @param int[] $ids Return only these submissions; after is then ignored.
     * @param int[] $courseids Return only submissions in these courses.
     * @param int[] $coursemoduleids Return only submissions to these assignments, by course module id.
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
        $source = new assign_submissions($DB, $courseids, $coursemoduleids);
        if (!$source->is_installed()) {
            throw new moodle_exception('module_not_installed', 'local_arcs', '', $source->modname());
        }
        $page = self::read($source, $after, $limit, $ids);
        return ['assignsubmissions' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::paged_returns('assignsubmissions', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Assignment submission id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module id of the assignment'),
            'attempt' => new external_value(PARAM_INT, 'Attempt number: 1 for the user\'s first attempt at the assignment'),
            'status' => new external_value(
                PARAM_ALPHA,
                'Status: new, draft, submitted or reopened',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'timecreated' => new external_value(PARAM_INT, 'When the submission was created'),
            'timemodified' => new external_value(PARAM_INT, 'When the submission last changed'),
            'timestarted' => new external_value(
                PARAM_INT,
                'When the user started the submission, in an assignment with a time limit; null otherwise',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'grade' => new external_value(
                PARAM_FLOAT,
                'The grade for this attempt: points, or the position on the scale; null until graded and released',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'grademax' => new external_value(
                PARAM_FLOAT,
                'The assignment\'s maximum points; null when it is graded on a scale or not graded',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'scaleid' => new external_value(
                PARAM_INT,
                'The scale the assignment is graded on; null when it is graded in points or not graded',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'penalty' => new external_value(
                PARAM_FLOAT,
                'Percentage deducted from the grade as a late penalty (Moodle 5.0+); null when grade is null or before 5.0',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'timegraded' => new external_value(
                PARAM_INT,
                'When the grade was last changed; null when grade is null',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'passed' => new external_value(
                PARAM_BOOL,
                'Whether the attempt passed; always null, as an assignment does not decide it (see the grade item\'s gradepass)',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
        ]));
    }
}
