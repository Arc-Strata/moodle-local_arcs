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

namespace local_arcs\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\metadata\types\external_location;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\manager;
use local_arcs\external\get_users;
use local_arcs\local\changelog\entity;
use local_arcs\local\changelog\operation;
use local_arcs\local\changelog\recorder;

#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
/**
 * Tests for the local_arcs privacy provider.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass A user the change log names. */
    private \stdClass $user;

    /** @var \stdClass Another user the change log names. */
    private \stdClass $other;

    /**
     * Creates two users with change log rows, and one row naming no user.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->user = $this->getDataGenerator()->create_user();
        $this->other = $this->getDataGenerator()->create_user();
        // Creating users records changes on databases where PHPUnit does not wrap the test in a transaction.
        $DB->delete_records(recorder::TABLE);
        $recorder = new recorder($DB, $this->mock_clock_with_frozen(1700000000));
        $recorder->record(entity::USER, $this->user->id, operation::UPSERT, $this->user->id);
        $recorder->record(entity::GRADE, 77, operation::DELETE, $this->user->id, 5);
        $recorder->record(entity::USER, $this->other->id, operation::UPSERT, $this->other->id);
        $recorder->record(entity::COURSE, 5, operation::UPSERT, null, 5);
    }

    /**
     * Counts the change log rows naming a user.
     *
     * @param \stdClass $user The user.
     * @return int
     */
    private function count_rows(\stdClass $user): int {
        global $DB;
        return $DB->count_records(recorder::TABLE, ['userid' => $user->id]);
    }

    /**
     * The plugin is compliant with the privacy API.
     */
    public function test_component_is_compliant(): void {
        $this->assertTrue((new manager())->component_is_compliant('local_arcs'));
    }

    /**
     * The change log table and the Arcs external location are described, each field with a defined string.
     */
    public function test_get_metadata(): void {
        $items = provider::get_metadata(new collection('local_arcs'))->get_collection();

        $this->assertCount(2, $items);
        $this->assertInstanceOf(database_table::class, $items[0]);
        $this->assertSame('local_arcs_change', $items[0]->get_name());
        $fields = ['entity', 'entityid', 'op', 'userid', 'courseid', 'timecreated'];
        $this->assertSame($fields, array_keys($items[0]->get_privacy_fields()));
        $this->assertInstanceOf(external_location::class, $items[1]);
        $this->assertSame('arcs', $items[1]->get_name());
        foreach ($items as $item) {
            foreach ($item->get_privacy_fields() + ['summary' => $item->get_summary()] as $key) {
                $this->assertTrue(get_string_manager()->string_exists($key, 'local_arcs'), $key);
            }
        }
    }

    /**
     * The external location names every user field local_arcs_get_users can send, their progress, grades, cohorts
     * and log events.
     */
    public function test_get_metadata_lists_exported_user_data(): void {
        $items = provider::get_metadata(new collection('local_arcs'))->get_collection();
        $userfields = array_keys(get_users::execute_returns()->keys['users']->content->keys);
        $progress = ['userenrolments', 'coursecompletions', 'activitycompletions', 'grades', 'cohortmembers', 'logevents'];

        $this->assertEqualsCanonicalizing(
            array_merge(array_values(array_diff($userfields, ['id'])), $progress),
            array_keys($items[1]->get_privacy_fields())
        );
    }

    /**
     * A named user's data is in their user context; a user the log never names has none.
     */
    public function test_get_contexts_for_userid(): void {
        $contexts = provider::get_contexts_for_userid($this->user->id)->get_contextids();
        $this->assertEquals([\core\context\user::instance($this->user->id)->id], $contexts);

        // The admin was created at install, before anything recorded changes.
        $this->assertSame([], provider::get_contexts_for_userid(get_admin()->id)->get_contextids());
    }

    /**
     * The user context lists its user; other contexts list nobody.
     */
    public function test_get_users_in_context(): void {
        $userlist = new userlist(\core\context\user::instance($this->user->id), 'local_arcs');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$this->user->id], $userlist->get_userids());

        $userlist = new userlist(\core\context\system::instance(), 'local_arcs');
        provider::get_users_in_context($userlist);
        $this->assertSame([], $userlist->get_userids());
    }

    /**
     * Export writes the user's rows (ids and times) to their user context.
     */
    public function test_export_user_data(): void {
        $context = \core\context\user::instance($this->user->id);

        $this->export_context_data_for_user($this->user->id, $context, 'local_arcs');

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
        $changes = $writer->get_data([get_string('privacy:changes', 'local_arcs')])->changes;
        $this->assertCount(2, $changes);
        $this->assertSame('grade', $changes[1]->entity);
        $this->assertEquals(77, $changes[1]->entityid);
        $this->assertSame('delete', $changes[1]->op);
        $this->assertEquals(5, $changes[1]->courseid);
    }

    /**
     * Deleting for a user removes only that user's rows.
     */
    public function test_delete_data_for_user(): void {
        $context = \core\context\user::instance($this->user->id);

        provider::delete_data_for_user(new approved_contextlist($this->user, 'local_arcs', [$context->id]));

        $this->assertSame(0, $this->count_rows($this->user));
        $this->assertSame(1, $this->count_rows($this->other));
    }

    /**
     * Deleting for all users in a user context removes that user's rows; other contexts do nothing.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context(\core\context\system::instance());
        $this->assertSame(4, $DB->count_records(recorder::TABLE));

        provider::delete_data_for_all_users_in_context(\core\context\user::instance($this->user->id));
        $this->assertSame(0, $this->count_rows($this->user));
        $this->assertSame(1, $this->count_rows($this->other));
    }

    /**
     * Deleting for a user list removes the rows of an approved user in their own context.
     */
    public function test_delete_data_for_users(): void {
        $context = \core\context\user::instance($this->user->id);

        provider::delete_data_for_users(new approved_userlist($context, 'local_arcs', [$this->other->id]));
        $this->assertSame(2, $this->count_rows($this->user));

        provider::delete_data_for_users(new approved_userlist($context, 'local_arcs', [$this->user->id]));
        $this->assertSame(0, $this->count_rows($this->user));
        $this->assertSame(1, $this->count_rows($this->other));
    }
}
