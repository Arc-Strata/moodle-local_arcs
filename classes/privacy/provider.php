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

namespace local_arcs\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_arcs.
 *
 * The change log (local_arcs_change) holds ids and times only. Its rows that name a
 * user are attached to that user's context.
 *
 * The web service functions send user data to Arcs, an external system, so it is
 * declared as an external location. The plugin keeps no copy of what it sends.
 *
 * Rows written by delete_user() after a privacy deletion (the user's own deletion and the
 * removal of their enrolments and grades) are kept on purpose, until the retention period
 * ends: they are how Arcs learns to delete its copy of the person.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string The change log table. */
    private const TABLE = 'local_arcs_change';

    /** @var string[] What the web service functions can send to Arcs about a user, optional fields included. */
    private const EXPORTED_USER_FIELDS = [
        'username',
        'idnumber',
        'email',
        'auth',
        'firstname',
        'lastname',
        'timezone',
        'lang',
        'department',
        'institution',
        'city',
        'country',
        'phone1',
        'phone2',
        'address',
        'middlename',
        'alternatename',
        'firstnamephonetic',
        'lastnamephonetic',
        'suspended',
        'deleted',
        'confirmed',
        'firstaccess',
        'lastaccess',
        'lastlogin',
        'timecreated',
        'timemodified',
        'profilefields',
        'userenrolments',
        'coursecompletions',
        'activitycompletions',
        'grades',
        'cohortmembers',
    ];

    /**
     * Describes the personal data the plugin stores.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(self::TABLE, [
            'entity' => 'privacy:metadata:local_arcs_change:entity',
            'entityid' => 'privacy:metadata:local_arcs_change:entityid',
            'op' => 'privacy:metadata:local_arcs_change:op',
            'userid' => 'privacy:metadata:local_arcs_change:userid',
            'courseid' => 'privacy:metadata:local_arcs_change:courseid',
            'timecreated' => 'privacy:metadata:local_arcs_change:timecreated',
        ], 'privacy:metadata:local_arcs_change');

        $fields = [];
        foreach (self::EXPORTED_USER_FIELDS as $field) {
            $fields[$field] = 'privacy:metadata:arcs:' . $field;
        }
        $collection->add_external_location_link('arcs', $fields, 'privacy:metadata:arcs');

        return $collection;
    }

    /**
     * Returns the contexts holding the user's data: their user context, if the change log names them.
     *
     * @param int $userid The user.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :contextlevel
                   AND ctx.instanceid = :userid
                   AND EXISTS (SELECT 1 FROM {" . self::TABLE . "} c WHERE c.userid = ctx.instanceid)";

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, ['contextlevel' => CONTEXT_USER, 'userid' => $userid]);
        return $contextlist;
    }

    /**
     * Adds the user whose context this is, if the change log names them.
     *
     * @param userlist $userlist The user list to add to.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \core\context\user) {
            return;
        }

        $userlist->add_from_sql('userid', "SELECT userid FROM {" . self::TABLE . "} WHERE userid = :userid", [
            'userid' => $context->instanceid,
        ]);
    }

    /**
     * Exports the change log rows that name the user.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \core\context\user || $context->instanceid != $userid) {
                continue;
            }

            $changes = [];
            $fields = 'id, entity, entityid, op, courseid, timecreated';
            $rs = $DB->get_recordset(self::TABLE, ['userid' => $userid], 'id ASC', $fields);
            foreach ($rs as $row) {
                $changes[] = (object) [
                    'entity' => $row->entity,
                    'entityid' => $row->entityid,
                    'op' => $row->op,
                    'courseid' => $row->courseid,
                    'timecreated' => transform::datetime($row->timecreated),
                ];
            }
            $rs->close();

            if ($changes) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:changes', 'local_arcs')],
                    (object) ['changes' => $changes]
                );
            }
        }
    }

    /**
     * Deletes every change log row naming the user whose context this is.
     *
     * @param \context $context The context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context instanceof \core\context\user) {
            $DB->delete_records(self::TABLE, ['userid' => $context->instanceid]);
        }
    }

    /**
     * Deletes the change log rows naming the user.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \core\context\user && $context->instanceid == $userid) {
                $DB->delete_records(self::TABLE, ['userid' => $userid]);
            }
        }
    }

    /**
     * Deletes the change log rows naming the approved users in this context.
     *
     * @param approved_userlist $userlist The approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \core\context\user || !in_array($context->instanceid, $userlist->get_userids())) {
            return;
        }

        $DB->delete_records(self::TABLE, ['userid' => $context->instanceid]);
    }
}
