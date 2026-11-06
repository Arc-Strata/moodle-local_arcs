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
     * these is recorded as a course upsert. So is a change to an enrolment method instance,
     * since its status decides whether every enrolment through it is in effect.
     *
     * @param \core\event\base $event course_created, course_updated, course_restored, course_reset_ended,
     *     course_content_deleted, course_completion_updated or enrol_instance_updated.
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
     * A user enrolment was created or updated.
     *
     * @param \core\event\base $event user_enrolment_created or user_enrolment_updated.
     */
    public static function user_enrolment_upserted(\core\event\base $event): void {
        self::recorder()->record(
            entity::USER_ENROLMENT,
            $event->objectid,
            operation::UPSERT,
            $event->relateduserid,
            $event->courseid
        );
    }

    /**
     * A user enrolment was deleted.
     *
     * @param \core\event\user_enrolment_deleted $event The event.
     */
    public static function user_enrolment_deleted(\core\event\user_enrolment_deleted $event): void {
        self::recorder()->record(
            entity::USER_ENROLMENT,
            $event->objectid,
            operation::DELETE,
            $event->relateduserid,
            $event->courseid
        );
    }

    /**
     * A role was assigned or unassigned. In a course context, the user's enrolments in that
     * course carry their roles, so each is recorded as an upsert.
     *
     * The enrolments are looked up when the observer runs, after the change has committed.
     * Unenrolling a user unassigns their roles just before deleting the enrolment, so this
     * may record an upsert for it right before its delete; readers find nothing to fetch.
     *
     * @param \core\event\base $event role_assigned or role_unassigned.
     */
    public static function role_changed(\core\event\base $event): void {
        global $DB;
        if ($event->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $courseid = (int) $event->contextinstanceid;
        $userid = (int) $event->relateduserid;
        $sql = "SELECT ue.id
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid
                   AND ue.userid = :userid";
        $recorder = self::recorder();
        foreach ($DB->get_fieldset_sql($sql, ['courseid' => $courseid, 'userid' => $userid]) as $id) {
            $recorder->record(entity::USER_ENROLMENT, (int) $id, operation::UPSERT, $userid, $courseid);
        }
    }

    /**
     * A user completed a course.
     *
     * @param \core\event\course_completed $event The event.
     */
    public static function course_completed(\core\event\course_completed $event): void {
        self::recorder()->record(
            entity::COURSE_COMPLETION,
            $event->objectid,
            operation::UPSERT,
            $event->relateduserid,
            $event->courseid
        );
    }

    /**
     * A user's completion of an activity changed, including viewing it when that counts towards completion.
     *
     * @param \core\event\course_module_completion_updated $event The event.
     */
    public static function activity_completion_updated(\core\event\course_module_completion_updated $event): void {
        self::recorder()->record(
            entity::ACTIVITY_COMPLETION,
            $event->objectid,
            operation::UPSERT,
            $event->relateduserid,
            $event->courseid
        );
    }

    /**
     * A grade item was created or updated. Site course grade items are not exported, so they are ignored.
     *
     * Hiding or locking an item also changes its grades without events of their own; readers
     * refetch them with the itemids filter.
     *
     * @param \core\event\base $event grade_item_created or grade_item_updated.
     */
    public static function grade_item_upserted(\core\event\base $event): void {
        if ($event->courseid == SITEID) {
            return;
        }
        self::recorder()->record(entity::GRADE_ITEM, $event->objectid, operation::UPSERT, null, $event->courseid);
    }

    /**
     * A grade item was deleted, and its grades with it. Site course grade items are not exported, so they are ignored.
     *
     * @param \core\event\grade_item_deleted $event The event.
     */
    public static function grade_item_deleted(\core\event\grade_item_deleted $event): void {
        if ($event->courseid == SITEID) {
            return;
        }
        self::recorder()->record(entity::GRADE_ITEM, $event->objectid, operation::DELETE, null, $event->courseid);
    }

    /**
     * A user's final grade was set or changed, by grading, overriding or recalculation.
     * Site course grades are not exported, so they are ignored.
     *
     * @param \core\event\user_graded $event The event.
     */
    public static function grade_upserted(\core\event\user_graded $event): void {
        if ($event->courseid == SITEID) {
            return;
        }
        self::recorder()->record(entity::GRADE, $event->objectid, operation::UPSERT, $event->relateduserid, $event->courseid);
    }

    /**
     * A user's grade was deleted. Site course grades are not exported, so they are ignored.
     *
     * @param \core\event\grade_deleted $event The event.
     */
    public static function grade_deleted(\core\event\grade_deleted $event): void {
        if ($event->courseid == SITEID) {
            return;
        }
        self::recorder()->record(entity::GRADE, $event->objectid, operation::DELETE, $event->relateduserid, $event->courseid);
    }

    /**
     * A cohort was created or updated.
     *
     * @param \core\event\base $event cohort_created or cohort_updated.
     */
    public static function cohort_upserted(\core\event\base $event): void {
        self::recorder()->record(entity::COHORT, $event->objectid, operation::UPSERT);
    }

    /**
     * A cohort was deleted, and its memberships with it.
     *
     * @param \core\event\cohort_deleted $event The event.
     */
    public static function cohort_deleted(\core\event\cohort_deleted $event): void {
        self::recorder()->record(entity::COHORT, $event->objectid, operation::DELETE);
    }

    /**
     * A user was added to a cohort. The event names the cohort and the user, not the membership,
     * so the change is recorded by that pair.
     *
     * @param \core\event\cohort_member_added $event The event.
     */
    public static function cohort_member_added(\core\event\cohort_member_added $event): void {
        self::recorder()->record(entity::COHORT_MEMBER, $event->objectid, operation::UPSERT, $event->relateduserid);
    }

    /**
     * A user was removed from a cohort, recorded by the cohort and the user.
     *
     * @param \core\event\cohort_member_removed $event The event.
     */
    public static function cohort_member_removed(\core\event\cohort_member_removed $event): void {
        self::recorder()->record(entity::COHORT_MEMBER, $event->objectid, operation::DELETE, $event->relateduserid);
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
