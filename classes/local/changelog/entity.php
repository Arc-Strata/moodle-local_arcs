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

namespace local_arcs\local\changelog;

/**
 * Kinds of record the change log can name. The values are part of the web service contract.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
enum entity: string {
    // A user (user.id).
    case USER = 'user';

    // A custom profile field definition (user_info_field.id).
    case PROFILE_FIELD = 'profile_field';

    // A cohort (cohort.id).
    case COHORT = 'cohort';

    // A cohort membership, named by its cohort id (entityid) and user id (userid).
    case COHORT_MEMBER = 'cohort_member';

    // A course category (course_categories.id).
    case COURSE_CATEGORY = 'course_category';

    // A course (course.id).
    case COURSE = 'course';

    // Everything in a course, named by the course id. An upsert means: refetch everything in the course.
    case COURSE_CONTENTS = 'course_contents';

    // A course module (course_modules.id).
    case COURSE_MODULE = 'course_module';

    // An enrolment method instance (enrol.id). An upsert means: refetch the user enrolments through it.
    case ENROL_INSTANCE = 'enrol_instance';

    // A user enrolment (user_enrolments.id).
    case USER_ENROLMENT = 'user_enrolment';

    // A course completion (course_completions.id).
    case COURSE_COMPLETION = 'course_completion';

    // An activity completion (course_modules_completion.id).
    case ACTIVITY_COMPLETION = 'activity_completion';

    // A grade item (grade_items.id).
    case GRADE_ITEM = 'grade_item';

    // A grade (grade_grades.id).
    case GRADE = 'grade';

    // A quiz attempt (quiz_attempts.id).
    case QUIZ_ATTEMPT = 'quiz_attempt';

    // A quiz's grading, named by its course module id. An upsert means: refetch the quiz's attempts.
    case QUIZ_GRADING = 'quiz_grading';
}
