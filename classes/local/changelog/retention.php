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
 * Deletes change log rows past their retention period, and remembers how far it has deleted.
 *
 * Deletion always removes a contiguous prefix of the log: every row with an id up to
 * some mark. That mark ("purged up to") is what makes a reader's cursor expired, so a
 * cursor is never reported expired because of an ordinary gap in the id sequence.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class retention {
    /** @var string Plugin config key holding the highest id deleted for age. */
    private const PURGED_UP_TO = 'purgedupto';

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param \core\clock $clock The clock that decides which rows are old.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        private \moodle_database $db,
        /** @var \core\clock The clock that decides which rows are old. */
        private \core\clock $clock,
    ) {
    }

    /**
     * The highest id deleted for age; every row up to it is gone. 0 when nothing has been deleted.
     *
     * @return int
     */
    public function purged_up_to(): int {
        return (int) get_config('local_arcs', self::PURGED_UP_TO);
    }

    /**
     * Deletes every row up to the newest one recorded more than the given number of days ago.
     *
     * @param int $days Retention period in days.
     */
    public function purge(int $days): void {
        $cutoff = $this->clock->time() - $days * DAYSECS;
        $maxid = (int) $this->db->get_field_select(recorder::TABLE, 'MAX(id)', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
        if ($maxid <= $this->purged_up_to()) {
            return;
        }

        // Record the mark before deleting: if the delete fails, readers are told to resync
        // (safe); the other order could lose rows without telling them.
        set_config(self::PURGED_UP_TO, $maxid, 'local_arcs');
        $this->db->delete_records_select(recorder::TABLE, 'id <= :maxid', ['maxid' => $maxid]);
    }
}
