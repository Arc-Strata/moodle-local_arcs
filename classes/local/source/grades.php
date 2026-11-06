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
 * Reads users' grades (the grade_grades table).
 *
 * Grades of grade items in the site course are left out, as those items are. Feedback is
 * free text that can hold anything, so it is never read.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grades extends source {
    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int[] $courseids Only read grades in these courses; empty for all.
     * @param int[] $itemids Only read grades of these grade items; empty for all.
     */
    public function __construct(
        \moodle_database $db,
        /** @var int[] Only read grades in these courses; empty for all. */
        private array $courseids = [],
        /** @var int[] Only read grades of these grade items; empty for all. */
        private array $itemids = [],
    ) {
        parent::__construct($db);
    }

    /**
     * The columns to select.
     *
     * @return string
     */
    protected function fields(): string {
        return 'gg.id, gg.itemid, gg.userid, gg.finalgrade, gg.rawgrade, gg.rawgrademin, gg.rawgrademax, ' .
            'gg.hidden, gg.overridden, gg.timemodified';
    }

    /**
     * The tables to read from.
     *
     * @return string
     */
    protected function from(): string {
        return '{grade_grades} gg JOIN {grade_items} gi ON gi.id = gg.itemid';
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 'gg.id';
    }

    /**
     * Leaves out the site course's grades, and keeps only the requested courses' or grade items'
     * when the reader asked for some.
     *
     * @return array [string $sql, array $params]
     */
    protected function filter(): array {
        $sql = 'gi.courseid <> :siteid';
        $params = ['siteid' => SITEID];
        if ($this->courseids) {
            [$insql, $inparams] = $this->db->get_in_or_equal($this->courseids, SQL_PARAMS_NAMED, 'course');
            $sql .= ' AND gi.courseid ' . $insql;
            $params += $inparams;
        }
        if ($this->itemids) {
            [$insql, $inparams] = $this->db->get_in_or_equal($this->itemids, SQL_PARAMS_NAMED, 'item');
            $sql .= ' AND gg.itemid ' . $insql;
            $params += $inparams;
        }
        return [$sql, $params];
    }

    /**
     * Shapes a page of grades.
     *
     * @param \stdClass[] $records The grades.
     * @return array[]
     */
    protected function shape(array $records): array {
        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => (int) $record->id,
                'itemid' => (int) $record->itemid,
                'userid' => (int) $record->userid,
                'finalgrade' => $record->finalgrade === null ? null : (float) $record->finalgrade,
                'rawgrade' => $record->rawgrade === null ? null : (float) $record->rawgrade,
                'rawgrademin' => (float) $record->rawgrademin,
                'rawgrademax' => (float) $record->rawgrademax,
                'hidden' => (int) $record->hidden,
                'overridden' => (int) $record->overridden,
                'timemodified' => $record->timemodified === null ? null : (int) $record->timemodified,
            ];
        }
        return $rows;
    }
}
