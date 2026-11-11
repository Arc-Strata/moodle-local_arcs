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

namespace local_arcs\local\changelog;

/**
 * Writes rows to the change log.
 *
 * A change log row is only a hint for Arcs, so a failure to write one must never
 * break the user's action that caused it: failures are reported through debugging()
 * and otherwise ignored. Arcs's periodic full reconciliation recovers what is missed.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recorder {
    /** @var string The change log table. */
    public const TABLE = 'local_arcs_change';

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param \core\clock $clock The clock that dates each row.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        private \moodle_database $db,
        /** @var \core\clock The clock that dates each row. */
        private \core\clock $clock,
    ) {
    }

    /**
     * Records that a record changed.
     *
     * @param entity $entity The kind of record.
     * @param int $entityid The record's id in its Moodle table.
     * @param operation $op What happened to it.
     * @param int|null $userid The user the record belongs to, if any.
     * @param int|null $courseid The course the record belongs to, if any.
     */
    public function record(entity $entity, int $entityid, operation $op, ?int $userid = null, ?int $courseid = null): void {
        try {
            $this->db->insert_record(self::TABLE, (object) [
                'entity' => $entity->value,
                'entityid' => $entityid,
                'op' => $op->value,
                'userid' => $userid,
                'courseid' => $courseid,
                'timecreated' => $this->clock->time(),
            ], false);
        } catch (\dml_exception $e) {
            debugging("local_arcs could not record a change ({$entity->value} {$op->value}): {$e->errorcode}", DEBUG_DEVELOPER);
        }
    }
}
