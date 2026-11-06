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
use local_arcs\local\changelog\entity;
use local_arcs\local\changelog\operation;
use local_arcs\local\changelog\reader;
use local_arcs\local\changelog\recorder;
use local_arcs\tests\exporter_trait;

#[\PHPUnit\Framework\Attributes\CoversClass(get_changes::class)]
/**
 * Tests for local_arcs_get_changes.
 *
 * The function has no ids parameter: readers follow the change log by cursor only.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_changes
 */
final class get_changes_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @return array
     */
    private function call(int $after = 0, int $limit = reader::DEFAULT_LIMIT): array {
        return external_api::clean_returnvalue(get_changes::execute_returns(), get_changes::execute($after, $limit));
    }

    /**
     * Records the given number of settled user changes.
     *
     * @param int $count How many.
     * @return int[] Their ids, ascending.
     */
    private function record_settled(int $count): array {
        global $DB;
        $clock = $this->mock_clock_with_frozen(1700000000);
        $recorder = new recorder($DB, $clock);
        for ($i = 1; $i <= $count; $i++) {
            $recorder->record(entity::USER, $i, operation::UPSERT, $i);
        }
        $clock->bump(reader::SETTLE_SECONDS);
        return array_map('intval', $DB->get_fieldset_sql('SELECT id FROM {local_arcs_change} ORDER BY id'));
    }

    /**
     * On an empty site, the page is empty and the cursor stays at 0.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $result = $this->call();

        $this->assertSame(['changes' => [], 'next' => 0, 'hasmore' => false, 'highwatermark' => 0], $result);
    }

    /**
     * A reader can walk the log page by page with next and hasmore.
     */
    public function test_execute_pages_with_cursor(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $ids = $this->record_settled(3);

        $first = $this->call(0, 2);
        $second = $this->call($first['next'], 2);

        $this->assertSame([$ids[0], $ids[1]], array_column($first['changes'], 'id'));
        $this->assertTrue($first['hasmore']);
        $this->assertSame([$ids[2]], array_column($second['changes'], 'id'));
        $this->assertFalse($second['hasmore']);
        $this->assertSame($ids[2], $second['next']);
        $this->assertSame(
            ['id' => $ids[0], 'entity' => 'user', 'entityid' => 1, 'op' => 'upsert', 'userid' => 1, 'courseid' => null,
                'timecreated' => 1700000000],
            $first['changes'][0]
        );
    }

    /**
     * A limit above the maximum is refused with limit_exceeded.
     */
    public function test_execute_limit_exceeded(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', reader::MAX_LIMIT));
        get_changes::execute(0, reader::MAX_LIMIT + 1);
    }

    /**
     * A limit below 1 is refused.
     */
    public function test_execute_limit_too_small(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(invalid_parameter_exception::class);
        get_changes::execute(0, 0);
    }

    /**
     * A negative cursor is refused.
     */
    public function test_execute_negative_cursor(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(invalid_parameter_exception::class);
        get_changes::execute(-1, 10);
    }

    /**
     * A cursor below the purged-up-to mark is refused with cursor_expired.
     */
    public function test_execute_cursor_expired(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        set_config('purgedupto', 100, 'local_arcs');

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('cursor_expired', 'local_arcs'));
        get_changes::execute(99, 10);
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_changes::execute();
    }
}
