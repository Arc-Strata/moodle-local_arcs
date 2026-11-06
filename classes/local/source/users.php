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

use local_arcs\local\settings\settings;

/**
 * Reads users (the user table), with the custom profile field values an admin has chosen to export.
 *
 * Only the columns named here are read: never passwords, secrets, IP addresses or
 * session data. Optional fields are read only when an admin has chosen to export them.
 * Deleted users are included, as Moodle keeps their scrubbed rows.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class users extends source {
    /** @var string[] Text columns always exported. */
    private const TEXT_FIELDS = ['username', 'idnumber', 'email', 'auth', 'firstname', 'lastname', 'timezone', 'lang'];

    /** @var string[] Flag columns always exported, as booleans. */
    private const FLAG_FIELDS = ['suspended', 'deleted', 'confirmed'];

    /** @var string[] Time columns always exported. */
    private const TIME_FIELDS = ['firstaccess', 'lastaccess', 'lastlogin', 'timecreated', 'timemodified'];

    /** @var string[] The optional user table columns to export, from settings::OPTIONAL_USER_FIELDS. */
    private array $optionalfields;

    /** @var string[] The shortnames of the custom profile fields to export. */
    private array $profilefields;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param settings $settings The plugin's settings, which say which optional fields to export.
     */
    public function __construct(\moodle_database $db, settings $settings) {
        parent::__construct($db);
        $this->optionalfields = $settings->optional_user_fields();
        $this->profilefields = $settings->profile_field_shortnames();
    }

    /**
     * The columns to select.
     *
     * @return string
     */
    protected function fields(): string {
        $columns = array_merge(['id'], self::TEXT_FIELDS, $this->optionalfields, self::FLAG_FIELDS, self::TIME_FIELDS);
        return implode(', ', array_map(fn(string $column): string => 'u.' . $column, $columns));
    }

    /**
     * The table to read from.
     *
     * @return string
     */
    protected function from(): string {
        return '{user} u';
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 'u.id';
    }

    /**
     * Shapes a page of users, adding their custom profile field values.
     *
     * @param \stdClass[] $records The user records.
     * @return array[]
     */
    protected function shape(array $records): array {
        $values = $this->profile_values(array_column($records, 'id'));

        $rows = [];
        foreach ($records as $record) {
            $row = ['id' => (int) $record->id];
            foreach (array_merge(self::TEXT_FIELDS, $this->optionalfields) as $field) {
                $row[$field] = (string) $record->$field;
            }
            foreach (self::FLAG_FIELDS as $field) {
                $row[$field] = (bool) $record->$field;
            }
            foreach (self::TIME_FIELDS as $field) {
                $row[$field] = (int) $record->$field;
            }
            $row['profilefields'] = $values[$record->id] ?? [];
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Reads the exported custom profile field values of the given users, in one query.
     *
     * @param int[] $userids The users.
     * @return array[] Lists of values (fieldid, shortname, value), keyed by user id.
     */
    private function profile_values(array $userids): array {
        if (!$userids || !$this->profilefields) {
            return [];
        }

        [$usersql, $params] = $this->db->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'user');
        [$fieldsql, $fieldparams] = $this->db->get_in_or_equal($this->profilefields, SQL_PARAMS_NAMED, 'field');
        $sql = "SELECT d.id, d.userid, d.fieldid, f.shortname, d.data
                  FROM {user_info_data} d
                  JOIN {user_info_field} f ON f.id = d.fieldid
                 WHERE d.userid $usersql
                   AND f.shortname $fieldsql
              ORDER BY d.userid, d.fieldid";

        $values = [];
        $rs = $this->db->get_recordset_sql($sql, $params + $fieldparams);
        foreach ($rs as $record) {
            $values[$record->userid][] = [
                'fieldid' => (int) $record->fieldid,
                'shortname' => $record->shortname,
                'value' => $record->data,
            ];
        }
        $rs->close();
        return $values;
    }
}
