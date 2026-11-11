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
use local_arcs\local\source\h5pactivity_attempts;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_h5pactivity_attempts.
 *
 * It takes two filters, courseids and coursemoduleids, which cannot be combined with
 * each other or with ids.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_h5pactivity_attempts extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters() + [
            'courseids' => self::filter_parameter('Return only H5P activity attempts in these courses'),
            'coursemoduleids' => self::filter_parameter('Return only attempts at these H5P activities, by course module id'),
        ]);
    }

    /**
     * Returns a page of H5P activity attempts.
     *
     * @param int $after Cursor: return H5P activity attempts with a higher id.
     * @param int $limit Most H5P activity attempts, or ids, to return.
     * @param int[] $ids Return only these H5P activity attempts; after is then ignored.
     * @param int[] $courseids Return only H5P activity attempts in these courses.
     * @param int[] $coursemoduleids Return only attempts at these H5P activities, by course module id.
     * @return array
     * @throws moodle_exception module_not_installed, when the H5P activity module is not installed.
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
        $source = new h5pactivity_attempts($DB, $courseids, $coursemoduleids);
        if (!$source->is_installed()) {
            throw new moodle_exception('module_not_installed', 'local_arcs', '', $source->modname());
        }
        $page = self::read($source, $after, $limit, $ids);
        return ['h5pactivityattempts' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::paged_returns('h5pactivityattempts', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'H5P activity attempt id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module id of the H5P activity'),
            'attempt' => new external_value(PARAM_INT, 'Attempt number: 1 for the user\'s first attempt at the activity'),
            'rawscore' => new external_value(
                PARAM_INT,
                'Score; null if the content did not report one',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'maxscore' => new external_value(
                PARAM_INT,
                'Maximum score; null if the content did not report one',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'scaled' => new external_value(PARAM_FLOAT, 'Score scaled from 0 to 1'),
            'duration' => new external_value(
                PARAM_INT,
                'Seconds spent, as the content reported it; null if it did not',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'completion' => new external_value(
                PARAM_BOOL,
                'Whether the content reported the attempt complete; null if it did not say',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'success' => new external_value(
                PARAM_BOOL,
                'Whether the content reported the attempt successful; null if it did not say',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'timecreated' => new external_value(PARAM_INT, 'When the attempt was started'),
            'timemodified' => new external_value(PARAM_INT, 'When the attempt last changed'),
            'passed' => new external_value(
                PARAM_BOOL,
                'Whether the attempt passed: success, as the content decides it',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
        ]));
    }
}
