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
 * Event observers for local_arcs: each records a change log row.
 *
 * All are external (internal false), so they run only after the change commits.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => \core\event\user_created::class,
        'callback' => [\local_arcs\observer::class, 'user_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\user_updated::class,
        'callback' => [\local_arcs\observer::class, 'user_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\user_deleted::class,
        'callback' => [\local_arcs\observer::class, 'user_deleted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\user_info_field_created::class,
        'callback' => [\local_arcs\observer::class, 'profile_field_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\user_info_field_updated::class,
        'callback' => [\local_arcs\observer::class, 'profile_field_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\user_info_field_deleted::class,
        'callback' => [\local_arcs\observer::class, 'profile_field_deleted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\course_category_created::class,
        'callback' => [\local_arcs\observer::class, 'course_category_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\course_category_updated::class,
        'callback' => [\local_arcs\observer::class, 'course_category_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\course_category_deleted::class,
        'callback' => [\local_arcs\observer::class, 'course_category_deleted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\course_created::class,
        'callback' => [\local_arcs\observer::class, 'course_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\course_updated::class,
        'callback' => [\local_arcs\observer::class, 'course_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\course_restored::class,
        'callback' => [\local_arcs\observer::class, 'course_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\course_reset_ended::class,
        'callback' => [\local_arcs\observer::class, 'course_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\course_content_deleted::class,
        'callback' => [\local_arcs\observer::class, 'course_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\course_completion_updated::class,
        'callback' => [\local_arcs\observer::class, 'course_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\course_deleted::class,
        'callback' => [\local_arcs\observer::class, 'course_deleted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\course_module_created::class,
        'callback' => [\local_arcs\observer::class, 'course_module_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\course_module_updated::class,
        'callback' => [\local_arcs\observer::class, 'course_module_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\course_module_deleted::class,
        'callback' => [\local_arcs\observer::class, 'course_module_deleted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\enrol_instance_updated::class,
        'callback' => [\local_arcs\observer::class, 'course_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\user_enrolment_created::class,
        'callback' => [\local_arcs\observer::class, 'user_enrolment_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\user_enrolment_updated::class,
        'callback' => [\local_arcs\observer::class, 'user_enrolment_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\user_enrolment_deleted::class,
        'callback' => [\local_arcs\observer::class, 'user_enrolment_deleted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\role_assigned::class,
        'callback' => [\local_arcs\observer::class, 'role_changed'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\role_unassigned::class,
        'callback' => [\local_arcs\observer::class, 'role_changed'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\course_completed::class,
        'callback' => [\local_arcs\observer::class, 'course_completed'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\course_module_completion_updated::class,
        'callback' => [\local_arcs\observer::class, 'activity_completion_updated'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\grade_item_created::class,
        'callback' => [\local_arcs\observer::class, 'grade_item_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\grade_item_updated::class,
        'callback' => [\local_arcs\observer::class, 'grade_item_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\grade_item_deleted::class,
        'callback' => [\local_arcs\observer::class, 'grade_item_deleted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\user_graded::class,
        'callback' => [\local_arcs\observer::class, 'grade_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\grade_deleted::class,
        'callback' => [\local_arcs\observer::class, 'grade_deleted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\cohort_created::class,
        'callback' => [\local_arcs\observer::class, 'cohort_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\cohort_updated::class,
        'callback' => [\local_arcs\observer::class, 'cohort_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\cohort_deleted::class,
        'callback' => [\local_arcs\observer::class, 'cohort_deleted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\cohort_member_added::class,
        'callback' => [\local_arcs\observer::class, 'cohort_member_added'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\cohort_member_removed::class,
        'callback' => [\local_arcs\observer::class, 'cohort_member_removed'],
        'internal' => false,
    ],
];
