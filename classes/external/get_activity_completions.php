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
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_arcs\local\source\activity_completions;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_activity_completions.
 *
 * It takes two filters, courseids and coursemoduleids, which cannot be combined with
 * each other or with ids.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_activity_completions extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters() + [
            'courseids' => self::filter_parameter('Return only activity completions in these courses'),
            'coursemoduleids' => self::filter_parameter('Return only activity completions of these course modules'),
        ]);
    }

    /**
     * Returns a page of activity completions.
     *
     * @param int $after Cursor: return activity completions with a higher id.
     * @param int $limit Most activity completions, or ids, to return.
     * @param int[] $ids Return only these activity completions; after is then ignored.
     * @param int[] $courseids Return only activity completions in these courses.
     * @param int[] $coursemoduleids Return only activity completions of these course modules.
     * @return array
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
        $page = self::read(new activity_completions($DB, $courseids, $coursemoduleids), $after, $limit, $ids);
        return ['activitycompletions' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::paged_returns('activitycompletions', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Activity completion id'),
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'completionstate' => new external_value(
                PARAM_INT,
                'Completion state: 0 incomplete, 1 complete, 2 complete and passed, 3 complete and failed, ' .
                    '4 failed with a hidden grade'
            ),
            'viewed' => new external_value(PARAM_BOOL, 'Whether the user has viewed the activity'),
            'timemodified' => new external_value(PARAM_INT, 'When the completion state last changed'),
        ]));
    }
}
