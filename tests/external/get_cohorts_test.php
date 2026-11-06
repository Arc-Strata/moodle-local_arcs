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
use local_arcs\local\source\source;
use local_arcs\tests\exporter_trait;

#[\PHPUnit\Framework\Attributes\CoversClass(get_cohorts::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\cohorts::class)]
/**
 * Tests for local_arcs_get_cohorts.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_cohorts
 * @covers     \local_arcs\local\source\cohorts
 */
final class get_cohorts_test extends \advanced_testcase {
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
        return external_api::clean_returnvalue(get_cohorts::execute_returns(), get_cohorts::execute($after, $limit, $ids));
    }

    /**
     * The ids of the cohorts in a result.
     *
     * @param array $result The cleaned result.
     * @return int[]
     */
    private function ids(array $result): array {
        return array_column($result['cohorts'], 'id');
    }

    /**
     * A site with no cohorts returns none.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->assertSame(['cohorts' => [], 'next' => null], $this->call());
    }

    /**
     * Every field is returned; a category cohort names its category, a site-wide one none.
     */
    public function test_execute_returns_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $category = $this->getDataGenerator()->create_category();
        $site = $this->getDataGenerator()->create_cohort(['name' => 'Site <b>wide</b>', 'idnumber' => 'S1',
            'visible' => 0, 'component' => 'local_arcs']);
        $incategory = $this->getDataGenerator()->create_cohort([
            'contextid' => \core\context\coursecat::instance($category->id)->id,
        ]);
        $record = $DB->get_record('cohort', ['id' => $site->id]);

        $rows = $this->call()['cohorts'];

        $this->assertSame([
            'id' => (int) $site->id,
            'contextid' => \core\context\system::instance()->id,
            'categoryid' => null,
            'name' => 'Site <b>wide</b>',
            'idnumber' => 'S1',
            'visible' => false,
            'component' => 'local_arcs',
            'timecreated' => (int) $record->timecreated,
            'timemodified' => (int) $record->timemodified,
        ], $rows[0]);
        $this->assertSame((int) $incategory->id, $rows[1]['id']);
        $this->assertSame((int) $category->id, $rows[1]['categoryid']);
        $this->assertTrue($rows[1]['visible']);
    }

    /**
     * A reader can page with next; ids return exactly the existing cohorts.
     */
    public function test_execute_pages_and_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $one = (int) $this->getDataGenerator()->create_cohort()->id;
        $two = (int) $this->getDataGenerator()->create_cohort()->id;

        $page1 = $this->call(0, 1);
        $page2 = $this->call($page1['next'], 1);

        $this->assertSame([$one], $this->ids($page1));
        $this->assertSame([$two], $this->ids($page2));
        $this->assertNull($page2['next']);
        $this->assertSame([$two], $this->ids($this->call(0, 10, [$two, 999999])));
    }

    /**
     * Deleting a category moves its cohorts to the parent category, which their categoryid shows.
     */
    public function test_execute_after_category_deleted(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $parent = $this->getDataGenerator()->create_category();
        $child = $this->getDataGenerator()->create_category(['parent' => $parent->id]);
        $cohort = $this->getDataGenerator()->create_cohort([
            'contextid' => \core\context\coursecat::instance($child->id)->id,
        ]);
        $this->setAdminUser();
        \core_course_category::get($child->id)->delete_full(false);
        $this->set_exporter();

        $row = $this->call(0, 10, [(int) $cohort->id])['cohorts'][0];

        $this->assertSame((int) $parent->id, $row['categoryid']);
    }

    /**
     * More ids than the limit are refused with limit_exceeded.
     */
    public function test_execute_too_many_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(\core\exception\moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', 2));
        get_cohorts::execute(0, 2, [1, 2, 3]);
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_cohorts::execute();
    }
}
