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
     * A course category was created or updated, including moved, hidden or shown.
     *
     * Moving, hiding or showing a category also changes the categories and courses below
     * it without events of their own; readers refetch them with the categoryids filters.
     *
     * @param \core\event\base $event course_category_created or course_category_updated.
     */
    public static function course_category_upserted(\core\event\base $event): void {
        self::recorder()->record(entity::COURSE_CATEGORY, $event->objectid, operation::UPSERT);
    }

    /**
     * A course category was deleted.
     *
     * @param \core\event\course_category_deleted $event The event.
     */
    public static function course_category_deleted(\core\event\course_category_deleted $event): void {
        self::recorder()->record(entity::COURSE_CATEGORY, $event->objectid, operation::DELETE);
    }

    /**
     * A course, or what is in it, changed: readers refetch the course and everything in it.
     *
     * Restore, reset and content deletion write the course's modules, enrolments, completions
     * and grades directly, and editing completion criteria changes their meaning, so each of
     * these is recorded as a course upsert.
     *
     * @param \core\event\base $event course_created, course_updated, course_restored, course_reset_ended,
     *     course_content_deleted or course_completion_updated.
     */
    public static function course_upserted(\core\event\base $event): void {
        self::recorder()->record(entity::COURSE, $event->courseid, operation::UPSERT, null, $event->courseid);
    }

    /**
     * A course was deleted, and everything in it with it.
     *
     * @param \core\event\course_deleted $event The event.
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        self::recorder()->record(entity::COURSE, $event->courseid, operation::DELETE, null, $event->courseid);
    }

    /**
     * A course module was created or updated. Site course modules are not exported, so they are ignored.
     *
     * @param \core\event\base $event course_module_created or course_module_updated.
     */
    public static function course_module_upserted(\core\event\base $event): void {
        if ($event->courseid == SITEID) {
            return;
        }
        self::recorder()->record(entity::COURSE_MODULE, $event->objectid, operation::UPSERT, null, $event->courseid);
    }

    /**
     * A course module was deleted. Site course modules are not exported, so they are ignored.
     *
     * @param \core\event\course_module_deleted $event The event.
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        if ($event->courseid == SITEID) {
            return;
        }
        self::recorder()->record(entity::COURSE_MODULE, $event->objectid, operation::DELETE, null, $event->courseid);
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
