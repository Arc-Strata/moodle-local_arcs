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
use core\exception\moodle_exception;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_arcs\local\changelog\reader;
use local_arcs\local\changelog\retention;

/**
 * Web service function local_arcs_get_changes.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_changes extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'after' => new external_value(PARAM_INT, 'Return changes with a higher id than this cursor', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Most changes to return', VALUE_DEFAULT, reader::DEFAULT_LIMIT),
        ]);
    }

    /**
     * Returns a page of the change log.
     *
     * @param int $after Cursor: return changes with a higher id.
     * @param int $limit Most changes to return.
     * @return array
     */
    public static function execute(int $after = 0, int $limit = reader::DEFAULT_LIMIT): array {
        global $DB;

        ['after' => $after, 'limit' => $limit] = self::validate_parameters(
            self::execute_parameters(),
            ['after' => $after, 'limit' => $limit]
        );
        $context = \core\context\system::instance();
        self::validate_context($context);
        require_capability('local/arcs:export', $context);

        if ($after < 0 || $limit < 1) {
            throw new invalid_parameter_exception('after must be 0 or more, and limit 1 or more');
        }
        if ($limit > reader::MAX_LIMIT) {
            throw new moodle_exception('limit_exceeded', 'local_arcs', '', reader::MAX_LIMIT);
        }

        $clock = \core\di::get(\core\clock::class);
        $reader = new reader($DB, $clock, new retention($DB, $clock));
        return $reader->page($after, $limit);
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'changes' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Change id: the sequence to page by'),
                    'entity' => new external_value(PARAM_ALPHANUMEXT, 'Kind of record, such as user or course'),
                    'entityid' => new external_value(PARAM_INT, 'Id of the record in its Moodle table'),
                    'op' => new external_value(PARAM_ALPHA, 'upsert or delete'),
                    'userid' => new external_value(PARAM_INT, 'User the record belongs to', VALUE_REQUIRED, null, NULL_ALLOWED),
                    'courseid' => new external_value(PARAM_INT, 'Course the record belongs to', VALUE_REQUIRED, null, NULL_ALLOWED),
                    'timecreated' => new external_value(PARAM_INT, 'When the change was recorded'),
                ])
            ),
            'next' => new external_value(PARAM_INT, 'Cursor to pass as after on the next call'),
            'hasmore' => new external_value(PARAM_BOOL, 'Whether more changes are ready now'),
            'highwatermark' => new external_value(PARAM_INT, 'Highest change id readers may read up to now'),
        ]);
    }
}
