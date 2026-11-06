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

/**
 * English language strings for local_arcs.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['arcs:export'] = 'Export site data to Arcs';
$string['cursor_expired'] = 'The cursor is older than the change log keeps. Read every entity again, then follow changes from the current high-water mark.';
$string['limit_exceeded'] = 'The limit, or the number of ids, is above the maximum of {$a}.';
$string['pluginname'] = 'Arcs connector';
$string['privacy:changes'] = 'Changes recorded for Arcs';
$string['privacy:metadata:local_arcs_change'] = 'A log of which records changed, by id only, so that Arcs can fetch their current state. Rows are deleted after the retention period. When a user is deleted, the rows recording the deletion of the user, their enrolments and their grades are written after their personal data is deleted and kept until the retention period ends, so that Arcs deletes its copy of the person.';
$string['privacy:metadata:local_arcs_change:courseid'] = 'The course the changed record belongs to.';
$string['privacy:metadata:local_arcs_change:entity'] = 'The kind of record that changed.';
$string['privacy:metadata:local_arcs_change:entityid'] = 'The id of the record that changed.';
$string['privacy:metadata:local_arcs_change:op'] = 'Whether the record was created or changed, or deleted.';
$string['privacy:metadata:local_arcs_change:timecreated'] = 'When the change was recorded.';
$string['privacy:metadata:local_arcs_change:userid'] = 'The user the changed record belongs to.';
$string['retentiondays'] = 'Change log retention';
$string['retentiondays_desc'] = 'How long to keep change log entries. Arcs must read the change log more often than this; if it falls further behind, it has to read every entity again.';
$string['task_cleanup_changes'] = 'Delete old change log entries';
