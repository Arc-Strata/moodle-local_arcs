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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_arcs\local\source\grade_items;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_grade_items.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_grade_items extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters() + [
            'courseids' => self::filter_parameter('Return only grade items in these courses'),
        ]);
    }

    /**
     * Returns a page of grade items.
     *
     * @param int $after Cursor: return grade items with a higher id.
     * @param int $limit Most grade items, or ids, to return.
     * @param int[] $ids Return only these grade items; after is then ignored.
     * @param int[] $courseids Return only grade items in these courses.
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
        $page = self::read(new grade_items($DB, $courseids), $after, $limit, $ids);
        return ['gradeitems' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::paged_returns('gradeitems', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Grade item id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'itemtype' => new external_value(PARAM_ALPHANUMEXT, 'Item type: course, category, mod or manual'),
            'itemmodule' => new external_value(
                PARAM_RAW,
                'Module type of an activity\'s item, such as quiz; null otherwise',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'iteminstance' => new external_value(
                PARAM_INT,
                'Module instance id of an activity\'s item, grade category id of a course or category item; null otherwise',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'itemnumber' => new external_value(
                PARAM_INT,
                'Which of an activity\'s grade items this is, usually 0',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'coursemoduleid' => new external_value(
                PARAM_INT,
                'Course module id of an activity\'s item; null for other items, or when the module is gone',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'itemname' => new external_value(
                PARAM_RAW,
                'Item name, raw; null for the course total',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'idnumber' => new external_value(PARAM_RAW, 'ID number', VALUE_REQUIRED, null, NULL_ALLOWED),
            'gradetype' => new external_value(PARAM_INT, 'Grade type: 0 none, 1 value, 2 scale, 3 text'),
            'grademin' => new external_value(PARAM_FLOAT, 'Lowest grade'),
            'grademax' => new external_value(PARAM_FLOAT, 'Highest grade'),
            'gradepass' => new external_value(PARAM_FLOAT, 'Grade needed to pass; 0 if none'),
            'scaleid' => new external_value(
                PARAM_INT,
                'Scale id, for a scale item; null otherwise',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'scaleitems' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Scale item, raw'),
                'The scale\'s items, lowest first, for a scale item; empty otherwise. Grade 1 is the first item.'
            ),
            'hidden' => new external_value(PARAM_INT, 'Hidden: 0 visible, 1 hidden, or a time until which it is hidden'),
            'needsupdate' => new external_value(PARAM_BOOL, 'Whether Moodle has still to recalculate the item\'s grades'),
            'timemodified' => new external_value(
                PARAM_INT,
                'When the item was last changed',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
        ]));
    }
}
