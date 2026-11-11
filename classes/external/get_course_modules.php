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
use local_arcs\local\source\course_modules;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_course_modules.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_modules extends state_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::paging_parameters() + [
            'courseids' => self::filter_parameter('Return only course modules in these courses'),
        ]);
    }

    /**
     * Returns a page of course modules.
     *
     * @param int $after Cursor: return course modules with a higher id.
     * @param int $limit Most course modules, or ids, to return.
     * @param int[] $ids Return only these course modules; after is then ignored.
     * @param int[] $courseids Return only course modules in these courses.
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
        $page = self::read(new course_modules($DB, $courseids), $after, $limit, $ids);
        return ['coursemodules' => $page['rows'], 'next' => $page['next']];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::paged_returns('coursemodules', new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Course module id'),
            'course' => new external_value(PARAM_INT, 'Course id'),
            'modname' => new external_value(PARAM_RAW, 'Module type, such as quiz or scorm'),
            'instance' => new external_value(PARAM_INT, "Instance id in the module type's own table"),
            'instancename' => new external_value(
                PARAM_RAW,
                'Instance name, as stored; null if the module type is not installed',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'section' => new external_value(PARAM_INT, 'Course section id'),
            'idnumber' => new external_value(PARAM_RAW, 'ID number, or null', VALUE_REQUIRED, null, NULL_ALLOWED),
            'visible' => new external_value(PARAM_BOOL, 'Whether the module is visible'),
            'completion' => new external_value(PARAM_INT, 'Completion tracking: 0 none, 1 manual, 2 automatic'),
            'completionexpected' => new external_value(PARAM_INT, 'Date completion is expected by; 0 if none'),
            'completiongradeitemnumber' => new external_value(
                PARAM_INT,
                'Grade item number that completion requires a grade on, or null',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'completionview' => new external_value(PARAM_BOOL, 'Whether completion requires viewing'),
            'added' => new external_value(PARAM_INT, 'When the module was added'),
            'deletioninprogress' => new external_value(PARAM_BOOL, 'Whether the module is waiting to be deleted'),
        ]));
    }
}
