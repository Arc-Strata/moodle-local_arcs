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
require_once($CFG->dirroot . '/mod/scorm/locallib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
/**
 * Tests that SCORM tracking and attempt deletion record change log rows.
 *
 * Each test writes tracking data with scorm_insert_track, as SCORM's runtime does, or deletes
 * attempts with scorm_delete_attempt, as the SCORM reports do; none calls the observer directly.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\observer
 */
final class observer_scorm_test extends \advanced_testcase {
    use changes_trait;

    /** @var \stdClass A course. */
    private \stdClass $course;

    /** @var \stdClass A SCORM package in the course, with cmid. */
    private \stdClass $scorm;

    /** @var int The package's SCO. */
    private int $scoid;

    /** @var \stdClass A user. */
    private \stdClass $user;

    /**
     * Resets the database the slow way, so the external observers run (see changes_trait), and
     * creates a course with a SCORM package, and a user, as an administrator.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        // The SCORM generator saves the package as the current user.
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $this->course->id]);
        $this->scoid = (int) $DB->get_field('scorm_scoes', 'id', ['scorm' => $this->scorm->id, 'scormtype' => 'sco']);
        $this->user = $this->getDataGenerator()->create_user();
    }

    /**
     * Writes one tracking element for the user's attempt, as the SCORM runtime does.
     *
     * @param string $element The element.
     * @param string $value Its value.
     * @param int $attempt The attempt number.
     */
    private function track(string $element, string $value, int $attempt = 1): void {
        scorm_insert_track($this->user->id, $this->scorm->id, $this->scoid, $attempt, $element, $value);
    }

    /**
     * The user's attempt id.
     *
     * @param int $attempt The attempt number.
     * @return int
     */
    private function attempt_id(int $attempt = 1): int {
        return (int) scorm_get_attempt($this->user->id, $this->scorm->id, $attempt, false)->id;
    }

    /**
     * The change log rows for one entity, oldest first. Scores also record grades, which other tests cover.
     *
     * @param string $entity The entity.
     * @return array[]
     */
    private function changes_of(string $entity): array {
        return array_values(array_filter($this->changes(), fn(array $change): bool => $change['entity'] === $entity));
    }

    /**
     * A score, and a completed, passed or failed status, each record an upsert for the attempt.
     */
    public function test_score_and_status_recorded(): void {
        $this->clear_changes();
        $this->track('cmi.core.score.raw', '80');
        $this->track('cmi.core.lesson_status', 'passed');
        $this->track('cmi.completion_status', 'completed', 2);
        $this->track('cmi.success_status', 'failed', 2);

        $one = $this->change('scorm_attempt', $this->attempt_id(), 'upsert', (int) $this->user->id, (int) $this->course->id);
        $two = $this->change('scorm_attempt', $this->attempt_id(2), 'upsert', (int) $this->user->id, (int) $this->course->id);
        $this->assertSame([$one, $one, $two, $two], $this->changes_of('scorm_attempt'));
    }

    /**
     * Other statuses and elements record nothing (the contract lists this).
     */
    public function test_other_tracking_not_recorded(): void {
        $this->clear_changes();
        $this->track('x.start.time', (string) time());
        $this->track('cmi.core.lesson_status', 'incomplete');
        $this->track('cmi.core.score.max', '100');
        $this->track('cmi.suspend_data', 'data');

        $this->assertSame([], $this->changes_of('scorm_attempt'));
    }

    /**
     * Deleting an attempt records a scorm_tracking upsert for the package.
     */
    public function test_attempt_deleted(): void {
        global $DB;
        $this->track('cmi.core.score.raw', '80');

        $this->clear_changes();
        scorm_delete_attempt($this->user->id, $DB->get_record('scorm', ['id' => $this->scorm->id]), 1);

        $this->assertNull(scorm_get_attempt($this->user->id, $this->scorm->id, 1, false));
        $this->assertSame([], $this->changes_of('scorm_attempt'));
        $this->assertSame(
            [$this->change('scorm_tracking', (int) $this->scorm->cmid, 'upsert', null, (int) $this->course->id)],
            $this->changes_of('scorm_tracking')
        );
    }

    /**
     * Tracking in a SCORM package on the site course records nothing.
     */
    public function test_site_course_ignored(): void {
        global $DB;
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => SITEID]);
        $scoid = $DB->get_field('scorm_scoes', 'id', ['scorm' => $scorm->id, 'scormtype' => 'sco']);

        $this->clear_changes();
        scorm_insert_track($this->user->id, $scorm->id, $scoid, 1, 'cmi.core.score.raw', '80');
        scorm_delete_attempt($this->user->id, $DB->get_record('scorm', ['id' => $scorm->id]), 1);

        $this->assertSame([], $this->changes_of('scorm_attempt'));
        $this->assertSame([], $this->changes_of('scorm_tracking'));
    }
}
