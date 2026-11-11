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
use local_arcs\local\source\profile_fields;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_profile_fields.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_profile_fields extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters());
    }

    /**
     * Returns a page of custom profile field definitions.
     *
     * @param int $after Cursor: return fields with a higher id.
     * @param int $limit Most fields, or ids, to return.
     * @param int[] $ids Return only these fields; after is then ignored.
     * @return array
     */
    public static function execute(int $after = 0, int $limit = source::DEFAULT_LIMIT, array $ids = []): array {
        global $DB;

        ['after' => $after, 'limit' => $limit, 'ids' => $ids] = self::validate_parameters(
            self::execute_parameters(),
            ['after' => $after, 'limit' => $limit, 'ids' => $ids]
        );
        $context = \core\context\system::instance();
        self::validate_context($context);
        require_capability('local/arcs:export', $context);

        $page = self::read(new profile_fields($DB), $after, $limit, $ids);
        return ['profilefields' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::paged_returns('profilefields', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Profile field id'),
            'shortname' => new external_value(PARAM_RAW, 'Shortname, unique on the site'),
            'name' => new external_value(PARAM_RAW, 'Name, as stored'),
            'datatype' => new external_value(PARAM_RAW, 'Field type, such as text, menu, checkbox or datetime'),
            'categoryid' => new external_value(PARAM_INT, 'Profile field category id'),
            'visible' => new external_value(
                PARAM_INT,
                'Who can see it: 0 nobody, 1 the user, 2 everyone, 3 the user and their teachers'
            ),
        ]));
    }
}
