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

namespace local_arcs\tests;

use local_arcs\local\changelog\recorder;

/**
 * Test helper: empties and reads the change log, for tests of the observers.
 *
 * Observer tests must call preventResetByRollback() in setUp: the observers are external,
 * so they run only once no transaction is open, and on PostgreSQL PHPUnit runs each test
 * inside one.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait changes_trait {
    /**
     * Empties the change log, so a test sees only the rows its own action records.
     */
    protected function clear_changes(): void {
        global $DB;
        $DB->delete_records(recorder::TABLE);
    }

    /**
     * The change log rows, oldest first, without ids and times.
     *
     * @return array[] Each with entity, entityid, op, userid and courseid.
     */
    protected function changes(): array {
        global $DB;
        $rows = $DB->get_records(recorder::TABLE, null, 'id ASC', 'id, entity, entityid, op, userid, courseid');
        return array_values(array_map(fn(\stdClass $row): array => [
            'entity' => $row->entity,
            'entityid' => (int) $row->entityid,
            'op' => $row->op,
            'userid' => $row->userid === null ? null : (int) $row->userid,
            'courseid' => $row->courseid === null ? null : (int) $row->courseid,
        ], $rows));
    }

    /**
     * A change row, as changes() returns it.
     *
     * @param string $entity The entity.
     * @param int $entityid The record's id.
     * @param string $op upsert or delete.
     * @param int|null $userid The user, if any.
     * @param int|null $courseid The course, if any.
     * @return array
     */
    protected function change(string $entity, int $entityid, string $op, ?int $userid = null, ?int $courseid = null): array {
        return ['entity' => $entity, 'entityid' => $entityid, 'op' => $op, 'userid' => $userid, 'courseid' => $courseid];
    }
}
