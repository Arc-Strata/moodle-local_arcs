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

use core\exception\moodle_exception;

/**
 * Pages through the change log for readers.
 *
 * Rows are only returned up to the high-water mark: the highest id recorded at least
 * SETTLE_SECONDS ago. Ids are allocated before rows commit, so a lower id can appear
 * after a higher one under concurrency; no row below the mark is still in flight.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reader {
    /** @var int Seconds a row must have existed before readers see it. */
    public const SETTLE_SECONDS = 60;

    /** @var int Rows per page when the reader does not say. */
    public const DEFAULT_LIMIT = 1000;

    /** @var int Most rows a reader may ask for in one page. */
    public const MAX_LIMIT = 1000;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param \core\clock $clock The clock that decides which rows have settled.
     * @param retention $retention Knows how far old rows have been deleted.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        private \moodle_database $db,
        /** @var \core\clock The clock that decides which rows have settled. */
        private \core\clock $clock,
        /** @var retention Knows how far old rows have been deleted. */
        private retention $retention,
    ) {
    }

    /**
     * Returns the rows after a cursor, up to the high-water mark.
     *
     * @param int $after Cursor: return rows with a higher id. Must be 0 or more.
     * @param int $limit Most rows to return, from 1 to MAX_LIMIT.
     * @return array With 'changes' (rows), 'next' (the cursor for the next call),
     *     'hasmore' (whether more rows are ready now) and 'highwatermark'.
     * @throws moodle_exception cursor_expired, when rows after the cursor were deleted for age.
     */
    public function page(int $after, int $limit): array {
        if ($after < $this->retention->purged_up_to()) {
            throw new moodle_exception('cursor_expired', 'local_arcs');
        }

        $mark = $this->high_water_mark();
        $changes = [];
        $rs = $this->db->get_recordset_select(
            recorder::TABLE,
            'id > :after AND id <= :mark',
            ['after' => $after, 'mark' => $mark],
            'id ASC',
            'id, entity, entityid, op, userid, courseid, timecreated',
            0,
            $limit
        );
        foreach ($rs as $row) {
            $changes[] = [
                'id' => (int) $row->id,
                'entity' => $row->entity,
                'entityid' => (int) $row->entityid,
                'op' => $row->op,
                'userid' => $row->userid === null ? null : (int) $row->userid,
                'courseid' => $row->courseid === null ? null : (int) $row->courseid,
                'timecreated' => (int) $row->timecreated,
            ];
        }
        $rs->close();

        $lastid = $changes ? end($changes)['id'] : $after;
        $hasmore = count($changes) === $limit && $lastid < $mark;

        return [
            'changes' => $changes,
            'next' => $hasmore ? $lastid : max($after, $mark),
            'hasmore' => $hasmore,
            'highwatermark' => $mark,
        ];
    }

    /**
     * The highest id a reader may read up to now.
     *
     * Never lower than the purged-up-to mark, so a reader that starts from it is never expired.
     *
     * @return int
     */
    public function high_water_mark(): int {
        $settled = (int) $this->db->get_field_select(
            recorder::TABLE,
            'MAX(id)',
            'timecreated <= :settled',
            ['settled' => $this->clock->time() - self::SETTLE_SECONDS]
        );
        return max($settled, $this->retention->purged_up_to());
    }

    /**
     * The lowest and highest ids currently in the log.
     *
     * @return array With 'oldest' and 'newest', each null when the log is empty.
     */
    public function bounds(): array {
        $row = $this->db->get_record_sql('SELECT MIN(id) AS oldest, MAX(id) AS newest FROM {' . recorder::TABLE . '}');
        return [
            'oldest' => $row->oldest === null ? null : (int) $row->oldest,
            'newest' => $row->newest === null ? null : (int) $row->newest,
        ];
    }
}
