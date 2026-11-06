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
use local_arcs\local\source\grades;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_grades.
 *
 * It takes two filters, courseids and itemids, which cannot be combined with each other
 * or with ids.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_grades extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters() + [
            'courseids' => self::filter_parameter('Return only grades in these courses'),
            'itemids' => self::filter_parameter('Return only grades of these grade items'),
        ]);
    }

    /**
     * Returns a page of grades.
     *
     * @param int $after Cursor: return grades with a higher id.
     * @param int $limit Most grades, or ids, to return.
     * @param int[] $ids Return only these grades; after is then ignored.
     * @param int[] $courseids Return only grades in these courses.
     * @param int[] $itemids Return only grades of these grade items.
     * @return array
     */
    public static function execute(
        int $after = 0,
        int $limit = source::DEFAULT_LIMIT,
        array $ids = [],
        array $courseids = [],
        array $itemids = []
    ): array {
        global $DB;

        [
            'after' => $after,
            'limit' => $limit,
            'ids' => $ids,
            'courseids' => $courseids,
            'itemids' => $itemids,
        ] = self::validate_parameters(self::execute_parameters(), [
            'after' => $after,
            'limit' => $limit,
            'ids' => $ids,
            'courseids' => $courseids,
            'itemids' => $itemids,
        ]);
        $context = \core\context\system::instance();
        self::validate_context($context);
        require_capability('local/arcs:export', $context);

        if ($courseids && $itemids) {
            throw new invalid_parameter_exception('courseids and itemids cannot be combined');
        }
        $courseids = self::check_filter($ids, $courseids);
        $itemids = self::check_filter($ids, $itemids);
        $page = self::read(new grades($DB, $courseids, $itemids), $after, $limit, $ids);
        return ['grades' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::paged_returns('grades', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Grade id'),
            'itemid' => new external_value(PARAM_INT, 'Grade item id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'finalgrade' => new external_value(
                PARAM_FLOAT,
                'The grade shown in the gradebook; null if not graded',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'rawgrade' => new external_value(
                PARAM_FLOAT,
                'The grade the activity sent, on its own scale; null if none',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'rawgrademin' => new external_value(PARAM_FLOAT, 'Lowest grade of the activity\'s scale for rawgrade'),
            'rawgrademax' => new external_value(PARAM_FLOAT, 'Highest grade of the activity\'s scale for rawgrade'),
            'hidden' => new external_value(PARAM_INT, 'Hidden: 0 visible, 1 hidden, or a time until which it is hidden'),
            'overridden' => new external_value(PARAM_INT, 'When the grade was overridden in the gradebook; 0 if not'),
            'timemodified' => new external_value(
                PARAM_INT,
                'When the user was graded; null if not graded yet',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
        ]));
    }
}
