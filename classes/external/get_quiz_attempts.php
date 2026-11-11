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
use local_arcs\local\source\quiz_attempts;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_quiz_attempts.
 *
 * It takes two filters, courseids and coursemoduleids, which cannot be combined with
 * each other or with ids.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_quiz_attempts extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters() + [
            'courseids' => self::filter_parameter('Return only quiz attempts in these courses'),
            'coursemoduleids' => self::filter_parameter('Return only attempts at these quizzes, by course module id'),
        ]);
    }

    /**
     * Returns a page of quiz attempts.
     *
     * @param int $after Cursor: return quiz attempts with a higher id.
     * @param int $limit Most quiz attempts, or ids, to return.
     * @param int[] $ids Return only these quiz attempts; after is then ignored.
     * @param int[] $courseids Return only quiz attempts in these courses.
     * @param int[] $coursemoduleids Return only attempts at these quizzes, by course module id.
     * @return array
     * @throws moodle_exception module_not_installed, when the quiz module is not installed.
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
        $source = new quiz_attempts($DB, $courseids, $coursemoduleids);
        if (!$source->is_installed()) {
            throw new moodle_exception('module_not_installed', 'local_arcs', '', $source->modname());
        }
        $page = self::read($source, $after, $limit, $ids);
        return ['quizattempts' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::paged_returns('quizattempts', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Quiz attempt id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module id of the quiz'),
            'attempt' => new external_value(PARAM_INT, 'Attempt number: 1 for the user\'s first attempt at the quiz'),
            'state' => new external_value(
                PARAM_ALPHA,
                'State: inprogress, overdue, finished or abandoned; from Moodle 5.3 also submitted (finished, ' .
                    'not yet graded)'
            ),
            'timestart' => new external_value(PARAM_INT, 'When the attempt was started'),
            'timefinish' => new external_value(PARAM_INT, 'When the attempt was submitted or abandoned; 0 until then'),
            'timemodified' => new external_value(PARAM_INT, 'When the attempt last changed'),
            'sumgrades' => new external_value(
                PARAM_FLOAT,
                'Total of the attempt\'s question marks; null while not graded',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'maxsumgrades' => new external_value(PARAM_FLOAT, 'Total of the quiz\'s question marks'),
            'grade' => new external_value(
                PARAM_FLOAT,
                'The attempt\'s grade on the quiz\'s scale, unrounded; null while not graded, or when the quiz has no marks',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'grademax' => new external_value(PARAM_FLOAT, 'The quiz\'s maximum grade'),
            'passed' => new external_value(
                PARAM_BOOL,
                'Whether the attempt passed; always null, as a quiz does not decide it (see the grade item\'s gradepass)',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
        ]));
    }
}
