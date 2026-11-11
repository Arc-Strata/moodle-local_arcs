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
 * Reads course modules (the course_modules table), with each module's type and instance name.
 *
 * Modules in the site course (front page activities) are left out, as the site course is.
 * Instance names are read from each module type's own table, one query per type per page.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_modules extends source {
    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int[] $courseids Only read modules in these courses; empty for all.
     */
    public function __construct(
        \moodle_database $db,
        /** @var int[] Only read modules in these courses; empty for all. */
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
        return 'cm.id, cm.course, m.name AS modname, cm.instance, cm.section, cm.idnumber, cm.visible, cm.completion, ' .
            'cm.completionexpected, cm.completiongradeitemnumber, cm.completionview, cm.added, cm.deletioninprogress';
    }

    /**
     * The tables to read from.
     *
     * @return string
     */
    protected function from(): string {
        return '{course_modules} cm JOIN {modules} m ON m.id = cm.module';
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 'cm.id';
    }

    /**
     * Leaves out the site course's modules, and keeps only the requested courses' when the reader asked for some.
     *
     * @return array [string $sql, array $params]
     */
    protected function filter(): array {
        $sql = 'cm.course <> :siteid';
        $params = ['siteid' => SITEID];
        if ($this->courseids) {
            [$insql, $inparams] = $this->db->get_in_or_equal($this->courseids, SQL_PARAMS_NAMED, 'course');
            $sql .= ' AND cm.course ' . $insql;
            $params += $inparams;
        }
        return [$sql, $params];
    }

    /**
     * Shapes a page of course modules, adding their instance names.
     *
     * @param \stdClass[] $records The course modules.
     * @return array[]
     */
    protected function shape(array $records): array {
        $names = $this->instance_names($records);

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => (int) $record->id,
                'course' => (int) $record->course,
                'modname' => $record->modname,
                'instance' => (int) $record->instance,
                'instancename' => $names[$record->modname][$record->instance] ?? null,
                'section' => (int) $record->section,
                'idnumber' => $record->idnumber,
                'visible' => (bool) $record->visible,
                'completion' => (int) $record->completion,
                'completionexpected' => (int) $record->completionexpected,
                'completiongradeitemnumber' => $record->completiongradeitemnumber === null
                    ? null : (int) $record->completiongradeitemnumber,
                'completionview' => (bool) $record->completionview,
                'added' => (int) $record->added,
                'deletioninprogress' => (bool) $record->deletioninprogress,
            ];
        }
        return $rows;
    }

    /**
     * Reads the instance names of a page of course modules, one query per module type.
     *
     * The module type names a table, so only installed activity module plugins are read;
     * a module whose plugin is missing has no name.
     *
     * @param \stdClass[] $records The course modules.
     * @return array[] Names keyed by module type, then by instance id.
     */
    private function instance_names(array $records): array {
        $instances = [];
        foreach ($records as $record) {
            $instances[$record->modname][] = (int) $record->instance;
        }

        $plugins = \core_component::get_plugin_list('mod');
        $names = [];
        foreach ($instances as $modname => $ids) {
            if (!array_key_exists($modname, $plugins)) {
                continue;
            }
            [$insql, $params] = $this->db->get_in_or_equal(array_unique($ids), SQL_PARAMS_NAMED, 'instance');
            $names[$modname] = $this->db->get_records_select_menu($modname, "id $insql", $params, '', 'id, name');
        }
        return $names;
    }
}
