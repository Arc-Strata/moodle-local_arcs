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

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_arcs\local\source\course_completions;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_course_completions.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_completions extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters() + [
            'courseids' => self::filter_parameter('Return only course completions in these courses'),
        ]);
    }

    /**
     * Returns a page of course completions.
     *
     * @param int $after Cursor: return course completions with a higher id.
     * @param int $limit Most course completions, or ids, to return.
     * @param int[] $ids Return only these course completions; after is then ignored.
     * @param int[] $courseids Return only course completions in these courses.
     * @return array
     */
    public static function execute(
        int $after = 0,
        int $limit = source::DEFAULT_LIMIT,
        array $ids = [],
        array $courseids = []
    ): array {
        global $DB;

        ['after' => $after, 'limit' => $limit, 'ids' => $ids, 'courseids' => $courseids] = self::validate_parameters(
            self::execute_parameters(),
            ['after' => $after, 'limit' => $limit, 'ids' => $ids, 'courseids' => $courseids]
        );
        $context = \core\context\system::instance();
        self::validate_context($context);
        require_capability('local/arcs:export', $context);

        $courseids = self::check_filter($ids, $courseids);
        $page = self::read(new course_completions($DB, $courseids), $after, $limit, $ids);
        return ['coursecompletions' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::paged_returns('coursecompletions', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Course completion id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'course' => new external_value(PARAM_INT, 'Course id'),
            'timeenrolled' => new external_value(PARAM_INT, 'When completion tracking saw the user enrol; 0 if unknown'),
            'timestarted' => new external_value(PARAM_INT, 'When the user started the course; 0 if not started'),
            'timecompleted' => new external_value(
                PARAM_INT,
                'When the user completed the course; null if not complete',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'reaggregate' => new external_value(PARAM_INT, 'When completion is due to be recalculated; 0 if not due'),
        ]));
    }
}
