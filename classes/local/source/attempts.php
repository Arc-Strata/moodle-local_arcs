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
 * Reads one activity module's attempts: what the attempt sources share.
 *
 * Each subclass reads its own module's tables, joined to course_modules (alias cm) and modules
 * (alias m). This class names the module, says whether it is installed, and builds the condition
 * every attempt source applies: the module's own course modules, none on the site course, and the
 * reader's courseids or coursemoduleids filter.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class attempts extends source {
    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int[] $courseids Only read attempts in these courses; empty for all.
     * @param int[] $coursemoduleids Only read attempts at these course modules; empty for all.
     */
    public function __construct(
        \moodle_database $db,
        /** @var int[] Only read attempts in these courses; empty for all. */
        private array $courseids = [],
        /** @var int[] Only read attempts at these course modules; empty for all. */
        private array $coursemoduleids = [],
    ) {
        parent::__construct($db);
    }

    /**
     * The activity module's name, such as quiz.
     *
     * @return string
     */
    abstract public function modname(): string;

    /**
     * Whether the module is installed. When it has been uninstalled, its tables are gone.
     *
     * @return bool
     */
    public function is_installed(): bool {
        return $this->db->record_exists('modules', ['name' => $this->modname()]);
    }

    /**
     * The module's course modules outside the site course, narrowed to the requested courses or
     * course modules when the reader asked for some. Subclasses add their own conditions to it.
     *
     * @return array [string $sql, array $params]
     */
    protected function filter(): array {
        $sql = 'm.name = :modname AND cm.course <> :siteid';
        $params = ['modname' => $this->modname(), 'siteid' => SITEID];
        if ($this->courseids) {
            [$insql, $inparams] = $this->db->get_in_or_equal($this->courseids, SQL_PARAMS_NAMED, 'course');
            $sql .= ' AND cm.course ' . $insql;
            $params += $inparams;
        }
        if ($this->coursemoduleids) {
            [$insql, $inparams] = $this->db->get_in_or_equal($this->coursemoduleids, SQL_PARAMS_NAMED, 'cm');
            $sql .= ' AND cm.id ' . $insql;
            $params += $inparams;
        }
        return [$sql, $params];
    }
}
