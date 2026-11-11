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

#[\PHPUnit\Framework\Attributes\CoversClass(retention::class)]
/**
 * Tests for change log retention.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\local\changelog\retention
 */
final class retention_test extends \advanced_testcase {
    /**
     * Rows older than the retention period are deleted, newer ones kept, and the mark recorded.
     */
    public function test_purge_deletes_old_rows_and_records_mark(): void {
        global $DB;
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(1700000000);
        $recorder = new recorder($DB, $clock);
        $recorder->record(entity::USER, 1, operation::UPSERT, 1);
        $recorder->record(entity::USER, 2, operation::UPSERT, 2);
        $clock->bump(8 * DAYSECS);
        $recorder->record(entity::USER, 3, operation::UPSERT, 3);
        $ids = array_map('intval', $DB->get_fieldset_sql('SELECT id FROM {local_arcs_change} ORDER BY id'));
        $retention = new retention($DB, $clock);

        $retention->purge(7);

        $this->assertSame([$ids[2]], array_map('intval', $DB->get_fieldset_sql('SELECT id FROM {local_arcs_change}')));
        $this->assertSame($ids[1], $retention->purged_up_to());
    }

    /**
     * Every row up to the mark goes, even one recorded later than the cutoff.
     */
    public function test_purge_deletes_a_contiguous_prefix(): void {
        global $DB;
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(1700000000);
        $recorder = new recorder($DB, $clock);
        $recorder->record(entity::USER, 1, operation::UPSERT, 1);
        $recorder->record(entity::USER, 2, operation::UPSERT, 2);
        $ids = array_map('intval', $DB->get_fieldset_sql('SELECT id FROM {local_arcs_change} ORDER BY id'));
        // The first row commits late: it has a lower id but a newer time.
        $DB->set_field(recorder::TABLE, 'timecreated', 1700000000 + 5 * DAYSECS, ['id' => $ids[0]]);
        $clock->bump(8 * DAYSECS);

        (new retention($DB, $clock))->purge(7);

        $this->assertSame(0, $DB->count_records(recorder::TABLE));
    }

    /**
     * Nothing old enough means nothing is deleted and the mark stays at 0.
     */
    public function test_purge_with_nothing_old(): void {
        global $DB;
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(1700000000);
        (new recorder($DB, $clock))->record(entity::USER, 1, operation::UPSERT, 1);
        $retention = new retention($DB, $clock);

        $retention->purge(7);

        $this->assertSame(1, $DB->count_records(recorder::TABLE));
        $this->assertSame(0, $retention->purged_up_to());
    }

    /**
     * The mark never moves backwards.
     */
    public function test_purge_mark_never_decreases(): void {
        global $DB;
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(1700000000);
        (new recorder($DB, $clock))->record(entity::USER, 1, operation::UPSERT, 1);
        $retention = new retention($DB, $clock);
        set_config('purgedupto', 1000000, 'local_arcs');
        $clock->bump(8 * DAYSECS);

        $retention->purge(7);

        $this->assertSame(1000000, $retention->purged_up_to());
    }
}
