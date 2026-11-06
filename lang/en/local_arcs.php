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
$string['optionalfields'] = 'Optional user fields to export';
$string['optionalfields_custom'] = '{$a} (custom profile field)';
$string['optionalfields_desc'] = 'The user fields Arcs may read besides those it always receives (identifiers, names, email, authentication method, timezone, language, account status and access times). Fields that are not selected are never sent. New custom profile fields are not sent until they are selected. Custom profile fields are selected by shortname, so a field whose shortname changes stops being sent until it is selected again.';
$string['pluginname'] = 'Arcs connector';
$string['privacy:changes'] = 'Changes recorded for Arcs';
$string['privacy:metadata:arcs'] = 'User data is sent to Arcs Learning Intelligence Platform when it requests it through the Arcs connector web service, which a site administrator sets up. Fields marked as optional, and custom profile field values, are sent only for the fields an administrator selects in the plugin\'s settings.';
$string['privacy:metadata:arcs:activitycompletions'] = 'The user\'s activity completions: each activity\'s completion state, whether they viewed it, and when the state changed.';
$string['privacy:metadata:arcs:address'] = 'The user\'s address (optional).';
$string['privacy:metadata:arcs:alternatename'] = 'The user\'s alternate name (optional).';
$string['privacy:metadata:arcs:auth'] = 'The user\'s authentication method.';
$string['privacy:metadata:arcs:city'] = 'The user\'s city or town (optional).';
$string['privacy:metadata:arcs:confirmed'] = 'Whether the user\'s account is confirmed.';
$string['privacy:metadata:arcs:country'] = 'The user\'s country (optional).';
$string['privacy:metadata:arcs:coursecompletions'] = 'The user\'s course completions: when they enrolled, started and completed each course.';
$string['privacy:metadata:arcs:deleted'] = 'Whether the user is deleted.';
$string['privacy:metadata:arcs:department'] = 'The user\'s department (optional).';
$string['privacy:metadata:arcs:email'] = 'The user\'s email address.';
$string['privacy:metadata:arcs:firstaccess'] = 'When the user first accessed the site.';
$string['privacy:metadata:arcs:firstname'] = 'The user\'s first name.';
$string['privacy:metadata:arcs:firstnamephonetic'] = 'The phonetic spelling of the user\'s first name (optional).';
$string['privacy:metadata:arcs:idnumber'] = 'The user\'s ID number.';
$string['privacy:metadata:arcs:institution'] = 'The user\'s institution (optional).';
$string['privacy:metadata:arcs:lang'] = 'The user\'s preferred language.';
$string['privacy:metadata:arcs:lastaccess'] = 'When the user last accessed the site.';
$string['privacy:metadata:arcs:lastlogin'] = 'When the user last logged in before their current login.';
$string['privacy:metadata:arcs:lastname'] = 'The user\'s last name.';
$string['privacy:metadata:arcs:lastnamephonetic'] = 'The phonetic spelling of the user\'s last name (optional).';
$string['privacy:metadata:arcs:middlename'] = 'The user\'s middle name (optional).';
$string['privacy:metadata:arcs:phone1'] = 'The user\'s phone number (optional).';
$string['privacy:metadata:arcs:phone2'] = 'The user\'s mobile phone number (optional).';
$string['privacy:metadata:arcs:profilefields'] = 'The user\'s values for the custom profile fields an administrator has selected (optional).';
$string['privacy:metadata:arcs:suspended'] = 'Whether the user\'s account is suspended.';
$string['privacy:metadata:arcs:timecreated'] = 'When the user\'s account was created.';
$string['privacy:metadata:arcs:timemodified'] = 'When the user\'s account was last changed.';
$string['privacy:metadata:arcs:timezone'] = 'The user\'s timezone.';
$string['privacy:metadata:arcs:userenrolments'] = 'The user\'s enrolments in courses: the course, the enrolment method, the enrolment\'s status and dates, and the roles the user has in the course.';
$string['privacy:metadata:arcs:username'] = 'The user\'s username.';
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
