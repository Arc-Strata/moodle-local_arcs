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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_arcs\local\source\user_enrolments;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_user_enrolments.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user_enrolments extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters() + [
            'courseids' => self::filter_parameter('Return only user enrolments in these courses'),
            'enrolids' => self::filter_parameter('Return only user enrolments through these enrolment method instances'),
        ]);
    }

    /**
     * Returns a page of user enrolments.
     *
     * @param int $after Cursor: return user enrolments with a higher id.
     * @param int $limit Most user enrolments, or ids, to return.
     * @param int[] $ids Return only these user enrolments; after is then ignored.
     * @param int[] $courseids Return only user enrolments in these courses.
     * @param int[] $enrolids Return only user enrolments through these enrolment method instances.
     * @return array
     */
    public static function execute(
        int $after = 0,
        int $limit = source::DEFAULT_LIMIT,
        array $ids = [],
        array $courseids = [],
        array $enrolids = []
    ): array {
        global $DB;

        [
            'after' => $after,
            'limit' => $limit,
            'ids' => $ids,
            'courseids' => $courseids,
            'enrolids' => $enrolids,
        ] = self::validate_parameters(self::execute_parameters(), [
            'after' => $after,
            'limit' => $limit,
            'ids' => $ids,
            'courseids' => $courseids,
            'enrolids' => $enrolids,
        ]);
        $context = \core\context\system::instance();
        self::validate_context($context);
        require_capability('local/arcs:export', $context);

        if ($courseids && $enrolids) {
            throw new invalid_parameter_exception('courseids and enrolids cannot be combined');
        }
        $courseids = self::check_filter($ids, $courseids);
        $enrolids = self::check_filter($ids, $enrolids);
        $page = self::read(new user_enrolments($DB, $courseids, $enrolids), $after, $limit, $ids);
        return ['userenrolments' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::paged_returns('userenrolments', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'User enrolment id'),
            'enrolid' => new external_value(PARAM_INT, 'Enrolment method instance id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'enrol' => new external_value(PARAM_RAW, 'Enrolment method, such as manual, self or cohort'),
            'enrolstatus' => new external_value(PARAM_INT, 'Enrolment method instance status: 0 enabled, 1 disabled'),
            'status' => new external_value(PARAM_INT, 'User enrolment status: 0 active, 1 suspended'),
            'timestart' => new external_value(PARAM_INT, 'When the enrolment starts; 0 if not limited'),
            'timeend' => new external_value(PARAM_INT, 'When the enrolment ends; 0 if not limited'),
            'timecreated' => new external_value(PARAM_INT, 'When the enrolment was created'),
            'timemodified' => new external_value(PARAM_INT, 'When the enrolment was last changed'),
            'roles' => new external_multiple_structure(
                new external_single_structure([
                    'roleid' => new external_value(PARAM_INT, 'Role id'),
                    'shortname' => new external_value(PARAM_RAW, 'Role shortname, such as student or editingteacher'),
                    'archetype' => new external_value(PARAM_ALPHANUMEXT, "The role's archetype, such as student; empty if none"),
                ]),
                "The user's roles assigned directly in the course"
            ),
        ]));
    }
}
