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

    /** @var string[] Standard user fields exported only when an admin chooses them (user table columns). */
    public const OPTIONAL_USER_FIELDS = [
        'department',
        'institution',
        'city',
        'country',
        'phone1',
        'phone2',
        'address',
        'middlename',
        'alternatename',
        'firstnamephonetic',
        'lastnamephonetic',
    ];

    /** @var string[] Optional fields exported until an admin chooses otherwise. Custom profile fields are not among them. */
    public const DEFAULT_OPTIONAL_FIELDS = ['department', 'institution', 'city', 'country'];

    /**
     * How many days the change log keeps rows.
     *
     * @return int
     */
    public function retention_days(): int {
        $days = (int) get_config('local_arcs', 'retentiondays');
        return $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * The optional user fields an admin has chosen to export.
     *
     * Standard fields are named by their user table column, custom profile fields by
     * profile_field_ and their shortname, as core names them.
     *
     * @return string[]
     */
    public function optional_fields(): array {
        $value = get_config('local_arcs', 'optionalfields');
        if ($value === false) {
            return self::DEFAULT_OPTIONAL_FIELDS;
        }
        return $value === '' ? [] : explode(',', $value);
    }

    /**
     * The optional standard user fields to export: user table column names.
     *
     * @return string[]
     */
    public function optional_user_fields(): array {
        return array_values(array_intersect(self::OPTIONAL_USER_FIELDS, $this->optional_fields()));
    }

    /**
     * The shortnames of the custom profile fields to export.
     *
     * @return string[]
     */
    public function profile_field_shortnames(): array {
        $shortnames = [];
        foreach ($this->optional_fields() as $field) {
            if (preg_match(\core_user\fields::PROFILE_FIELD_REGEX, $field, $matches)) {
                $shortnames[] = $matches[1];
            }
        }
        return $shortnames;
    }
}
