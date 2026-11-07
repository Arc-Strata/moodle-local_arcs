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

namespace local_arcs\check;

use core\check\check;
use core\check\result;
use core\output\action_link;
use local_arcs\local\changelog\recorder;
use local_arcs\local\settings\settings;

/**
 * Status check, for information: the change log's size, oldest row and retention.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class changelog extends check {
    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param settings $settings The plugin's settings.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        private \moodle_database $db,
        /** @var settings The plugin's settings. */
        private settings $settings,
    ) {
    }

    /**
     * A link to the plugin's settings.
     *
     * @return action_link
     */
    public function get_action_link(): ?action_link {
        return new action_link(
            new \core\url('/admin/settings.php', ['section' => 'local_arcs']),
            get_string('pluginname', 'local_arcs')
        );
    }

    /**
     * Describes the change log.
     *
     * @return result
     */
    public function get_result(): result {
        $days = $this->settings->retention_days();
        $row = $this->db->get_record_sql(
            'SELECT COUNT(id) AS changes, MIN(timecreated) AS oldest FROM {' . recorder::TABLE . '}'
        );
        if ((int) $row->changes === 0) {
            return new result(result::INFO, get_string('checkchangelog_empty', 'local_arcs', $days));
        }
        return new result(result::INFO, get_string('checkchangelog_info', 'local_arcs', [
            'changes' => (int) $row->changes,
            'oldest' => userdate((int) $row->oldest),
            'days' => $days,
        ]));
    }
}
