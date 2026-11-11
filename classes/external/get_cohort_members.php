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
use local_arcs\local\source\cohort_members;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_cohort_members.
 *
 * It takes two filters, cohortids and userids. Unlike other functions' filters, they can
 * be combined, because core names a membership change by its cohort and user, and a
 * reader follows such changes with both. Neither can be combined with ids.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_cohort_members extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters() + [
            'cohortids' => self::filter_parameter('Return only memberships of these cohorts'),
            'userids' => self::filter_parameter('Return only memberships of these users; can be combined with cohortids'),
        ]);
    }

    /**
     * Returns a page of cohort memberships.
     *
     * @param int $after Cursor: return memberships with a higher id.
     * @param int $limit Most memberships, or ids, to return.
     * @param int[] $ids Return only these memberships; after is then ignored.
     * @param int[] $cohortids Return only memberships of these cohorts.
     * @param int[] $userids Return only memberships of these users.
     * @return array
     */
    public static function execute(
        int $after = 0,
        int $limit = source::DEFAULT_LIMIT,
        array $ids = [],
        array $cohortids = [],
        array $userids = []
    ): array {
        global $DB;

        [
            'after' => $after,
            'limit' => $limit,
            'ids' => $ids,
            'cohortids' => $cohortids,
            'userids' => $userids,
        ] = self::validate_parameters(self::execute_parameters(), [
            'after' => $after,
            'limit' => $limit,
            'ids' => $ids,
            'cohortids' => $cohortids,
            'userids' => $userids,
        ]);
        $context = \core\context\system::instance();
        self::validate_context($context);
        require_capability('local/arcs:export', $context);

        $cohortids = self::check_filter($ids, $cohortids);
        $userids = self::check_filter($ids, $userids);
        $page = self::read(new cohort_members($DB, $cohortids, $userids), $after, $limit, $ids);
        return ['cohortmembers' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::paged_returns('cohortmembers', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Cohort membership id'),
            'cohortid' => new external_value(PARAM_INT, 'Cohort id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'timeadded' => new external_value(PARAM_INT, 'When the user was added to the cohort'),
        ]));
    }
}
