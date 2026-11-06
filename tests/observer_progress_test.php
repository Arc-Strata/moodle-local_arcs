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

use local_arcs\tests\changes_trait;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/completion/completion_completion.php');

#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
/**
 * Tests that core actions on enrolments, roles and completions record change log rows.
 *
 * Each test performs the real core action; none calls the observer directly.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\observer
 */
final class observer_progress_test extends \advanced_testcase {
    use changes_trait;

    /** @var \stdClass A course with completion enabled. */
    private \stdClass $course;

    /** @var \stdClass A user. */
    private \stdClass $user;

    /** @var \enrol_plugin The manual enrolment plugin. */
    private \enrol_plugin $manual;

    /** @var \stdClass The course's manual enrolment instance. */
    private \stdClass $instance;

    /**
     * Resets the database the slow way, so the external observers run (see changes_trait), and
     * creates a course with a manual enrolment instance and a user.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->user = $this->getDataGenerator()->create_user();
        $this->manual = enrol_get_plugin('manual');
        $this->instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
    }

    /**
     * The user's enrolment id in the course.
     *
     * @return int
     */
    private function enrolment_id(): int {
        global $DB;
        return (int) $DB->get_field('user_enrolments', 'id', ['enrolid' => $this->instance->id, 'userid' => $this->user->id]);
    }

    /**
     * A user enrolment change row for the user's enrolment in the course.
     *
     * @param int $id The user enrolment id.
     * @param string $op upsert or delete.
     * @return array
     */
    private function enrolment_change(int $id, string $op): array {
        return $this->change('user_enrolment', $id, $op, (int) $this->user->id, (int) $this->course->id);
    }

    /**
     * Enrolling, updating and unenrolling a user record user enrolment changes.
     */
    public function test_user_enrolment_lifecycle(): void {
        $this->clear_changes();
        $this->manual->enrol_user($this->instance, $this->user->id);
        $id = $this->enrolment_id();
        $this->assertSame([$this->enrolment_change($id, 'upsert')], $this->changes());

        $this->clear_changes();
        $this->manual->update_user_enrol($this->instance, $this->user->id, ENROL_USER_SUSPENDED);
        $this->assertSame([$this->enrolment_change($id, 'upsert')], $this->changes());

        $this->clear_changes();
        $this->manual->unenrol_user($this->instance, $this->user->id);
        $this->assertSame([$this->enrolment_change($id, 'delete')], $this->changes());
    }

    /**
     * Enrolling with a role records the enrolment, and the role assignment upserts it again.
     */
    public function test_enrol_with_role(): void {
        global $DB;
        $this->clear_changes();

        $this->manual->enrol_user($this->instance, $this->user->id, $DB->get_field('role', 'id', ['shortname' => 'student']));

        $upsert = $this->enrolment_change($this->enrolment_id(), 'upsert');
        $this->assertSame([$upsert, $upsert], $this->changes());
    }

    /**
     * Assigning and unassigning a role in the course upserts the user's enrolment there; elsewhere, nothing.
     */
    public function test_role_assigned_and_unassigned(): void {
        global $DB;
        $this->manual->enrol_user($this->instance, $this->user->id);
        $teacherid = (int) $DB->get_field('role', 'id', ['shortname' => 'teacher']);
        $coursecontext = \core\context\course::instance($this->course->id);
        $upsert = $this->enrolment_change($this->enrolment_id(), 'upsert');

        $this->clear_changes();
        role_assign($teacherid, $this->user->id, $coursecontext->id);
        $this->assertSame([$upsert], $this->changes());

        $this->clear_changes();
        role_unassign($teacherid, $this->user->id, $coursecontext->id);
        $this->assertSame([$upsert], $this->changes());

        $this->clear_changes();
        role_assign($teacherid, $this->user->id, \core\context\coursecat::instance($this->course->category)->id);
        $this->assertSame([], $this->changes());
    }

    /**
     * Disabling an enrolment method records a course upsert, since every enrolment through it changes effect.
     */
    public function test_enrol_instance_updated(): void {
        $this->clear_changes();

        $this->manual->update_status($this->instance, ENROL_INSTANCE_DISABLED);

        $this->assertSame(
            [$this->change('course', (int) $this->course->id, 'upsert', null, (int) $this->course->id)],
            $this->changes()
        );
    }

    /**
     * Completing a course records a course completion upsert.
     */
    public function test_course_completed(): void {
        $completion = new \completion_completion(['course' => $this->course->id, 'userid' => $this->user->id]);
        $completion->mark_enrolled();
        $this->clear_changes();

        $completion->mark_complete();

        $this->assertSame(
            [$this->change('course_completion', (int) $completion->id, 'upsert', (int) $this->user->id, (int) $this->course->id)],
            $this->changes()
        );
    }

    /**
     * Changing an activity's completion state, or viewing it when that counts, records an activity completion upsert.
     */
    public function test_activity_completion_updated(): void {
        global $DB;
        $manual = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id,
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $viewed = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1]);
        $modinfo = get_fast_modinfo($this->course->id);
        $completion = new \completion_info($this->course);
        $this->clear_changes();

        $completion->update_state($modinfo->get_cm($manual->cmid), COMPLETION_COMPLETE, $this->user->id);
        $completion->set_module_viewed($modinfo->get_cm($viewed->cmid), $this->user->id);

        $ids = $DB->get_records_menu('course_modules_completion', ['userid' => $this->user->id], '', 'coursemoduleid, id');
        $changes = $this->changes();
        $userid = (int) $this->user->id;
        $courseid = (int) $this->course->id;
        $change = fn(int $cmid): array => $this->change('activity_completion', (int) $ids[$cmid], 'upsert', $userid, $courseid);
        $this->assertSame($change($manual->cmid), $changes[0]);
        $this->assertContains($change($viewed->cmid), $changes);
    }
}
