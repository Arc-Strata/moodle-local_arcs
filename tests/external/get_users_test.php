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
use local_arcs\local\source\source;
use local_arcs\tests\exporter_trait;

#[\PHPUnit\Framework\Attributes\CoversClass(get_users::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(state_function::class)]
/**
 * Tests for local_arcs_get_users.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_users
 * @covers     \local_arcs\external\state_function
 */
final class get_users_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @param int[] $ids Ids to fetch.
     * @return array
     */
    private function call(int $after = 0, int $limit = source::DEFAULT_LIMIT, array $ids = []): array {
        return external_api::clean_returnvalue(get_users::execute_returns(), get_users::execute($after, $limit, $ids));
    }

    /**
     * Finds a user's row in a result.
     *
     * @param array $result The cleaned result.
     * @param int $id The user id.
     * @return array
     */
    private function row(array $result, int $id): array {
        $rows = array_values(array_filter($result['users'], fn(array $row): bool => $row['id'] === $id));
        $this->assertCount(1, $rows);
        return $rows[0];
    }

    /**
     * On a site with no users of its own, the guest, the admin and the exporter are returned.
     */
    public function test_execute_on_empty_site(): void {
        global $CFG;
        $this->resetAfterTest();
        $exporter = $this->set_exporter();

        $result = $this->call();

        $this->assertSame([(int) $CFG->siteguest, 2, (int) $exporter->id], array_column($result['users'], 'id'));
        $this->assertNull($result['next']);
    }

    /**
     * Every always-exported field is returned, raw, with the default optional fields.
     */
    public function test_execute_returns_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $user = $this->getDataGenerator()->create_user([
            'username' => 'ada',
            'idnumber' => 'E100',
            'email' => 'ada@example.com',
            'lastname' => 'Lovelace',
            'department' => 'R&D',
            'institution' => 'Analytical',
            'city' => 'London',
            'country' => 'GB',
            'timezone' => 'Europe/London',
            'lang' => 'en',
            'phone1' => '0123',
            'firstaccess' => 1700000001,
            'lastaccess' => 1700000002,
            'lastlogin' => 1700000003,
        ]);
        // Set directly: the generator cleans names, and the export must not.
        $DB->set_field('user', 'firstname', 'Ada <b>', ['id' => $user->id]);

        $row = $this->row($this->call(), (int) $user->id);

        $this->assertSame([
            'id' => (int) $user->id,
            'username' => 'ada',
            'idnumber' => 'E100',
            'email' => 'ada@example.com',
            'auth' => 'manual',
            'firstname' => 'Ada <b>',
            'lastname' => 'Lovelace',
            'timezone' => 'Europe/London',
            'lang' => 'en',
            'department' => 'R&D',
            'institution' => 'Analytical',
            'city' => 'London',
            'country' => 'GB',
            'suspended' => false,
            'deleted' => false,
            'confirmed' => true,
            'firstaccess' => 1700000001,
            'lastaccess' => 1700000002,
            'lastlogin' => 1700000003,
            'timecreated' => (int) $user->timecreated,
            'timemodified' => (int) $user->timemodified,
            'profilefields' => [],
        ], $row);
    }

    /**
     * A standard field and a custom field that an admin has not selected are withheld.
     */
    public function test_execute_withholds_unselected_fields(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $generator = $this->getDataGenerator();
        $team = $generator->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'team', 'name' => 'Team']);
        $generator->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'secret', 'name' => 'Secret']);
        $user = $generator->create_user(['city' => 'Paris', 'profile_field_team' => 'Blue', 'profile_field_secret' => 'x']);
        set_config('optionalfields', 'department,profile_field_team', 'local_arcs');

        $row = $this->row($this->call(), (int) $user->id);

        $this->assertArrayHasKey('department', $row);
        $this->assertArrayNotHasKey('city', $row);
        $this->assertSame([['fieldid' => (int) $team->id, 'shortname' => 'team', 'value' => 'Blue']], $row['profilefields']);
    }

    /**
     * With no optional fields selected, none are returned.
     */
    public function test_execute_with_no_optional_fields(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $user = $this->getDataGenerator()->create_user();
        set_config('optionalfields', '', 'local_arcs');

        $row = $this->row($this->call(), (int) $user->id);

        $this->assertArrayNotHasKey('department', $row);
        $this->assertArrayNotHasKey('country', $row);
    }

    /**
     * Suspended and deleted users are returned with their flags; a deleted user's row is Moodle's scrubbed one.
     */
    public function test_execute_suspended_and_deleted_users(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $deleted = $this->getDataGenerator()->create_user(['email' => 'gone@example.com']);
        delete_user($deleted);

        $result = $this->call(0, source::DEFAULT_LIMIT, [(int) $suspended->id, (int) $deleted->id]);

        $this->assertTrue($this->row($result, (int) $suspended->id)['suspended']);
        $row = $this->row($result, (int) $deleted->id);
        $this->assertTrue($row['deleted']);
        $this->assertSame($DB->get_field('user', 'email', ['id' => $deleted->id]), $row['email']);
        $this->assertNotSame('gone@example.com', $row['email']);
    }

    /**
     * A reader can page through users with next.
     */
    public function test_execute_pages_with_cursor(): void {
        $this->resetAfterTest();
        $exporter = $this->set_exporter();
        $user = $this->getDataGenerator()->create_user();

        $first = $this->call(0, 2);
        $second = $this->call($first['next'], 2);

        $this->assertCount(2, $first['users']);
        $this->assertSame([(int) $exporter->id, (int) $user->id], array_column($second['users'], 'id'));
        $this->assertNull($second['next']);
    }

    /**
     * With ids, exactly the existing users are returned and after is ignored.
     */
    public function test_execute_by_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();

        $result = $this->call((int) $two->id, 10, [(int) $two->id, 999999, (int) $one->id, (int) $one->id]);

        $this->assertSame([(int) $one->id, (int) $two->id], array_column($result['users'], 'id'));
        $this->assertNull($result['next']);
    }

    /**
     * More ids than the limit are refused with limit_exceeded.
     */
    public function test_execute_too_many_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', 2));
        get_users::execute(0, 2, [1, 2, 3]);
    }

    /**
     * A limit above the maximum is refused with limit_exceeded.
     */
    public function test_execute_limit_exceeded(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::MAX_LIMIT));
        get_users::execute(0, source::MAX_LIMIT + 1);
    }

    /**
     * A limit below 1 is refused.
     */
    public function test_execute_limit_too_small(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(invalid_parameter_exception::class);
        get_users::execute(0, 0);
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_users::execute();
    }
}
