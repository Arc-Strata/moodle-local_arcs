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
use local_arcs\local\logstore as logstore_state;

/**
 * Status check: the standard log store is enabled, so Arcs can read log events.
 *
 * Only the log stream depends on it; the change log and the state functions do not,
 * so a disabled store is a warning.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logstore extends check {
    /**
     * Constructor.
     *
     * @param logstore_state $logstore The standard log store's state.
     */
    public function __construct(
        /** @var logstore_state The standard log store's state. */
        private logstore_state $logstore,
    ) {
    }

    /**
     * A link to the log store settings.
     *
     * @return action_link
     */
    public function get_action_link(): ?action_link {
        return new action_link(
            new \core\url('/admin/settings.php', ['section' => 'managelogging']),
            get_string('managelogging', 'tool_log')
        );
    }

    /**
     * Whether the standard log store is enabled.
     *
     * @return result
     */
    public function get_result(): result {
        if (!$this->logstore->is_enabled()) {
            return new result(result::WARNING, get_string('checklogstore_warning', 'local_arcs'));
        }
        return new result(result::OK, get_string('checklogstore_ok', 'local_arcs'));
    }
}
