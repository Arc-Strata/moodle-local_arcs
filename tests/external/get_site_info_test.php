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

use core_external\external_api;
use local_arcs\local\changelog\entity;
use local_arcs\local\changelog\operation;
use local_arcs\local\changelog\reader;
use local_arcs\local\changelog\recorder;
use local_arcs\local\contract;
use local_arcs\tests\exporter_trait;

#[\PHPUnit\Framework\Attributes\CoversClass(get_site_info::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\site_info::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\logstore::class)]
/**
 * Tests for local_arcs_get_site_info.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_site_info
 * @covers     \local_arcs\local\site_info
 * @covers     \local_arcs\local\logstore
 */
final class get_site_info_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @return array
     */
    private function call(): array {
        return external_api::clean_returnvalue(get_site_info::execute_returns(), get_site_info::execute());
    }

    /**
     * On an empty site, every field is present and describes this site.
     */
    public function test_execute_on_empty_site(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->set_exporter();

        $result = $this->call();

        $this->assertSame(contract::VERSION, $result['contractversion']);
        $this->assertSame((int) get_config('local_arcs', 'version'), $result['pluginversion']);
        $this->assertNotEmpty($result['pluginrelease']);
        $this->assertSame($CFG->release, $result['moodlerelease']);
        $this->assertSame((string) $CFG->branch, $result['moodlebranch']);
        $this->assertSame(get_site_identifier(), $result['siteidentifier']);
        $this->assertSame($CFG->wwwroot, $result['wwwroot']);
        $this->assertSame(\core_date::get_server_timezone(), $result['timezone']);
        $this->assertNull($result['changelogoldestid']);
        $this->assertNull($result['changelognewestid']);
        $this->assertSame(0, $result['changeloghighwatermark']);
        $this->assertSame(0, $result['changelogpurgedupto']);
        $this->assertSame(30, $result['changelogretentiondays']);
    }

    /**
     * The change log's bounds, high-water mark, purge mark and retention are reported.
     */
    public function test_execute_reports_change_log(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        set_config('retentiondays', 7, 'local_arcs');
        $clock = $this->mock_clock_with_frozen(1700000000);
        $recorder = new recorder($DB, $clock);
        $recorder->record(entity::USER, 1, operation::UPSERT, 1);
        $clock->bump(reader::SETTLE_SECONDS);
        $recorder->record(entity::USER, 2, operation::UPSERT, 2);
        [$first, $second] = array_map('intval', $DB->get_fieldset_sql('SELECT id FROM {local_arcs_change} ORDER BY id'));
        set_config('purgedupto', $first - 1, 'local_arcs');

        $result = $this->call();

        $this->assertSame($first, $result['changelogoldestid']);
        $this->assertSame($second, $result['changelognewestid']);
        $this->assertSame($first, $result['changeloghighwatermark']);
        $this->assertSame($first - 1, $result['changelogpurgedupto']);
        $this->assertSame(7, $result['changelogretentiondays']);
    }

    /**
     * The site timezone setting is reported.
     */
    public function test_execute_reports_site_timezone(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $this->setTimezone('Australia/Perth');

        $this->assertSame('Australia/Perth', $this->call()['timezone']);
    }

    /**
     * The standard log store's state and lifetime are reported when it is enabled.
     */
    public function test_execute_reports_log_store_enabled(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        set_config('enabled_stores', 'logstore_database,logstore_standard', 'tool_log');
        set_config('loglifetime', 365, 'logstore_standard');

        $result = $this->call();

        $this->assertTrue($result['logstoreenabled']);
        $this->assertSame(365, $result['logstorelifetime']);
    }

    /**
     * A disabled standard log store is reported, including when no store is enabled.
     */
    public function test_execute_reports_log_store_disabled(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        set_config('enabled_stores', 'logstore_database', 'tool_log');
        $this->assertFalse($this->call()['logstoreenabled']);

        set_config('enabled_stores', '', 'tool_log');
        $this->assertFalse($this->call()['logstoreenabled']);
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_site_info::execute();
    }
}
