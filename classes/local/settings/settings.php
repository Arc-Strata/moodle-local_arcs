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

namespace local_arcs\local\settings;

/**
 * Typed access to local_arcs's admin settings.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings {
    /** @var int Change log retention in days, until an admin chooses another. */
    public const DEFAULT_RETENTION_DAYS = 30;

    /** @var int[] Retention periods, in days, an admin can choose from. */
    public const RETENTION_DAYS_CHOICES = [7, 14, 30, 60, 90, 180, 365];

    /**
     * How many days the change log keeps rows.
     *
     * @return int
     */
    public function retention_days(): int {
        $days = (int) get_config('local_arcs', 'retentiondays');
        return $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
    }
}
