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

use core\exception\moodle_exception;

#[\PHPUnit\Framework\Attributes\CoversClass(reader::class)]
/**
 * Tests for the change log reader.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\local\changelog\reader
 */
final class reader_test extends \advanced_testcase {
    /** @var \frozen_clock The test clock. */
    private \frozen_clock $clock;

    /** @var reader The reader under test. */
    private reader $reader;

    /** @var retention The retention used by the reader. */
    private retention $retention;

    /**
     * Sets up a reader over an empty log, with a frozen clock.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->clock = $this->mock_clock_with_frozen(1700000000);
        $this->retention = new retention($DB, $this->clock);
        $this->reader = new reader($DB, $this->clock, $this->retention);
    }

    /**
     * Records the given number of user changes at the clock's current time.
     *
     * @param int $count How many to record.
     * @return int[] The new rows' ids, ascending.
     */
    private function record(int $count): array {
        global $DB;
        $before = (int) $DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {local_arcs_change}');
        $recorder = new recorder($DB, $this->clock);
        for ($i = 1; $i <= $count; $i++) {
            $recorder->record(entity::USER, $i, operation::UPSERT, $i);
        }
        return array_map('intval', $DB->get_fieldset_select(recorder::TABLE, 'id', 'id > ? ORDER BY id', [$before]));
    }

    /**
     * Pages run in id order, set next to the last id while more are ready, then to the mark.
     */
    public function test_page_walks_the_log(): void {
        $ids = $this->record(5);
        $this->clock->bump(reader::SETTLE_SECONDS);

        $first = $this->reader->page(0, 2);
        $this->assertSame([$ids[0], $ids[1]], array_column($first['changes'], 'id'));
        $this->assertTrue($first['hasmore']);
        $this->assertSame($ids[1], $first['next']);
        $this->assertSame($ids[4], $first['highwatermark']);

        $second = $this->reader->page($first['next'], 2);
        $this->assertSame([$ids[2], $ids[3]], array_column($second['changes'], 'id'));
        $this->assertTrue($second['hasmore']);

        $last = $this->reader->page($second['next'], 2);
        $this->assertSame([$ids[4]], array_column($last['changes'], 'id'));
        $this->assertFalse($last['hasmore']);
        $this->assertSame($ids[4], $last['next']);

        $empty = $this->reader->page($last['next'], 2);
        $this->assertSame([], $empty['changes']);
        $this->assertFalse($empty['hasmore']);
        $this->assertSame($ids[4], $empty['next']);
    }

    /**
     * A full page that ends exactly at the mark says there is no more.
     */
    public function test_full_page_ending_at_mark_has_no_more(): void {
        $ids = $this->record(2);
        $this->clock->bump(reader::SETTLE_SECONDS);

        $page = $this->reader->page(0, 2);

        $this->assertCount(2, $page['changes']);
        $this->assertFalse($page['hasmore']);
        $this->assertSame($ids[1], $page['next']);
    }

    /**
     * Rows newer than the settle window are held back until it passes.
     */
    public function test_page_holds_back_unsettled_rows(): void {
        $old = $this->record(2);
        $this->clock->bump(reader::SETTLE_SECONDS);
        $new = $this->record(1);

        $page = $this->reader->page(0, 10);
        $this->assertSame($old, array_column($page['changes'], 'id'));
        $this->assertSame($old[1], $page['highwatermark']);
        $this->assertSame($old[1], $page['next']);

        $this->clock->bump(reader::SETTLE_SECONDS);
        $page = $this->reader->page($page['next'], 10);
        $this->assertSame($new, array_column($page['changes'], 'id'));
    }

    /**
     * Each row carries its fields.
     */
    public function test_page_row_shape(): void {
        global $DB;
        (new recorder($DB, $this->clock))->record(entity::COURSE_MODULE, 9, operation::DELETE, null, 4);
        $this->clock->bump(reader::SETTLE_SECONDS);

        $row = $this->reader->page(0, 10)['changes'][0];

        $this->assertSame('course_module', $row['entity']);
        $this->assertSame(9, $row['entityid']);
        $this->assertSame('delete', $row['op']);
        $this->assertNull($row['userid']);
        $this->assertSame(4, $row['courseid']);
        $this->assertSame(1700000000, $row['timecreated']);
    }

    /**
     * An empty log gives an empty page at cursor 0.
     */
    public function test_page_on_empty_log(): void {
        $page = $this->reader->page(0, 10);

        $this->assertSame([], $page['changes']);
        $this->assertFalse($page['hasmore']);
        $this->assertSame(0, $page['next']);
        $this->assertSame(0, $page['highwatermark']);
    }

    /**
     * A cursor below the purged-up-to mark is expired; one at the mark is not.
     */
    public function test_page_expired_cursor(): void {
        $old = $this->record(3);
        $this->clock->bump(10 * DAYSECS);
        $this->record(1);
        $this->clock->bump(reader::SETTLE_SECONDS);
        $this->retention->purge(7);

        $page = $this->reader->page($old[2], 10);
        $this->assertCount(1, $page['changes']);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('cursor_expired', 'local_arcs'));
        $this->reader->page($old[1], 10);
    }

    /**
     * Rows missing from the middle of the log (such as after a privacy deletion) do not expire a cursor.
     */
    public function test_gaps_do_not_expire_cursor(): void {
        global $DB;
        $ids = $this->record(3);
        $this->clock->bump(reader::SETTLE_SECONDS);
        $DB->delete_records(recorder::TABLE, ['id' => $ids[1]]);

        $page = $this->reader->page($ids[0], 10);

        $this->assertSame([$ids[2]], array_column($page['changes'], 'id'));
    }

    /**
     * The mark never falls below the purged-up-to mark, even when the log is empty.
     */
    public function test_high_water_mark_after_everything_is_purged(): void {
        $ids = $this->record(2);
        $this->clock->bump(10 * DAYSECS);
        $this->retention->purge(7);

        $this->assertSame($ids[1], $this->reader->high_water_mark());
        $this->assertSame([], $this->reader->page($ids[1], 10)['changes']);
    }

    /**
     * Bounds are the lowest and highest ids, or nulls for an empty log.
     */
    public function test_bounds(): void {
        $this->assertSame(['oldest' => null, 'newest' => null], $this->reader->bounds());

        $ids = $this->record(3);

        $this->assertSame(['oldest' => $ids[0], 'newest' => $ids[2]], $this->reader->bounds());
    }
}
