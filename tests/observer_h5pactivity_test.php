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

use core_xapi\handler;
use core_xapi\local\statement;
use core_xapi\local\statement\item_activity;
use core_xapi\local\statement\item_agent;
use core_xapi\local\statement\item_definition;
use core_xapi\local\statement\item_result;
use core_xapi\local\statement\item_verb;
use local_arcs\tests\changes_trait;

#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
/**
 * Tests that H5P activity results record change log rows.
 *
 * Each test sends xAPI statements through the H5P activity's handler, as the H5P player does;
 * none calls the observer directly.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\observer
 */
final class observer_h5pactivity_test extends \advanced_testcase {
    use changes_trait;

    /** @var \stdClass A course. */
    private \stdClass $course;

    /** @var \stdClass A student in the course. */
    private \stdClass $user;

    /**
     * Resets the database the slow way, so the external observers run (see changes_trait), and
     * creates a course with a student, as an administrator.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        // The H5P activity generator saves the content as the current user.
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Sends the student's result to an H5P activity, as the student.
     *
     * @param \stdClass $activity The activity, with cmid.
     * @param string $subcontent Empty for the whole content's result (which starts a new attempt),
     *     or the id of a part of it (which adds to the latest attempt).
     */
    private function send(\stdClass $activity, string $subcontent = ''): void {
        $statement = new statement();
        $statement->set_actor(item_agent::create_from_user($this->user));
        $statement->set_verb(item_verb::create_from_id('http://adlnet.gov/expapi/verbs/completed'));
        $context = \core\context\module::instance($activity->cmid);
        $statement->set_object(item_activity::create_from_id(
            $context->id . ($subcontent === '' ? '' : '?subContentId=' . $subcontent),
            item_definition::create_from_data((object) ['interactionType' => 'compound', 'correctResponsesPattern' => '1'])
        ));
        $statement->set_result(item_result::create_from_data((object) [
            'completion' => true,
            'success' => true,
            'score' => (object) ['min' => 0, 'max' => 2, 'raw' => 2, 'scaled' => 1],
            'duration' => 'PT25S',
        ]));
        $this->setUser($this->user);
        handler::create('mod_h5pactivity')->process_statements([$statement]);
    }

    /**
     * The user's attempt ids at an activity, by attempt number.
     *
     * @param \stdClass $activity The activity.
     * @return int[]
     */
    private function attempt_ids(\stdClass $activity): array {
        global $DB;
        return array_map('intval', array_values($DB->get_fieldset_select(
            'h5pactivity_attempts',
            'id',
            'h5pactivityid = ? AND userid = ? ORDER BY attempt',
            [$activity->id, $this->user->id]
        )));
    }

    /**
     * The H5P activity attempt change rows, oldest first.
     *
     * @return array[]
     */
    private function attempt_changes(): array {
        return array_values(array_filter($this->changes(), fn(array $change): bool => $change['entity'] === 'h5pactivity_attempt'));
    }

    /**
     * An H5P activity attempt change row for the student in the course.
     *
     * @param int $id The attempt id.
     * @return array
     */
    private function attempt_change(int $id): array {
        return $this->change('h5pactivity_attempt', $id, 'upsert', (int) $this->user->id, (int) $this->course->id);
    }

    /**
     * Each whole-content result starts an attempt and records it; a part's result records the latest attempt.
     */
    public function test_statement_received(): void {
        $activity = $this->getDataGenerator()->create_module('h5pactivity', ['course' => $this->course->id]);

        $this->clear_changes();
        $this->send($activity);
        $this->send($activity, '111-222-333');
        $this->send($activity);

        [$first, $second] = $this->attempt_ids($activity);
        $this->assertSame([
            $this->attempt_change($first),
            $this->attempt_change($first),
            $this->attempt_change($second),
        ], $this->attempt_changes());
    }

    /**
     * Results sent to an activity that does not track attempts save nothing and record nothing.
     */
    public function test_tracking_disabled(): void {
        $activity = $this->getDataGenerator()->create_module(
            'h5pactivity',
            ['course' => $this->course->id, 'enabletracking' => 0]
        );

        $this->clear_changes();
        $this->send($activity);

        $this->assertSame([], $this->attempt_ids($activity));
        $this->assertSame([], $this->attempt_changes());
    }

    /**
     * Results sent to an H5P activity on the site course record nothing.
     */
    public function test_site_course_ignored(): void {
        global $DB;
        $activity = $this->getDataGenerator()->create_module('h5pactivity', ['course' => SITEID]);
        role_assign(
            (int) $DB->get_field('role', 'id', ['shortname' => 'student']),
            $this->user->id,
            \core\context\module::instance($activity->cmid)->id
        );

        $this->clear_changes();
        $this->send($activity);

        $this->assertCount(1, $this->attempt_ids($activity));
        $this->assertSame([], $this->attempt_changes());
    }
}
