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

namespace local_arcs\local\source;

use local_arcs\local\logstore;

/**
 * Reads events from the standard log store (the logstore_standard_log table).
 *
 * The event's other data, the IP address and the real user of a "log in as" session
 * are never read. For an anonymous event, neither the user who acted nor the related
 * user is returned: Moodle puts the anonymous party in either.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class log_events extends source {
    /**
     * The columns to select.
     *
     * @return string
     */
    protected function fields(): string {
        return 'l.id, l.eventname, l.component, l.action, l.target, l.objecttable, l.objectid, l.crud, l.edulevel, ' .
            'l.contextid, l.contextlevel, l.contextinstanceid, l.userid, l.courseid, l.relateduserid, l.anonymous, ' .
            'l.timecreated, l.origin';
    }

    /**
     * The table to read from.
     *
     * @return string
     */
    protected function from(): string {
        return '{' . logstore::TABLE . '} l';
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 'l.id';
    }

    /**
     * Shapes a page of log events.
     *
     * @param \stdClass[] $records The events.
     * @return array[]
     */
    protected function shape(array $records): array {
        $rows = [];
        foreach ($records as $record) {
            $anonymous = (bool) $record->anonymous;
            $rows[] = [
                'id' => (int) $record->id,
                'eventname' => $record->eventname,
                'component' => $record->component,
                'action' => $record->action,
                'target' => $record->target,
                'objecttable' => $record->objecttable,
                'objectid' => $record->objectid === null ? null : (int) $record->objectid,
                'crud' => $record->crud,
                'edulevel' => (int) $record->edulevel,
                'contextid' => (int) $record->contextid,
                'contextlevel' => (int) $record->contextlevel,
                'contextinstanceid' => (int) $record->contextinstanceid,
                'userid' => $anonymous ? null : (int) $record->userid,
                'courseid' => $record->courseid === null ? null : (int) $record->courseid,
                'relateduserid' => $anonymous || $record->relateduserid === null ? null : (int) $record->relateduserid,
                'anonymous' => $anonymous,
                'timecreated' => (int) $record->timecreated,
                'origin' => $record->origin,
            ];
        }
        return $rows;
    }
}
