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
use local_arcs\local\logstore;
use local_arcs\local\source\log_events;
use local_arcs\local\source\source;

/**
 * Web service function local_arcs_get_log_events.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_log_events extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'after' => new external_value(PARAM_INT, 'Return events with a higher id than this cursor', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Most events to return', VALUE_DEFAULT, source::DEFAULT_LIMIT),
        ]);
    }

    /**
     * Returns a page of the standard log store's events.
     *
     * @param int $after Cursor: return events with a higher id.
     * @param int $limit Most events to return.
     * @return array
     */
    public static function execute(int $after = 0, int $limit = source::DEFAULT_LIMIT): array {
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
        if ($limit > source::MAX_LIMIT) {
            throw new moodle_exception('limit_exceeded', 'local_arcs', '', source::MAX_LIMIT);
        }

        $logstore = new logstore($DB);
        if (!$logstore->is_enabled()) {
            throw new moodle_exception('logstore_disabled', 'local_arcs');
        }
        // Moodle deletes old events by time; any id below the oldest kept one has been deleted or never existed.
        $bounds = $logstore->bounds();
        if ($after > 0 && $bounds['oldest'] !== null && $after < $bounds['oldest'] - 1) {
            throw new moodle_exception('cursor_expired', 'local_arcs');
        }

        $page = (new log_events($DB))->page($after, $limit);
        $lastid = $page['rows'] ? end($page['rows'])['id'] : $after;

        return [
            'events' => $page['rows'],
            'next' => $lastid,
            'hasmore' => $page['next'] !== null,
            'maxid' => $bounds['newest'] ?? 0,
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'events' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Event id: the sequence to page by'),
                    'eventname' => new external_value(PARAM_RAW, 'Event class name, such as \core\event\course_viewed'),
                    'component' => new external_value(PARAM_RAW, 'Component that triggered the event'),
                    'action' => new external_value(PARAM_RAW, 'What happened, such as viewed or created'),
                    'target' => new external_value(PARAM_RAW, 'What it happened to, such as course or course_module'),
                    'objecttable' => new external_value(PARAM_RAW, 'Table of the object', VALUE_REQUIRED, null, NULL_ALLOWED),
                    'objectid' => new external_value(PARAM_INT, 'Id of the object', VALUE_REQUIRED, null, NULL_ALLOWED),
                    'crud' => new external_value(PARAM_ALPHA, 'c, r, u or d: create, read, update or delete'),
                    'edulevel' => new external_value(PARAM_INT, '0 other, 1 teaching, 2 participating'),
                    'contextid' => new external_value(PARAM_INT, 'Context the event happened in'),
                    'contextlevel' => new external_value(PARAM_INT, 'Level of that context'),
                    'contextinstanceid' => new external_value(PARAM_INT, 'Instance id of that context'),
                    'userid' => new external_value(PARAM_INT, 'User who acted', VALUE_REQUIRED, null, NULL_ALLOWED),
                    'courseid' => new external_value(PARAM_INT, 'Course of the event', VALUE_REQUIRED, null, NULL_ALLOWED),
                    'relateduserid' => new external_value(PARAM_INT, 'User it concerns', VALUE_REQUIRED, null, NULL_ALLOWED),
                    'anonymous' => new external_value(PARAM_BOOL, 'Whether the event is anonymous'),
                    'timecreated' => new external_value(PARAM_INT, 'When the event happened'),
                    'origin' => new external_value(PARAM_RAW, 'web, ws, cli or restore', VALUE_REQUIRED, null, NULL_ALLOWED),
                ])
            ),
            'next' => new external_value(PARAM_INT, 'Cursor to pass as after on the next call'),
            'hasmore' => new external_value(PARAM_BOOL, 'Whether more events are ready now'),
            'maxid' => new external_value(PARAM_INT, 'Highest event id when the call started; 0 when there are none'),
        ]);
    }
}
