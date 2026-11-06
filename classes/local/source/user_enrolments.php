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

/**
 * Reads user enrolments (the user_enrolments table), with their enrolment method and the user's roles in the course.
 *
 * Roles are those assigned to the user directly in the course's context, whatever
 * assigned them (manually or by an enrolment method). Roles assigned in a category or
 * the system context, and inherited roles, are not included.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_enrolments extends source {
    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int[] $courseids Only read enrolments in these courses; empty for all.
     */
    public function __construct(
        \moodle_database $db,
        /** @var int[] Only read enrolments in these courses; empty for all. */
        private array $courseids = [],
    ) {
        parent::__construct($db);
    }

    /**
     * The columns to select.
     *
     * @return string
     */
    protected function fields(): string {
        return 'ue.id, ue.enrolid, ue.userid, ue.status, ue.timestart, ue.timeend, ue.timecreated, ue.timemodified, ' .
            'e.courseid, e.enrol, e.status AS enrolstatus';
    }

    /**
     * The tables to read from.
     *
     * @return string
     */
    protected function from(): string {
        return '{user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid';
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 'ue.id';
    }

    /**
     * Only the requested courses' enrolments, when the reader asked for some.
     *
     * @return array [string $sql, array $params]
     */
    protected function filter(): array {
        if (!$this->courseids) {
            return ['', []];
        }
        [$insql, $params] = $this->db->get_in_or_equal($this->courseids, SQL_PARAMS_NAMED, 'course');
        return ['e.courseid ' . $insql, $params];
    }

    /**
     * Shapes a page of user enrolments, adding the users' roles in each course.
     *
     * @param \stdClass[] $records The user enrolments.
     * @return array[]
     */
    protected function shape(array $records): array {
        $roles = $this->roles($records);

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => (int) $record->id,
                'enrolid' => (int) $record->enrolid,
                'userid' => (int) $record->userid,
                'courseid' => (int) $record->courseid,
                'enrol' => $record->enrol,
                'enrolstatus' => (int) $record->enrolstatus,
                'status' => (int) $record->status,
                'timestart' => (int) $record->timestart,
                'timeend' => (int) $record->timeend,
                'timecreated' => (int) $record->timecreated,
                'timemodified' => (int) $record->timemodified,
                'roles' => array_values($roles[$record->courseid][$record->userid] ?? []),
            ];
        }
        return $rows;
    }

    /**
     * Reads the roles of a page's users in the page's courses, in one query.
     *
     * @param \stdClass[] $records The user enrolments.
     * @return array[] Roles (roleid, shortname), keyed by course id, user id and role id, in role order.
     */
    private function roles(array $records): array {
        if (!$records) {
            return [];
        }

        [$coursesql, $params] = $this->db->get_in_or_equal(
            array_unique(array_column($records, 'courseid')),
            SQL_PARAMS_NAMED,
            'course'
        );
        [$usersql, $userparams] = $this->db->get_in_or_equal(
            array_unique(array_column($records, 'userid')),
            SQL_PARAMS_NAMED,
            'user'
        );
        $sql = "SELECT ra.id, ctx.instanceid AS courseid, ra.userid, r.id AS roleid, r.shortname
                  FROM {role_assignments} ra
                  JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :courselevel
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE ctx.instanceid $coursesql
                   AND ra.userid $usersql
              ORDER BY r.sortorder, ra.id";
        $params += $userparams + ['courselevel' => CONTEXT_COURSE];

        $roles = [];
        $rs = $this->db->get_recordset_sql($sql, $params);
        foreach ($rs as $record) {
            // A role assigned twice (say, manually and by an enrolment method) is listed once.
            $roles[$record->courseid][$record->userid][$record->roleid] = [
                'roleid' => (int) $record->roleid,
                'shortname' => $record->shortname,
            ];
        }
        $rs->close();
        return $roles;
    }
}
