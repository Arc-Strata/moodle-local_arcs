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

#[\PHPUnit\Framework\Attributes\CoversClass(token::class)]
/**
 * Tests for the token status check.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\check\token
 */
final class token_test extends \advanced_testcase {
    /**
     * Creates a user who may use the service, with a token for it.
     *
     * @param bool $authorise Whether to authorise the user on the service.
     * @param int $validuntil When the token expires; 0 for never.
     * @return \stdClass The user.
     */
    private function create_token_holder(bool $authorise = true, int $validuntil = 0): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/webservice/lib.php');

        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $roleid = $generator->create_role();
        $context = \core\context\system::instance();
        assign_capability('local/arcs:export', CAP_ALLOW, $roleid, $context->id);
        assign_capability('webservice/rest:use', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $user->id, $context->id);

        $service = $DB->get_record('external_services', ['shortname' => service::SHORTNAME], '*', MUST_EXIST);
        if ($authorise) {
            (new \webservice())->add_ws_authorised_user((object) ['externalserviceid' => $service->id, 'userid' => $user->id]);
        }
        \core_external\util::generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $user->id, $context, $validuntil);
        return $user;
    }

    /**
     * Returns the token check's result.
     *
     * @return result
     */
    private function token_result(): result {
        global $DB;
        return (new token($DB, \core\di::get(\core\clock::class)))->get_result();
    }

    /**
     * With no token, the token check fails and says what a usable token needs.
     */
    public function test_token_none(): void {
        $this->resetAfterTest();

        $result = $this->token_result();

        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertSame(get_string('checktoken_none', 'local_arcs'), $result->get_summary());
        $this->assertSame(get_string('checktoken_details', 'local_arcs'), $result->get_details());
    }

    /**
     * A token whose user is authorised and holds both capabilities is usable, and only counted.
     */
    public function test_token_usable(): void {
        $this->resetAfterTest();
        $this->create_token_holder();
        $this->create_token_holder();

        $result = $this->token_result();

        $this->assertSame(result::OK, $result->get_status());
        $this->assertSame(get_string('checktoken_ok', 'local_arcs', 2), $result->get_summary());
    }

    /**
     * A token is unusable when it has expired, its user is suspended, not authorised or lacks a
     * capability; the check counts the tokens it found.
     */
    public function test_token_unusable(): void {
        global $DB;
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(1700000000);
        $this->create_token_holder(true, 1700000000 - 1);
        $suspended = $this->create_token_holder();
        $DB->set_field('user', 'suspended', 1, ['id' => $suspended->id]);
        $this->create_token_holder(false);
        $nocapability = $this->create_token_holder();
        $roleid = $DB->get_field('role_assignments', 'roleid', ['userid' => $nocapability->id], MUST_EXIST);
        unassign_capability('local/arcs:export', $roleid);

        $result = (new token($DB, $clock))->get_result();

        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertSame(get_string('checktoken_unusable', 'local_arcs', 4), $result->get_summary());
    }

    /**
     * An authorisation that has expired makes the token unusable.
     */
    public function test_token_authorisation_expired(): void {
        global $DB;
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(1700000000);
        $user = $this->create_token_holder();
        $DB->set_field('external_services_users', 'validuntil', 1700000000 - 1, ['userid' => $user->id]);

        $this->assertSame(result::ERROR, (new token($DB, $clock))->get_result()->get_status());
    }

    /**
     * When the service is open to all users with the capability, a token needs no authorisation.
     */
    public function test_token_unrestricted_service(): void {
        global $DB;
        $this->resetAfterTest();
        $this->create_token_holder(false);
        $DB->set_field('external_services', 'restrictedusers', 0, ['shortname' => service::SHORTNAME]);

        $this->assertSame(result::OK, $this->token_result()->get_status());
    }
}
