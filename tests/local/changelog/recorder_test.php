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

#[\PHPUnit\Framework\Attributes\CoversClass(recorder::class)]
/**
 * Tests for the change log recorder.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\local\changelog\recorder
 */
final class recorder_test extends \advanced_testcase {
    /**
     * A change is written with its ids and the clock's time.
     */
    public function test_record_writes_row(): void {
        global $DB;
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(1700000000);

        (new recorder($DB, $clock))->record(entity::GRADE, 42, operation::DELETE, 7, 3);

        $row = $DB->get_record(recorder::TABLE, []);
        $this->assertSame('grade', $row->entity);
        $this->assertEquals(42, $row->entityid);
        $this->assertSame('delete', $row->op);
        $this->assertEquals(7, $row->userid);
        $this->assertEquals(3, $row->courseid);
        $this->assertEquals(1700000000, $row->timecreated);
    }

    /**
     * A record with no user or course is written with nulls.
     */
    public function test_record_without_user_or_course(): void {
        global $DB;
        $this->resetAfterTest();

        (new recorder($DB, $this->mock_clock_with_frozen()))->record(entity::COURSE_CATEGORY, 5, operation::UPSERT);

        $row = $DB->get_record(recorder::TABLE, []);
        $this->assertNull($row->userid);
        $this->assertNull($row->courseid);
    }

    /**
     * A failed write is reported through debugging() and never thrown.
     */
    public function test_record_reports_failure_without_throwing(): void {
        $db = $this->createMock(\moodle_database::class);
        $db->method('insert_record')->willThrowException(new \dml_write_exception('simulated'));

        (new recorder($db, $this->mock_clock_with_frozen()))->record(entity::USER, 1, operation::UPSERT, 1);

        $this->assertDebuggingCalled();
    }
}
