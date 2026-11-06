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

namespace local_arcs;

use local_arcs\tests\changes_trait;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/definelib.php');
require_once($CFG->dirroot . '/user/externallib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
/**
 * Tests that core actions on users and profile fields record change log rows.
 *
 * Each test performs the real core action; none calls the observer directly.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\observer
 */
final class observer_test extends \advanced_testcase {
    use changes_trait;

    /**
     * Resets the database the slow way, without rolling back a transaction around the test.
     *
     * The observers are external: they run only once no transaction is open, so the
     * test must not run inside the transaction PHPUnit opens on PostgreSQL.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
    }

    /**
     * Creating a user, as core_user_create_users does, records a user upsert.
     */
    public function test_user_created(): void {
        global $DB;
        $this->setAdminUser();
        $this->clear_changes();

        \core_user_external::create_users([[
            'username' => 'ada',
            'password' => 'Arcs-test-1234',
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'email' => 'ada@example.com',
        ]]);

        $userid = (int) $DB->get_field('user', 'id', ['username' => 'ada']);
        $this->assertSame([$this->change('user', $userid, 'upsert', $userid)], $this->changes());
    }

    /**
     * Updating a user, as core_user_update_users does, records a user upsert.
     */
    public function test_user_updated(): void {
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $this->clear_changes();

        \core_user_external::update_users([['id' => $user->id, 'department' => 'Sales']]);

        $this->assertSame([$this->change('user', (int) $user->id, 'upsert', (int) $user->id)], $this->changes());
    }

    /**
     * Changing a user's custom profile field values, as core_user_update_users does, records a user upsert.
     */
    public function test_profile_values_updated(): void {
        $this->setAdminUser();
        $this->getDataGenerator()->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'team', 'name' => 'Team']);
        $user = $this->getDataGenerator()->create_user();
        $this->clear_changes();

        \core_user_external::update_users([
            ['id' => $user->id, 'customfields' => [['type' => 'team', 'value' => 'Blue']]],
        ]);

        $this->assertSame([$this->change('user', (int) $user->id, 'upsert', (int) $user->id)], $this->changes());
    }

    /**
     * Deleting a user records a user delete.
     */
    public function test_user_deleted(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->clear_changes();

        delete_user($user);

        $this->assertSame([$this->change('user', (int) $user->id, 'delete', (int) $user->id)], $this->changes());
    }

    /**
     * A user created in a transaction that rolls back records nothing.
     */
    public function test_rolled_back_change_is_not_recorded(): void {
        global $DB;
        $this->clear_changes();

        $transaction = $DB->start_delegated_transaction();
        $this->getDataGenerator()->create_user();
        try {
            $transaction->rollback(new \moodle_exception('test'));
        } catch (\moodle_exception $e) {
            $this->assertSame('test', $e->errorcode);
        }

        $this->assertSame([], $this->changes());
    }

    /**
     * Defining a new profile field records a profile field upsert.
     */
    public function test_profile_field_created(): void {
        global $DB;
        $category = $this->getDataGenerator()->create_custom_profile_field_category(['name' => 'Work']);
        $this->clear_changes();

        (new \profile_define_base())->define_save((object) [
            'datatype' => 'text',
            'shortname' => 'team',
            'name' => 'Team',
            'description' => '',
            'categoryid' => $category->id,
        ]);

        $fieldid = (int) $DB->get_field('user_info_field', 'id', ['shortname' => 'team']);
        $this->assertSame([$this->change('profile_field', $fieldid, 'upsert')], $this->changes());
    }

    /**
     * Changing a profile field definition records a profile field upsert.
     */
    public function test_profile_field_updated(): void {
        $field = $this->getDataGenerator()->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'team',
            'name' => 'Team']);
        $this->clear_changes();

        $field->name = 'Squad';
        (new \profile_define_base())->define_save($field);

        $this->assertSame([$this->change('profile_field', (int) $field->id, 'upsert')], $this->changes());
    }

    /**
     * Deleting a profile field records a profile field delete.
     */
    public function test_profile_field_deleted(): void {
        $field = $this->getDataGenerator()->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'team',
            'name' => 'Team']);
        $this->clear_changes();

        profile_delete_field($field->id);

        $this->assertSame([$this->change('profile_field', (int) $field->id, 'delete')], $this->changes());
    }
}
