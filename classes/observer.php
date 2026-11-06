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

namespace local_arcs;

use local_arcs\local\changelog\entity;
use local_arcs\local\changelog\operation;
use local_arcs\local\changelog\recorder;

/**
 * Turns core events into change log rows. It only maps events to records; the recorder writes.
 *
 * Every observer is registered with internal set to false, so it runs after the
 * surrounding transaction commits, and never for a rolled-back change.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A user was created or updated, including their custom profile field values.
     *
     * @param \core\event\base $event user_created or user_updated.
     */
    public static function user_upserted(\core\event\base $event): void {
        self::recorder()->record(entity::USER, $event->objectid, operation::UPSERT, $event->objectid);
    }

    /**
     * A user was deleted.
     *
     * @param \core\event\user_deleted $event The event.
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        self::recorder()->record(entity::USER, $event->objectid, operation::DELETE, $event->objectid);
    }

    /**
     * A custom profile field definition was created or updated.
     *
     * @param \core\event\base $event user_info_field_created or user_info_field_updated.
     */
    public static function profile_field_upserted(\core\event\base $event): void {
        self::recorder()->record(entity::PROFILE_FIELD, $event->objectid, operation::UPSERT);
    }

    /**
     * A custom profile field definition was deleted, and every user's value for it with it.
     *
     * @param \core\event\user_info_field_deleted $event The event.
     */
    public static function profile_field_deleted(\core\event\user_info_field_deleted $event): void {
        self::recorder()->record(entity::PROFILE_FIELD, $event->objectid, operation::DELETE);
    }

    /**
     * The recorder that writes change rows.
     *
     * @return recorder
     */
    private static function recorder(): recorder {
        global $DB;
        return new recorder($DB, \core\di::get(\core\clock::class));
    }
}
