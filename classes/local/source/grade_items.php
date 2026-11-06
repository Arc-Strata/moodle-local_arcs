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
 * Reads grade items (the grade_items table), with the course module of each activity's item and the items of each scale.
 *
 * Grade items in the site course (front page activities) are left out, as their modules are.
 * Scale items are read once per page, for the scales on the page.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_items extends source {
    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int[] $courseids Only read grade items in these courses; empty for all.
     */
    public function __construct(
        \moodle_database $db,
        /** @var int[] Only read grade items in these courses; empty for all. */
        private array $courseids = [],
    ) {
        global $CFG;
        parent::__construct($db);
        // For GRADE_TYPE_SCALE.
        require_once($CFG->libdir . '/grade/constants.php');
    }

    /**
     * The columns to select.
     *
     * @return string
     */
    protected function fields(): string {
        return 'gi.id, gi.courseid, gi.itemtype, gi.itemmodule, gi.iteminstance, gi.itemnumber, gi.itemname, ' .
            'gi.idnumber, gi.gradetype, gi.grademin, gi.grademax, gi.gradepass, gi.scaleid, gi.hidden, ' .
            'gi.needsupdate, gi.timemodified, cm.id AS coursemoduleid';
    }

    /**
     * The tables to read from. An activity's item names its module by type and instance; the
     * course module is looked up from those (each instance has one course module).
     *
     * @return string
     */
    protected function from(): string {
        return "{grade_items} gi
           LEFT JOIN {modules} m ON gi.itemtype = 'mod' AND m.name = gi.itemmodule
           LEFT JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = gi.iteminstance AND cm.course = gi.courseid";
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 'gi.id';
    }

    /**
     * Leaves out the site course's grade items, and keeps only the requested courses' when the reader asked for some.
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
        return [$sql, $params];
    }

    /**
     * Shapes a page of grade items, adding the items of their scales.
     *
     * @param \stdClass[] $records The grade items.
     * @return array[]
     */
    protected function shape(array $records): array {
        $scales = $this->scale_items($records);

        $rows = [];
        foreach ($records as $record) {
            $isscale = (int) $record->gradetype === GRADE_TYPE_SCALE && $record->scaleid !== null;
            $rows[] = [
                'id' => (int) $record->id,
                'courseid' => (int) $record->courseid,
                'itemtype' => $record->itemtype,
                'itemmodule' => $record->itemmodule,
                'iteminstance' => $record->iteminstance === null ? null : (int) $record->iteminstance,
                'itemnumber' => $record->itemnumber === null ? null : (int) $record->itemnumber,
                'coursemoduleid' => $record->coursemoduleid === null ? null : (int) $record->coursemoduleid,
                'itemname' => $record->itemname,
                'idnumber' => $record->idnumber,
                'gradetype' => (int) $record->gradetype,
                'grademin' => (float) $record->grademin,
                'grademax' => (float) $record->grademax,
                'gradepass' => (float) $record->gradepass,
                'scaleid' => $isscale ? (int) $record->scaleid : null,
                'scaleitems' => $isscale ? ($scales[(int) $record->scaleid] ?? []) : [],
                'hidden' => (int) $record->hidden,
                'needsupdate' => (bool) $record->needsupdate,
                'timemodified' => $record->timemodified === null ? null : (int) $record->timemodified,
            ];
        }
        return $rows;
    }

    /**
     * Reads the items of the scales used by a page of grade items, in one query.
     *
     * A scale stores its items as one comma-separated string; they are split and trimmed as core does.
     *
     * @param \stdClass[] $records The grade items.
     * @return array[] Lists of scale items, keyed by scale id.
     */
    private function scale_items(array $records): array {
        $scaleids = [];
        foreach ($records as $record) {
            if ((int) $record->gradetype === GRADE_TYPE_SCALE && $record->scaleid !== null) {
                $scaleids[(int) $record->scaleid] = true;
            }
        }
        if (!$scaleids) {
            return [];
        }

        $scales = $this->db->get_records_list('scale', 'id', array_keys($scaleids), '', 'id, scale');
        $items = [];
        foreach ($scales as $scale) {
            $items[(int) $scale->id] = array_map('trim', explode(',', $scale->scale));
        }
        return $items;
    }
}
