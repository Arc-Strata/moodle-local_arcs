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
use local_arcs\local\source\courses;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_courses.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_courses extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters() + [
            'categoryids' => self::filter_parameter('Return only courses in these categories or the categories below them'),
        ]);
    }

    /**
     * Returns a page of courses.
     *
     * @param int $after Cursor: return courses with a higher id.
     * @param int $limit Most courses, or ids, to return.
     * @param int[] $ids Return only these courses; after is then ignored.
     * @param int[] $categoryids Return only courses in these categories or those below them.
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
        $page = self::read(new courses($DB, $categoryids), $after, $limit, $ids);
        return ['courses' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::paged_returns('courses', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Course id'),
            'category' => new external_value(PARAM_INT, 'Category id'),
            'shortname' => new external_value(PARAM_RAW, 'Short name, as stored'),
            'fullname' => new external_value(PARAM_RAW, 'Full name, as stored'),
            'idnumber' => new external_value(PARAM_RAW, 'ID number; empty if not set'),
            'format' => new external_value(PARAM_RAW, 'Course format, such as topics or weeks'),
            'visible' => new external_value(PARAM_BOOL, 'Whether the course is visible'),
            'startdate' => new external_value(PARAM_INT, 'Start date'),
            'enddate' => new external_value(PARAM_INT, 'End date; 0 if none'),
            'enablecompletion' => new external_value(PARAM_BOOL, 'Whether completion tracking is enabled'),
            'timecreated' => new external_value(PARAM_INT, 'When the course was created'),
            'timemodified' => new external_value(PARAM_INT, 'When the course was last changed'),
            'deletioninprogress' => new external_value(PARAM_BOOL, 'Whether the course is waiting to be deleted (Moodle 5.3+)'),
        ]));
    }
}
