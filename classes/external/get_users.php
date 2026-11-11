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
use local_arcs\local\settings\settings;
use local_arcs\local\source\source;
use local_arcs\local\source\users;

/**
 * Web service function local_arcs_get_users.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_users extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters());
    }

    /**
     * Returns a page of users.
     *
     * @param int $after Cursor: return users with a higher id.
     * @param int $limit Most users, or ids, to return.
     * @param int[] $ids Return only these users; after is then ignored.
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

        $page = self::read(new users($DB, new settings()), $after, $limit, $ids);
        return ['users' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $optional = [];
        foreach (settings::OPTIONAL_USER_FIELDS as $field) {
            $optional[$field] = new external_value(PARAM_RAW, "The user's {$field}; present only when exported", VALUE_OPTIONAL);
        }

        return self::paged_returns('users', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'User id: the stable key'),
            'username' => new external_value(PARAM_RAW, 'Username'),
            'idnumber' => new external_value(PARAM_RAW, 'ID number'),
            'email' => new external_value(PARAM_RAW, 'Email address'),
            'auth' => new external_value(PARAM_RAW, 'Authentication method, such as manual or ldap'),
            'firstname' => new external_value(PARAM_RAW, 'First name'),
            'lastname' => new external_value(PARAM_RAW, 'Last name'),
            'timezone' => new external_value(PARAM_RAW, 'Timezone; 99 means the site default'),
            'lang' => new external_value(PARAM_RAW, 'Preferred language'),
        ] + $optional + [
            'suspended' => new external_value(PARAM_BOOL, 'Whether the account is suspended'),
            'deleted' => new external_value(PARAM_BOOL, 'Whether the user is deleted; Moodle keeps the row and scrubs it'),
            'confirmed' => new external_value(PARAM_BOOL, 'Whether the account is confirmed'),
            'firstaccess' => new external_value(PARAM_INT, 'First access to the site; 0 if never'),
            'lastaccess' => new external_value(PARAM_INT, 'Last access to the site; 0 if never'),
            'lastlogin' => new external_value(PARAM_INT, 'Previous login; 0 if none'),
            'timecreated' => new external_value(PARAM_INT, 'When the user was created'),
            'timemodified' => new external_value(PARAM_INT, 'When the user record was last changed'),
            'profilefields' => new external_multiple_structure(
                new external_single_structure([
                    'fieldid' => new external_value(PARAM_INT, 'Profile field id'),
                    'shortname' => new external_value(PARAM_RAW, 'Profile field shortname'),
                    'value' => new external_value(PARAM_RAW, 'Value, as stored'),
                ]),
                'Values of the exported custom profile fields that the user has a value for'
            ),
        ]));
    }
}
