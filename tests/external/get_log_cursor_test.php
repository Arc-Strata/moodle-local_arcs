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
use local_arcs\tests\exporter_trait;

#[\PHPUnit\Framework\Attributes\CoversClass(get_log_cursor::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(logstore::class)]
/**
 * Tests for local_arcs_get_log_cursor.
 *
 * Log rows are inserted directly, so that each test controls both their times and the order
 * of their ids, as the store's buffered writes can make them differ.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_log_cursor
 * @covers     \local_arcs\local\logstore
 */
final class get_log_cursor_test extends \advanced_testcase {
    use exporter_trait;

    /** @var int The time the tests ask for a cursor at. */
    private const TIME = 1790000000;

    /**
     * Enables the standard log store and acts as the exporter with an empty log.
     */
    private function set_up_log(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        $this->set_exporter();
        $DB->delete_records(logstore::TABLE);
    }

    /**
     * Writes a log row for an event that happened at a given time, as the store would write it now.
     *
     * @param int $timecreated When the event happened.
     * @return int The row's id.
     */
    private function write_event(int $timecreated): int {
        global $DB;
        return (int) $DB->insert_record(logstore::TABLE, (object) [
            'eventname' => '\core\event\user_profile_viewed',
            'component' => 'core',
            'action' => 'viewed',
            'target' => 'user_profile',
            'crud' => 'r',
            'edulevel' => 0,
            'contextid' => \core\context\system::instance()->id,
            'contextlevel' => CONTEXT_SYSTEM,
            'contextinstanceid' => 0,
            'userid' => 2,
            'anonymous' => 0,
            'timecreated' => $timecreated,
        ]);
    }

    /**
     * Calls the function as the web service layer would and returns the cursor.
     *
     * @param int $time Unix time.
     * @return int
     */
    private function call(int $time = self::TIME): int {
        return external_api::clean_returnvalue(get_log_cursor::execute_returns(), get_log_cursor::execute($time))['after'];
    }

    /**
     * With an empty log, the cursor is 0.
     */
    public function test_execute_on_empty_log(): void {
        $this->set_up_log();

        $this->assertSame(0, $this->call());
    }

    /**
     * Asked for a time before every event, the cursor is just below the first event.
     */
    public function test_execute_before_every_event(): void {
        $this->set_up_log();
        $first = $this->write_event(self::TIME + 10);
        $this->write_event(self::TIME + 20);

        $this->assertSame($first - 1, $this->call());
        $this->assertSame($first - 1, $this->call(0));
    }

    /**
     * Asked for a time after every event, the cursor is the newest event.
     */
    public function test_execute_after_every_event(): void {
        $this->set_up_log();
        $this->write_event(self::TIME - 20);
        $last = $this->write_event(self::TIME - 1);

        $this->assertSame($last, $this->call());
    }

    /**
     * Within the log, the cursor is just below the first event at or after the time.
     */
    public function test_execute_within_the_log(): void {
        $this->set_up_log();
        $this->write_event(self::TIME - HOURSECS);
        $this->write_event(self::TIME - 1);
        $first = $this->write_event(self::TIME);
        $this->write_event(self::TIME + HOURSECS);

        $this->assertSame($first - 1, $this->call());
    }

    /**
     * Events are not in time order by id: an event written before an earlier one that a long
     * process held back is not missed, and an event from before the time written late is read too.
     */
    public function test_execute_when_events_are_written_out_of_time_order(): void {
        $this->set_up_log();
        $this->write_event(self::TIME - HOURSECS);
        $first = $this->write_event(self::TIME + 5);
        $this->write_event(self::TIME - 5);
        $this->write_event(self::TIME);

        $this->assertSame($first - 1, $this->call());
    }

    /**
     * The cursor is accepted by local_arcs_get_log_events, which returns every event from the time on.
     */
    public function test_cursor_reads_every_event_from_the_time(): void {
        $this->set_up_log();
        $this->write_event(self::TIME - DAYSECS);
        $after = [$this->write_event(self::TIME + 5), $this->write_event(self::TIME), $this->write_event(self::TIME + DAYSECS)];

        $events = get_log_events::execute($this->call())['events'];

        $this->assertEmpty(array_diff($after, array_column($events, 'id')));
    }

    /**
     * A negative time is refused.
     */
    public function test_execute_refuses_negative_time(): void {
        $this->set_up_log();

        $this->expectException(invalid_parameter_exception::class);
        get_log_cursor::execute(-1);
    }

    /**
     * When the standard log store is off, the call fails rather than return a cursor.
     */
    public function test_execute_when_log_store_disabled(): void {
        $this->set_up_log();
        $this->write_event(self::TIME);
        set_config('enabled_stores', '', 'tool_log');

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('logstore_disabled', 'local_arcs'));
        get_log_cursor::execute(self::TIME);
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_log_cursor::execute(self::TIME);
    }
}
