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
use core_external\external_single_structure;
use core_external\external_value;
use local_arcs\local\logstore;

/**
 * Web service function local_arcs_get_log_cursor.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_log_cursor extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'time' => new external_value(PARAM_INT, 'Unix time from which no event is to be missed'),
        ]);
    }

    /**
     * Returns a cursor for local_arcs_get_log_events from which no event at or after a time is missed.
     *
     * @param int $time Unix time.
     * @return array
     */
    public static function execute(int $time): array {
        global $DB;

        ['time' => $time] = self::validate_parameters(self::execute_parameters(), ['time' => $time]);
        $context = \core\context\system::instance();
        self::validate_context($context);
        require_capability('local/arcs:export', $context);

        if ($time < 0) {
            throw new invalid_parameter_exception('time must be 0 or more');
        }
        $logstore = new logstore($DB);
        if (!$logstore->is_enabled()) {
            throw new moodle_exception('logstore_disabled', 'local_arcs');
        }

        return ['after' => $logstore->cursor_at($time)];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'after' => new external_value(PARAM_INT, 'Cursor to pass as after to local_arcs_get_log_events'),
        ]);
    }
}
