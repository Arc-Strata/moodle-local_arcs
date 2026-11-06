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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/cohort/lib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(get_cohort_members::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\cohort_members::class)]
/**
 * Tests for local_arcs_get_cohort_members.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_cohort_members
 * @covers     \local_arcs\local\source\cohort_members
 */
final class get_cohort_members_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @param int[] $ids Ids to fetch.
     * @param int[] $cohortids Cohorts whose memberships to fetch.
     * @param int[] $userids Users whose memberships to fetch.
     * @return array
     */
    private function call(
        int $after = 0,
        int $limit = source::DEFAULT_LIMIT,
        array $ids = [],
        array $cohortids = [],
        array $userids = []
    ): array {
        return external_api::clean_returnvalue(
            get_cohort_members::execute_returns(),
            get_cohort_members::execute($after, $limit, $ids, $cohortids, $userids)
        );
    }

    /**
     * Adds a user to a cohort, as core does.
     *
     * @param int $cohortid The cohort.
     * @param int $userid The user.
     * @return int The membership id.
     */
    private function add(int $cohortid, int $userid): int {
        global $DB;
        cohort_add_member($cohortid, $userid);
        return (int) $DB->get_field('cohort_members', 'id', ['cohortid' => $cohortid, 'userid' => $userid]);
    }

    /**
     * The ids of the memberships in a result.
     *
     * @param array $result The cleaned result.
     * @return int[]
     */
    private function ids(array $result): array {
        return array_column($result['cohortmembers'], 'id');
    }

    /**
     * A site with no memberships returns none.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->assertSame(['cohortmembers' => [], 'next' => null], $this->call());
    }

    /**
     * Every field is returned.
     */
    public function test_execute_returns_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        $cohort = $this->getDataGenerator()->create_cohort();
        $user = $this->getDataGenerator()->create_user();
        $id = $this->add((int) $cohort->id, (int) $user->id);

        $this->assertSame([[
            'id' => $id,
            'cohortid' => (int) $cohort->id,
            'userid' => (int) $user->id,
            'timeadded' => (int) $DB->get_field('cohort_members', 'timeadded', ['id' => $id]),
        ]], $this->call()['cohortmembers']);
    }

    /**
     * A reader can page with next; ids return exactly the existing memberships.
     */
    public function test_execute_pages_and_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $cohortid = (int) $this->getDataGenerator()->create_cohort()->id;
        $one = $this->add($cohortid, (int) $this->getDataGenerator()->create_user()->id);
        $two = $this->add($cohortid, (int) $this->getDataGenerator()->create_user()->id);

        $page1 = $this->call(0, 1);
        $page2 = $this->call($page1['next'], 1);

        $this->assertSame([$one], $this->ids($page1));
        $this->assertSame([$two], $this->ids($page2));
        $this->assertNull($page2['next']);
        $this->assertSame([$two], $this->ids($this->call(0, 10, [$two, 999999])));
    }

    /**
     * cohortids and userids each filter, and together select the given users' memberships of the given cohorts.
     */
    public function test_execute_by_cohort_and_user(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $a = (int) $this->getDataGenerator()->create_cohort()->id;
        $b = (int) $this->getDataGenerator()->create_cohort()->id;
        $empty = (int) $this->getDataGenerator()->create_cohort()->id;
        $u1 = (int) $this->getDataGenerator()->create_user()->id;
        $u2 = (int) $this->getDataGenerator()->create_user()->id;
        $a1 = $this->add($a, $u1);
        $a2 = $this->add($a, $u2);
        $b1 = $this->add($b, $u1);

        $page1 = $this->call(0, 1, [], [$a]);
        $page2 = $this->call($page1['next'], 1, [], [$a]);

        $this->assertSame([$a1, $a2], array_merge($this->ids($page1), $this->ids($page2)));
        $this->assertSame([$a1, $b1], $this->ids($this->call(0, 10, [], [], [$u1])));
        $this->assertSame([$a2], $this->ids($this->call(0, 10, [], [$a], [$u2])));
        $this->assertSame([$a1, $b1], $this->ids($this->call(0, 10, [], [$a, $b], [$u1])));
        $this->assertSame([], $this->ids($this->call(0, 10, [], [$empty])));
        $this->assertSame([], $this->ids($this->call(0, 10, [], [$b], [$u2])));
    }

    /**
     * Either filter with ids is refused.
     */
    public function test_execute_filters_with_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        try {
            get_cohort_members::execute(0, 10, [1], [2]);
            $this->fail('ids with cohortids should be refused');
        } catch (invalid_parameter_exception $e) {
            $this->assertInstanceOf(invalid_parameter_exception::class, $e);
        }
        $this->expectException(invalid_parameter_exception::class);
        get_cohort_members::execute(0, 10, [1], [], [2]);
    }

    /**
     * More userids than the maximum are refused with limit_exceeded.
     */
    public function test_execute_too_many_user_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::MAX_LIMIT));
        get_cohort_members::execute(0, 10, [], [1], range(1, source::MAX_LIMIT + 1));
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_cohort_members::execute();
    }
}
