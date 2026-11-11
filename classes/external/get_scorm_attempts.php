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
use local_arcs\local\source\scorm_attempts;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_scorm_attempts.
 *
 * It takes two filters, courseids and coursemoduleids, which cannot be combined with
 * each other or with ids.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_scorm_attempts extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters() + [
            'courseids' => self::filter_parameter('Return only SCORM attempts in these courses'),
            'coursemoduleids' => self::filter_parameter('Return only attempts at these SCORM packages, by course module id'),
        ]);
    }

    /**
     * Returns a page of SCORM attempts.
     *
     * @param int $after Cursor: return SCORM attempts with a higher id.
     * @param int $limit Most SCORM attempts, or ids, to return.
     * @param int[] $ids Return only these SCORM attempts; after is then ignored.
     * @param int[] $courseids Return only SCORM attempts in these courses.
     * @param int[] $coursemoduleids Return only attempts at these SCORM packages, by course module id.
     * @return array
     * @throws moodle_exception module_not_installed, when the SCORM module is not installed.
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
        $source = new scorm_attempts($DB, $courseids, $coursemoduleids);
        if (!$source->is_installed()) {
            throw new moodle_exception('module_not_installed', 'local_arcs', '', $source->modname());
        }
        $page = self::read($source, $after, $limit, $ids);
        return ['scormattempts' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $sco = new external_single_structure([
            'scoid' => new external_value(PARAM_INT, 'SCO id (scorm_scoes.id)'),
            'identifier' => new external_value(PARAM_RAW, 'The SCO\'s identifier in the package manifest, raw'),
            'title' => new external_value(PARAM_RAW, 'The SCO\'s title, raw'),
            'status' => new external_value(
                PARAM_RAW,
                'cmi.core.lesson_status (SCORM 1.2) or cmi.completion_status (SCORM 2004); null if not reported',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'successstatus' => new external_value(
                PARAM_RAW,
                'cmi.success_status (SCORM 2004); null if not reported',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'scoreraw' => new external_value(
                PARAM_FLOAT,
                'Raw score; null if not reported',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'scoremin' => new external_value(
                PARAM_FLOAT,
                'Lowest possible score; null if not reported',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'scoremax' => new external_value(
                PARAM_FLOAT,
                'Highest possible score; null if not reported',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'starttime' => new external_value(
                PARAM_INT,
                'When the SCO was first launched in this attempt; null if not recorded',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'timemodified' => new external_value(PARAM_INT, 'When any of these values last changed'),
        ]);

        return self::paged_returns('scormattempts', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'SCORM attempt id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module id of the SCORM package'),
            'attempt' => new external_value(PARAM_INT, 'Attempt number: 1 for the user\'s first attempt at the package'),
            'passed' => new external_value(
                PARAM_BOOL,
                'Always null: SCORM reports passing per SCO (status, successstatus)',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'scos' => new external_multiple_structure($sco, 'The SCOs tracked in the attempt, by SCO id'),
        ]));
    }
}
