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

namespace local_arcs\local\source;

use local_arcs\local\settings\settings;

#[\PHPUnit\Framework\Attributes\CoversClass(source::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(users::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(settings::class)]
/**
 * Tests for the source base, through the users source.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\local\source\source
 * @covers     \local_arcs\local\source\users
 * @covers     \local_arcs\local\settings\settings
 */
final class users_test extends \advanced_testcase {
    /**
     * Creates a source with the current settings.
     *
     * @return users
     */
    private function source(): users {
        global $DB;
        return new users($DB, new settings());
    }

    /**
     * The ids of every user on the site, ascending.
     *
     * @return int[]
     */
    private function all_ids(): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_sql('SELECT id FROM {user} ORDER BY id'));
    }

    /**
     * Pages follow the cursor in id order, and next is null only after the last row.
     */
    public function test_page_follows_cursor(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user();
        $ids = $this->all_ids();
        $this->assertCount(5, $ids);

        $first = $this->source()->page(0, 2);
        $second = $this->source()->page($first['next'], 2);
        $third = $this->source()->page($second['next'], 2);

        $this->assertSame([$ids[0], $ids[1]], array_column($first['rows'], 'id'));
        $this->assertSame($ids[1], $first['next']);
        $this->assertSame([$ids[2], $ids[3]], array_column($second['rows'], 'id'));
        $this->assertSame($ids[3], $second['next']);
        $this->assertSame([$ids[4]], array_column($third['rows'], 'id'));
        $this->assertNull($third['next']);
    }

    /**
     * A page that ends exactly at the last row says there is no more.
     */
    public function test_page_ending_at_last_row(): void {
        $this->resetAfterTest();
        $ids = $this->all_ids();

        $page = $this->source()->page(0, count($ids));

        $this->assertSame($ids, array_column($page['rows'], 'id'));
        $this->assertNull($page['next']);
    }

    /**
     * A cursor past the last row gives an empty page.
     */
    public function test_page_after_last_row(): void {
        $this->resetAfterTest();
        $ids = $this->all_ids();

        $this->assertSame(['rows' => [], 'next' => null], $this->source()->page(end($ids), 10));
    }

    /**
     * By id, only the rows that exist are returned, in id order, whatever order the ids came in.
     */
    public function test_by_ids_returns_existing_rows(): void {
        $this->resetAfterTest();
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();

        $page = $this->source()->by_ids([(int) $two->id, 999999, (int) $one->id]);

        $this->assertSame([(int) $one->id, (int) $two->id], array_column($page['rows'], 'id'));
        $this->assertNull($page['next']);
    }

    /**
     * By default, the default optional fields are read and custom profile fields are not.
     */
    public function test_default_fields(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'team', 'name' => 'Team']);
        $user = $this->getDataGenerator()->create_user(['phone1' => '0123', 'profile_field_team' => 'Blue']);

        $row = $this->source()->by_ids([(int) $user->id])['rows'][0];

        foreach (settings::DEFAULT_OPTIONAL_FIELDS as $field) {
            $this->assertArrayHasKey($field, $row);
        }
        $this->assertArrayNotHasKey('phone1', $row);
        $this->assertSame([], $row['profilefields']);
    }

    /**
     * The selected optional and custom fields are read; the others are not.
     */
    public function test_selected_fields(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $team = $generator->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'team', 'name' => 'Team']);
        $generator->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'secret', 'name' => 'Secret']);
        $user = $generator->create_user([
            'phone1' => '0123',
            'department' => 'Sales',
            'profile_field_team' => 'Blue',
            'profile_field_secret' => 'Hidden',
        ]);
        set_config('optionalfields', 'phone1,profile_field_team', 'local_arcs');

        $row = $this->source()->by_ids([(int) $user->id])['rows'][0];

        $this->assertSame('0123', $row['phone1']);
        $this->assertArrayNotHasKey('department', $row);
        $this->assertSame([['fieldid' => (int) $team->id, 'shortname' => 'team', 'value' => 'Blue']], $row['profilefields']);
    }

    /**
     * Custom profile field values for a whole page are read at once: the reads do not grow with the page.
     */
    public function test_profile_values_read_once_per_page(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $generator->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'team', 'name' => 'Team']);
        for ($i = 0; $i < 10; $i++) {
            $generator->create_user(['profile_field_team' => "Team {$i}"]);
        }
        set_config('optionalfields', 'profile_field_team', 'local_arcs');
        $source = $this->source();

        $before = $DB->perf_get_reads();
        $small = $source->page(2, 2);
        $smallreads = $DB->perf_get_reads() - $before;
        $before = $DB->perf_get_reads();
        $large = $source->page(2, 20);
        $largereads = $DB->perf_get_reads() - $before;

        $this->assertCount(2, $small['rows']);
        $this->assertCount(10, $large['rows']);
        $this->assertSame('Team 9', $large['rows'][9]['profilefields'][0]['value']);
        $this->assertSame($smallreads, $largereads);
    }
}
