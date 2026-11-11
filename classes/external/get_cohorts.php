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
use local_arcs\local\source\cohorts;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_cohorts.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_cohorts extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters());
    }

    /**
     * Returns a page of cohorts.
     *
     * @param int $after Cursor: return cohorts with a higher id.
     * @param int $limit Most cohorts, or ids, to return.
     * @param int[] $ids Return only these cohorts; after is then ignored.
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

        $page = self::read(new cohorts($DB), $after, $limit, $ids);
        return ['cohorts' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::paged_returns('cohorts', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Cohort id'),
            'contextid' => new external_value(PARAM_INT, 'The context the cohort belongs to'),
            'categoryid' => new external_value(
                PARAM_INT,
                'The course category the cohort belongs to; null for a site-wide cohort',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'name' => new external_value(PARAM_RAW, 'Name, raw'),
            'idnumber' => new external_value(PARAM_RAW, 'ID number', VALUE_REQUIRED, null, NULL_ALLOWED),
            'visible' => new external_value(PARAM_BOOL, 'Whether the cohort is visible'),
            'component' => new external_value(PARAM_RAW, 'The plugin that manages the cohort; empty if none'),
            'timecreated' => new external_value(PARAM_INT, 'When the cohort was created'),
            'timemodified' => new external_value(PARAM_INT, 'When the cohort was last changed'),
        ]));
    }
}
