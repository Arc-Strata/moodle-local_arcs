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

namespace local_arcs\task;

use local_arcs\local\changelog\retention;
use local_arcs\local\settings\settings;

/**
 * Deletes change log rows older than the retention setting.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_changes extends \core\task\scheduled_task {
    /**
     * Returns the task's name, shown to admins.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup_changes', 'local_arcs');
    }

    /**
     * Deletes the expired rows.
     */
    public function execute(): void {
        global $DB;

        $retention = new retention($DB, \core\di::get(\core\clock::class));
        $retention->purge((new settings())->retention_days());
    }
}
