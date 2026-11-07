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

namespace local_arcs\local;

/**
 * Reads the state of Moodle's standard log store (logstore_standard) and the bounds of its table.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logstore {
    /** @var string Component name of the standard log store. */
    private const COMPONENT = 'logstore_standard';

    /** @var string The standard log store's table. */
    public const TABLE = 'logstore_standard_log';

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        private \moodle_database $db,
    ) {
    }

    /**
     * Whether the standard log store is enabled, so that new events are written to it.
     *
     * @return bool
     */
    public function is_enabled(): bool {
        $stores = (string) get_config('tool_log', 'enabled_stores');
        return in_array(self::COMPONENT, explode(',', $stores), true);
    }

    /**
     * How many days the standard log store keeps rows before core deletes them.
     *
     * @return int Days; 0 means rows are kept forever.
     */
    public function lifetime_days(): int {
        return (int) get_config(self::COMPONENT, 'loglifetime');
    }

    /**
     * The lowest and highest ids currently in the standard log store's table.
     *
     * @return array With 'oldest' and 'newest', each null when the table is empty.
     */
    public function bounds(): array {
        $row = $this->db->get_record_sql('SELECT MIN(id) AS oldest, MAX(id) AS newest FROM {' . self::TABLE . '}');
        return [
            'oldest' => $row->oldest === null ? null : (int) $row->oldest,
            'newest' => $row->newest === null ? null : (int) $row->newest,
        ];
    }

    /**
     * A cursor from which reading misses no event that happened at or after a given time.
     *
     * Ids are not in time order, since the store buffers events before writing them, so the
     * cursor is just below the lowest id of any event at or after the time, not below the first
     * event by time. Events between the cursor and the time are read too.
     *
     * @param int $time Unix time.
     * @return int An id to read after; the highest id when no event is that recent, and 0 when the log is empty.
     */
    public function cursor_at(int $time): int {
        $lowest = $this->db->get_field_sql(
            'SELECT MIN(id) FROM {' . self::TABLE . '} WHERE timecreated >= :time',
            ['time' => $time]
        );
        if ($lowest === null || $lowest === false) {
            return $this->bounds()['newest'] ?? 0;
        }
        return (int) $lowest - 1;
    }
}
