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

namespace local_arcs\task;

use local_arcs\local\changelog\entity;
use local_arcs\local\changelog\operation;
use local_arcs\local\changelog\recorder;

#[\PHPUnit\Framework\Attributes\CoversClass(cleanup_changes::class)]
/**
 * Tests for the change log cleanup task.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\task\cleanup_changes
 */
final class cleanup_changes_test extends \advanced_testcase {
    /**
     * The task deletes rows older than the retention setting.
     */
    public function test_execute_uses_retention_setting(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('retentiondays', 14, 'local_arcs');
        $clock = $this->mock_clock_with_frozen(1700000000);
        $recorder = new recorder($DB, $clock);
        $recorder->record(entity::USER, 1, operation::UPSERT, 1);
        $clock->bump(10 * DAYSECS);
        $recorder->record(entity::USER, 2, operation::UPSERT, 2);
        $clock->bump(5 * DAYSECS);

        (new cleanup_changes())->execute();

        $this->assertEquals([2], $DB->get_fieldset_sql('SELECT entityid FROM {local_arcs_change}'));
    }

    /**
     * The task is registered and has a name.
     */
    public function test_task_is_scheduled(): void {
        $task = \core\task\manager::get_scheduled_task(cleanup_changes::class);

        $this->assertInstanceOf(cleanup_changes::class, $task);
        $this->assertNotEmpty($task->get_name());
    }
}
