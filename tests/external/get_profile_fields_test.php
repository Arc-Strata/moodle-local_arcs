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

use core\exception\moodle_exception;
use core_external\external_api;
use local_arcs\local\source\source;
use local_arcs\tests\exporter_trait;

#[\PHPUnit\Framework\Attributes\CoversClass(get_profile_fields::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\profile_fields::class)]
/**
 * Tests for local_arcs_get_profile_fields.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_profile_fields
 * @covers     \local_arcs\local\source\profile_fields
 */
final class get_profile_fields_test extends \advanced_testcase {
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
        return external_api::clean_returnvalue(
            get_profile_fields::execute_returns(),
            get_profile_fields::execute($after, $limit, $ids)
        );
    }

    /**
     * Creates the given number of text profile fields.
     *
     * @param int $count How many.
     * @return \stdClass[] The fields, in id order.
     */
    private function create_fields(int $count): array {
        $fields = [];
        for ($i = 1; $i <= $count; $i++) {
            $fields[] = $this->getDataGenerator()->create_custom_profile_field([
                'datatype' => 'text',
                'shortname' => "field{$i}",
                'name' => "Field {$i}",
            ]);
        }
        return $fields;
    }

    /**
     * A site with no custom profile fields returns none.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->assertSame(['profilefields' => [], 'next' => null], $this->call());
    }

    /**
     * Every field of a definition is returned, raw, including fields whose values are not exported.
     */
    public function test_execute_returns_fields(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $field = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'menu',
            'shortname' => 'grade',
            'name' => 'Pay <grade>',
            'param1' => "A\nB",
            'visible' => PROFILE_VISIBLE_PRIVATE,
        ]);

        $this->assertSame(['profilefields' => [[
            'id' => (int) $field->id,
            'shortname' => 'grade',
            'name' => 'Pay <grade>',
            'datatype' => 'menu',
            'categoryid' => (int) $field->categoryid,
            'visible' => 1,
        ]], 'next' => null], $this->call());
    }

    /**
     * A reader can page through fields with next.
     */
    public function test_execute_pages_with_cursor(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $fields = $this->create_fields(3);

        $first = $this->call(0, 2);
        $second = $this->call($first['next'], 2);

        $this->assertSame([(int) $fields[0]->id, (int) $fields[1]->id], array_column($first['profilefields'], 'id'));
        $this->assertSame([(int) $fields[2]->id], array_column($second['profilefields'], 'id'));
        $this->assertNull($second['next']);
    }

    /**
     * With ids, exactly the existing fields are returned.
     */
    public function test_execute_by_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $fields = $this->create_fields(3);

        $result = $this->call(0, 10, [(int) $fields[2]->id, 999999, (int) $fields[0]->id]);

        $this->assertSame([(int) $fields[0]->id, (int) $fields[2]->id], array_column($result['profilefields'], 'id'));
    }

    /**
     * A limit above the maximum is refused with limit_exceeded.
     */
    public function test_execute_limit_exceeded(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::MAX_LIMIT));
        get_profile_fields::execute(0, source::MAX_LIMIT + 1);
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_profile_fields::execute();
    }
}
