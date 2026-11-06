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
use local_arcs\local\source\categories;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_categories.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_categories extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters() + [
            'categoryids' => self::filter_parameter('Return only these categories and the categories below them'),
        ]);
    }

    /**
     * Returns a page of categories.
     *
     * @param int $after Cursor: return categories with a higher id.
     * @param int $limit Most categories, or ids, to return.
     * @param int[] $ids Return only these categories; after is then ignored.
     * @param int[] $categoryids Return only these categories and those below them.
     * @return array
     */
    public static function execute(
        int $after = 0,
        int $limit = source::DEFAULT_LIMIT,
        array $ids = [],
        array $categoryids = []
    ): array {
        global $DB;

        ['after' => $after, 'limit' => $limit, 'ids' => $ids, 'categoryids' => $categoryids] = self::validate_parameters(
            self::execute_parameters(),
            ['after' => $after, 'limit' => $limit, 'ids' => $ids, 'categoryids' => $categoryids]
        );
        $context = \core\context\system::instance();
        self::validate_context($context);
        require_capability('local/arcs:export', $context);

        $categoryids = self::check_filter($ids, $categoryids);
        $page = self::read(new categories($DB, $categoryids), $after, $limit, $ids);
        return ['categories' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::paged_returns('categories', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Category id'),
            'name' => new external_value(PARAM_RAW, 'Name, as stored'),
            'idnumber' => new external_value(PARAM_RAW, 'ID number, or null', VALUE_REQUIRED, null, NULL_ALLOWED),
            'parent' => new external_value(PARAM_INT, 'Parent category id; 0 for a top-level category'),
            'path' => new external_value(PARAM_RAW, 'Ids from the top category down to this one, such as /3/12'),
            'visible' => new external_value(PARAM_BOOL, 'Whether the category is visible'),
            'timemodified' => new external_value(PARAM_INT, 'When the category was last changed'),
        ]));
    }
}
