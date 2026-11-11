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

/**
 * Callbacks for local_arcs.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds the checks that say whether Arcs can connect and read to the status report.
 *
 * @return \core\check\check[]
 */
function local_arcs_status_checks(): array {
    global $CFG, $DB;
    return [
        new \local_arcs\check\webservices($CFG),
        new \local_arcs\check\restprotocol($CFG),
        new \local_arcs\check\service($DB),
        new \local_arcs\check\token($DB, \core\di::get(\core\clock::class)),
        new \local_arcs\check\logstore(new \local_arcs\local\logstore($DB)),
        new \local_arcs\check\changelog($DB, new \local_arcs\local\settings\settings()),
    ];
}
