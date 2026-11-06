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

namespace local_arcs\local;

/**
 * Reads the state of Moodle's standard log store (logstore_standard).
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logstore {
    /** @var string Component name of the standard log store. */
    private const COMPONENT = 'logstore_standard';

    /**
     * Whether the standard log store is enabled, so that new events are written to it.
     *
     * @return bool
     */
    public function is_enabled(): bool {
        $stores = (string) get_config('tool_log', 'enabled_stores');
        return in_array(self::COMPONENT, explode(',', $stores), true);
    }

    /**
     * How many days the standard log store keeps rows before core deletes them.
     *
     * @return int Days; 0 means rows are kept forever.
     */
    public function lifetime_days(): int {
        return (int) get_config(self::COMPONENT, 'loglifetime');
    }
}
