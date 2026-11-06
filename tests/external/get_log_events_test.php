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

namespace local_arcs\external;

use core\exception\invalid_parameter_exception;
use core\exception\moodle_exception;
use core_external\external_api;
use local_arcs\local\logstore;
use local_arcs\local\source\source;
use local_arcs\tests\exporter_trait;

#[\PHPUnit\Framework\Attributes\CoversClass(get_log_events::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\log_events::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(logstore::class)]
/**
 * Tests for local_arcs_get_log_events.
 *
 * Events are written by triggering real events with the standard log store enabled and
 * unbuffered. The store's observer runs after commit, so these tests prevent the reset by
 * rollback that would otherwise hold every event back on PostgreSQL.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_log_events
 * @covers     \local_arcs\local\source\log_events
 * @covers     \local_arcs\local\logstore
 */
final class get_log_events_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Enables the standard log store, unbuffered, and acts as the exporter with an empty log.
     *
     * @return \stdClass The exporter.
     */
    private function set_up_log(): \stdClass {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        $exporter = $this->set_exporter();
        $DB->delete_records(logstore::TABLE);
        return $exporter;
    }

    /**
     * Creates a user, then empties the log of the events that creating them logged.
     *
     * @return \stdClass The user.
     */
    private function create_user(): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $DB->delete_records(logstore::TABLE);
        return $user;
    }

    /**
     * Logs that the current user viewed a user's profile.
     *
     * @param \stdClass $user The user whose profile was viewed.
     * @param bool $anonymous Whether the event is anonymous.
     * @return int The id of the event's log row.
     */
    private function log_profile_view(\stdClass $user, bool $anonymous = false): int {
        global $DB;
        \core\event\user_profile_viewed::create([
            'objectid' => $user->id,
            'relateduserid' => $user->id,
            'context' => \core\context\user::instance($user->id),
            'anonymous' => (int) $anonymous,
        ])->trigger();
        return (int) $DB->get_field_sql('SELECT MAX(id) FROM {' . logstore::TABLE . '}');
    }

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @return array
     */
    private function call(int $after = 0, int $limit = source::DEFAULT_LIMIT): array {
        return external_api::clean_returnvalue(get_log_events::execute_returns(), get_log_events::execute($after, $limit));
    }

    /**
     * With an empty log, the page is empty, the cursor stays put and there is no max id.
     */
    public function test_execute_on_empty_log(): void {
        $this->set_up_log();

        $this->assertSame(['events' => [], 'next' => 0, 'hasmore' => false, 'maxid' => 0], $this->call());
        $this->assertSame(['events' => [], 'next' => 5, 'hasmore' => false, 'maxid' => 0], $this->call(5));
    }

    /**
     * An event is returned with its fields, and without its other data, IP address or real user.
     */
    public function test_execute_returns_event_fields(): void {
        $exporter = $this->set_up_log();
        $user = $this->create_user();
        $id = $this->log_profile_view($user);
        $context = \core\context\user::instance($user->id);

        $raw = get_log_events::execute();
        $result = external_api::clean_returnvalue(get_log_events::execute_returns(), $raw);

        $this->assertSame([
            'id' => $id,
            'eventname' => '\core\event\user_profile_viewed',
            'component' => 'core',
            'action' => 'viewed',
            'target' => 'user_profile',
            'objecttable' => 'user',
            'objectid' => (int) $user->id,
            'crud' => 'r',
            'edulevel' => 0,
            'contextid' => (int) $context->id,
            'contextlevel' => CONTEXT_USER,
            'contextinstanceid' => (int) $user->id,
            'userid' => (int) $exporter->id,
            'courseid' => 0,
            'relateduserid' => (int) $user->id,
            'anonymous' => false,
            'timecreated' => $result['events'][0]['timecreated'],
            'origin' => 'cli',
        ], $result['events'][0]);
        $this->assertSame(array_keys($result['events'][0]), array_keys($raw['events'][0]));
        $this->assertSame($id, $result['maxid']);
    }

    /**
     * A reader can walk the log page by page with next and hasmore.
     */
    public function test_execute_pages_with_cursor(): void {
        $this->set_up_log();
        $user = $this->create_user();
        $ids = [$this->log_profile_view($user), $this->log_profile_view($user), $this->log_profile_view($user)];

        $first = $this->call(0, 2);
        $second = $this->call($first['next'], 2);

        $this->assertSame([$ids[0], $ids[1]], array_column($first['events'], 'id'));
        $this->assertSame($ids[1], $first['next']);
        $this->assertTrue($first['hasmore']);
        $this->assertSame([$ids[2]], array_column($second['events'], 'id'));
        $this->assertSame($ids[2], $second['next']);
        $this->assertFalse($second['hasmore']);
        $this->assertSame($ids[2], $second['maxid']);

        $third = $this->call($second['next'], 2);
        $this->assertSame(['events' => [], 'next' => $ids[2], 'hasmore' => false, 'maxid' => $ids[2]], $third);
    }

    /**
     * An anonymous event is returned without the user who acted or the user it concerns.
     */
    public function test_execute_withholds_users_of_anonymous_events(): void {
        $this->set_up_log();
        $user = $this->create_user();
        $this->log_profile_view($user, true);

        $event = $this->call()['events'][0];

        $this->assertTrue($event['anonymous']);
        $this->assertNull($event['userid']);
        $this->assertNull($event['relateduserid']);
        $this->assertSame((int) $user->id, $event['objectid']);
    }

    /**
     * When the standard log store is disabled, the call fails with logstore_disabled, even if rows remain.
     */
    public function test_execute_logstore_disabled(): void {
        $this->set_up_log();
        $this->log_profile_view($this->getDataGenerator()->create_user());
        set_config('enabled_stores', '', 'tool_log');

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('logstore_disabled', 'local_arcs'));
        get_log_events::execute();
    }

    /**
     * After Moodle deletes old events, a cursor below the oldest kept event is expired; 0 and a cursor
     * just below the oldest are not.
     */
    public function test_execute_cursor_expired_after_cleanup(): void {
        global $DB;
        $this->set_up_log();
        $user = $this->create_user();
        $ids = [$this->log_profile_view($user), $this->log_profile_view($user), $this->log_profile_view($user)];
        $DB->set_field_select(logstore::TABLE, 'timecreated', time() - 3 * DAYSECS, 'id < :id', ['id' => $ids[2]]);
        set_config('loglifetime', 1, 'logstore_standard');
        ob_start();
        (new \logstore_standard\task\cleanup_task())->execute();
        ob_end_clean();

        $this->assertSame([$ids[2]], array_column($this->call()['events'], 'id'));
        $this->assertSame([$ids[2]], array_column($this->call($ids[2] - 1)['events'], 'id'));

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('cursor_expired', 'local_arcs'));
        get_log_events::execute($ids[0], 10);
    }

    /**
     * A limit above the maximum is refused with limit_exceeded.
     */
    public function test_execute_limit_exceeded(): void {
        $this->set_up_log();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::MAX_LIMIT));
        get_log_events::execute(0, source::MAX_LIMIT + 1);
    }

    /**
     * A limit below 1 is refused.
     */
    public function test_execute_limit_too_small(): void {
        $this->set_up_log();

        $this->expectException(invalid_parameter_exception::class);
        get_log_events::execute(0, 0);
    }

    /**
     * A negative cursor is refused.
     */
    public function test_execute_negative_cursor(): void {
        $this->set_up_log();

        $this->expectException(invalid_parameter_exception::class);
        get_log_events::execute(-1, 10);
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_log_events::execute();
    }
}
