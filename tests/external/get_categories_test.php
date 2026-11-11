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

#[\PHPUnit\Framework\Attributes\CoversClass(get_categories::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\categories::class)]
/**
 * Tests for local_arcs_get_categories.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_categories
 * @covers     \local_arcs\local\source\categories
 */
final class get_categories_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @param int[] $ids Ids to fetch.
     * @param int[] $categoryids Categories whose subtrees to fetch.
     * @return array
     */
    private function call(int $after = 0, int $limit = source::DEFAULT_LIMIT, array $ids = [], array $categoryids = []): array {
        return external_api::clean_returnvalue(
            get_categories::execute_returns(),
            get_categories::execute($after, $limit, $ids, $categoryids)
        );
    }

    /**
     * The ids of the categories in a result.
     *
     * @param array $result The cleaned result.
     * @return int[]
     */
    private function ids(array $result): array {
        return array_column($result['categories'], 'id');
    }

    /**
     * A new site has only the default category.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $result = $this->call();

        $this->assertSame([(int) \core_course_category::get_default()->id], $this->ids($result));
        $this->assertNull($result['next']);
    }

    /**
     * Every field is returned, raw.
     */
    public function test_execute_returns_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $parent = $this->getDataGenerator()->create_category(['name' => 'Parent']);
        $child = $this->getDataGenerator()->create_category(['name' => 'Safety', 'idnumber' => 'HS', 'parent' => $parent->id]);
        // Set directly: the generator cleans names, and the export must not.
        $DB->set_field('course_categories', 'name', 'Health & <b>safety</b>', ['id' => $child->id]);
        $record = $DB->get_record('course_categories', ['id' => $child->id]);

        $this->assertSame([[
            'id' => (int) $child->id,
            'name' => 'Health & <b>safety</b>',
            'idnumber' => 'HS',
            'parent' => (int) $parent->id,
            'path' => '/' . $parent->id . '/' . $child->id,
            'visible' => true,
            'timemodified' => (int) $record->timemodified,
        ]], $this->call(0, 10, [(int) $child->id])['categories']);
    }

    /**
     * A reader can page through categories with next.
     */
    public function test_execute_pages_with_cursor(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $this->getDataGenerator()->create_category();
        $this->getDataGenerator()->create_category();

        $first = $this->call(0, 2);
        $second = $this->call($first['next'], 2);

        $this->assertCount(2, $first['categories']);
        $this->assertCount(1, $second['categories']);
        $this->assertNull($second['next']);
    }

    /**
     * With ids, exactly the existing categories are returned.
     */
    public function test_execute_by_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $one = $this->getDataGenerator()->create_category();
        $this->getDataGenerator()->create_category();

        $this->assertSame([(int) $one->id], $this->ids($this->call(0, 10, [(int) $one->id, 999999])));
    }

    /**
     * With categoryids, the categories and everything below them are returned, and nothing else.
     */
    public function test_execute_by_category_subtree(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $generator = $this->getDataGenerator();
        $top = $generator->create_category();
        $child = $generator->create_category(['parent' => $top->id]);
        $grandchild = $generator->create_category(['parent' => $child->id]);
        $other = $generator->create_category();
        $othertop = $generator->create_category(['parent' => $other->id]);

        $result = $this->call(0, 10, [], [(int) $top->id, (int) $othertop->id]);

        $this->assertSame([(int) $top->id, (int) $child->id, (int) $grandchild->id, (int) $othertop->id], $this->ids($result));
    }

    /**
     * A category whose id starts with another's (12 and 120) is not mistaken for one below it.
     */
    public function test_execute_by_category_subtree_matches_whole_ids(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $one = $this->getDataGenerator()->create_category();
        $two = $this->getDataGenerator()->create_category();
        // Give the second a path that starts with the first's, as category 12 and 120 would have.
        $DB->set_field('course_categories', 'path', '/' . $one->id . '0', ['id' => $two->id]);

        $this->assertSame([(int) $one->id], $this->ids($this->call(0, 10, [], [(int) $one->id])));
    }

    /**
     * With categoryids, paging still works.
     */
    public function test_execute_by_category_subtree_pages(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $top = $this->getDataGenerator()->create_category();
        $child = $this->getDataGenerator()->create_category(['parent' => $top->id]);

        $first = $this->call(0, 1, [], [(int) $top->id]);
        $second = $this->call($first['next'], 1, [], [(int) $top->id]);

        $this->assertSame([(int) $top->id], $this->ids($first));
        $this->assertSame([(int) $child->id], $this->ids($second));
        $this->assertNull($second['next']);
    }

    /**
     * ids and categoryids together are refused.
     */
    public function test_execute_ids_with_filter(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(invalid_parameter_exception::class);
        get_categories::execute(0, 10, [1], [1]);
    }

    /**
     * More categoryids than the maximum are refused with limit_exceeded.
     */
    public function test_execute_too_many_category_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::MAX_LIMIT));
        get_categories::execute(0, 10, [], range(1, source::MAX_LIMIT + 1));
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_categories::execute();
    }
}
