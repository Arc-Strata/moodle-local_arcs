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
 * Reads courses (the course table), leaving out the site course.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class courses extends source {
    /** @var bool Whether the course table has deletioninprogress (asynchronous course deletion, from Moodle 5.3). */
    private bool $hasasyncdeletion;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int[] $categoryids Only read courses in these categories or below them; empty for all.
     */
    public function __construct(
        \moodle_database $db,
        /** @var int[] Only read courses in these categories or below them; empty for all. */
        private array $categoryids = [],
    ) {
        parent::__construct($db);
        $this->hasasyncdeletion = array_key_exists('deletioninprogress', $db->get_columns('course'));
    }

    /**
     * The columns to select.
     *
     * @return string
     */
    protected function fields(): string {
        return 'c.id, c.category, c.shortname, c.fullname, c.idnumber, c.format, c.visible, c.startdate, c.enddate, ' .
            'c.enablecompletion, c.timecreated, c.timemodified' . ($this->hasasyncdeletion ? ', c.deletioninprogress' : '');
    }

    /**
     * The table to read from.
     *
     * @return string
     */
    protected function from(): string {
        return '{course} c';
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 'c.id';
    }

    /**
     * Leaves out the site course, and keeps only the requested categories' courses when the reader asked for some.
     *
     * @return array [string $sql, array $params]
     */
    protected function filter(): array {
        $sql = 'c.id <> :siteid';
        $params = ['siteid' => SITEID];
        if ($this->categoryids) {
            [$withinsql, $withinparams] = categories::within_sql($this->db, 'c.category', $this->categoryids, 'cat');
            $sql .= ' AND ' . $withinsql;
            $params += $withinparams;
        }
        return [$sql, $params];
    }

    /**
     * Shapes a page of courses.
     *
     * @param \stdClass[] $records The courses.
     * @return array[]
     */
    protected function shape(array $records): array {
        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => (int) $record->id,
                'category' => (int) $record->category,
                'shortname' => $record->shortname,
                'fullname' => $record->fullname,
                'idnumber' => $record->idnumber,
                'format' => $record->format,
                'visible' => (bool) $record->visible,
                'startdate' => (int) $record->startdate,
                'enddate' => (int) $record->enddate,
                'enablecompletion' => (bool) $record->enablecompletion,
                'timecreated' => (int) $record->timecreated,
                'timemodified' => (int) $record->timemodified,
                'deletioninprogress' => !empty($record->deletioninprogress),
            ];
        }
        return $rows;
    }
}
