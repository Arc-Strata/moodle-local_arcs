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

namespace local_arcs\check;

use core\check\result;
use local_arcs\local\changelog\entity;
use local_arcs\local\changelog\operation;
use local_arcs\local\changelog\recorder;
use local_arcs\local\settings\settings;

#[\PHPUnit\Framework\Attributes\CoversClass(webservices::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(restprotocol::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(logstore::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(changelog::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_arcs_status_checks')]
/**
 * Tests for the status checks.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\check\webservices
 * @covers     \local_arcs\check\restprotocol
 * @covers     \local_arcs\check\service
 * @covers     \local_arcs\check\logstore
 * @covers     \local_arcs\check\changelog
 * @covers     ::local_arcs_status_checks
 */
final class checks_test extends \advanced_testcase {
    /**
     * The status report lists the plugin's six checks.
     */
    public function test_status_report_lists_checks(): void {
        $this->resetAfterTest();

        $refs = array_map(fn(\core\check\check $check): string => $check->get_ref(), \core\check\manager::get_status_checks());

        foreach (['webservices', 'restprotocol', 'service', 'token', 'logstore', 'changelog'] as $id) {
            $this->assertContains('local_arcs_' . $id, $refs);
        }
    }

    /**
     * Each check has a name and an action link.
     */
    public function test_checks_have_names_and_links(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/arcs/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        foreach (local_arcs_status_checks() as $check) {
            $this->assertNotEmpty($check->get_name());
            $this->assertInstanceOf(\core\output\action_link::class, $check->get_action_link());
            $this->assertStringNotContainsString('{$a', $check->get_action_link()->text);
        }
    }

    /**
     * The web services check fails until web services are enabled.
     */
    public function test_webservices(): void {
        global $CFG;
        $this->resetAfterTest();

        set_config('enablewebservices', 0);
        $this->assertSame(result::ERROR, (new webservices($CFG))->get_result()->get_status());

        set_config('enablewebservices', 1);
        $this->assertSame(result::OK, (new webservices($CFG))->get_result()->get_status());
    }

    /**
     * The REST protocol check fails until REST is among the enabled protocols.
     */
    public function test_restprotocol(): void {
        global $CFG;
        $this->resetAfterTest();

        set_config('webserviceprotocols', '');
        $this->assertSame(result::ERROR, (new restprotocol($CFG))->get_result()->get_status());

        set_config('webserviceprotocols', 'soap');
        $this->assertSame(result::ERROR, (new restprotocol($CFG))->get_result()->get_status());

        set_config('webserviceprotocols', 'soap,rest');
        $this->assertSame(result::OK, (new restprotocol($CFG))->get_result()->get_status());
    }

    /**
     * The service check fails until the Arcs connector service is enabled; its link edits that service.
     */
    public function test_service(): void {
        global $DB;
        $this->resetAfterTest();
        $check = new service($DB);
        $serviceid = $DB->get_field('external_services', 'id', ['shortname' => service::SHORTNAME], MUST_EXIST);

        $this->assertSame(result::ERROR, $check->get_result()->get_status());

        $DB->set_field('external_services', 'enabled', 1, ['id' => $serviceid]);
        $this->assertSame(result::OK, $check->get_result()->get_status());
        $this->assertSame((int) $serviceid, (int) $check->get_action_link()->url->get_param('id'));
    }

    /**
     * The log store check warns while the standard log store is disabled.
     */
    public function test_logstore(): void {
        global $DB;
        $this->resetAfterTest();
        $check = new logstore(new \local_arcs\local\logstore($DB));

        set_config('enabled_stores', '', 'tool_log');
        $this->assertSame(result::WARNING, $check->get_result()->get_status());

        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        $this->assertSame(result::OK, $check->get_result()->get_status());
    }

    /**
     * The change log check reports the number of changes, the oldest and the retention, for information.
     */
    public function test_changelog(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->delete_records(recorder::TABLE);
        set_config('retentiondays', 14, 'local_arcs');
        $check = new changelog($DB, new settings());

        $result = $check->get_result();
        $this->assertSame(result::INFO, $result->get_status());
        $this->assertSame(get_string('checkchangelog_empty', 'local_arcs', 14), $result->get_summary());

        $recorder = new recorder($DB, $this->mock_clock_with_frozen(1700000000));
        $recorder->record(entity::USER, 1, operation::UPSERT, 1);
        $recorder->record(entity::USER, 2, operation::UPSERT, 2);

        $result = $check->get_result();
        $this->assertSame(result::INFO, $result->get_status());
        $this->assertSame(
            get_string('checkchangelog_info', 'local_arcs', ['changes' => 2, 'oldest' => userdate(1700000000), 'days' => 14]),
            $result->get_summary()
        );
    }
}
