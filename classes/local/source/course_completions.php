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
 * Reads course completions (the course_completions table).
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_completions extends source {
    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int[] $courseids Only read completions in these courses; empty for all.
     */
    public function __construct(
        \moodle_database $db,
        /** @var int[] Only read completions in these courses; empty for all. */
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
        return 'cc.id, cc.userid, cc.course, cc.timeenrolled, cc.timestarted, cc.timecompleted, cc.reaggregate';
    }

    /**
     * The table to read from.
     *
     * @return string
     */
    protected function from(): string {
        return '{course_completions} cc';
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 'cc.id';
    }

    /**
     * Only the requested courses' completions, when the reader asked for some.
     *
     * @return array [string $sql, array $params]
     */
    protected function filter(): array {
        if (!$this->courseids) {
            return ['', []];
        }
        [$insql, $params] = $this->db->get_in_or_equal($this->courseids, SQL_PARAMS_NAMED, 'course');
        return ['cc.course ' . $insql, $params];
    }

    /**
     * Shapes a page of course completions.
     *
     * @param \stdClass[] $records The course completions.
     * @return array[]
     */
    protected function shape(array $records): array {
        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => (int) $record->id,
                'userid' => (int) $record->userid,
                'course' => (int) $record->course,
                'timeenrolled' => (int) $record->timeenrolled,
                'timestarted' => (int) $record->timestarted,
                'timecompleted' => $record->timecompleted === null ? null : (int) $record->timecompleted,
                'reaggregate' => (int) $record->reaggregate,
            ];
        }
        return $rows;
    }
}
