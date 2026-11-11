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
require_once($CFG->dirroot . '/cohort/lib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
/**
 * Tests that core actions on cohorts and their members record change log rows.
 *
 * Each test performs the real core action; none calls the observer directly.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\observer
 */
final class observer_cohorts_test extends \advanced_testcase {
    use changes_trait;

    /**
     * Resets the database the slow way, so the external observers run (see changes_trait).
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
    }

    /**
     * Creating, updating and deleting a cohort record cohort changes.
     */
    public function test_cohort_lifecycle(): void {
        $this->clear_changes();
        $id = (int) cohort_add_cohort((object) [
            'contextid' => \core\context\system::instance()->id,
            'name' => 'Cohort',
            'idnumber' => '',
        ]);
        $this->assertSame([$this->change('cohort', $id, 'upsert')], $this->changes());

        $cohort = $this->get_cohort($id);
        $this->clear_changes();
        $cohort->name = 'Renamed';
        cohort_update_cohort($cohort);
        $this->assertSame([$this->change('cohort', $id, 'upsert')], $this->changes());

        // Read it again: cohort_update_cohort() unsets theme unless cohort themes are allowed.
        $cohort = $this->get_cohort($id);
        $this->clear_changes();
        cohort_delete_cohort($cohort);
        $this->assertSame([$this->change('cohort', $id, 'delete')], $this->changes());
    }

    /**
     * Adding and removing a member record the change by cohort and user.
     */
    public function test_member_added_and_removed(): void {
        $cohortid = (int) $this->getDataGenerator()->create_cohort()->id;
        $userid = (int) $this->getDataGenerator()->create_user()->id;

        $this->clear_changes();
        cohort_add_member($cohortid, $userid);
        $this->assertSame([$this->change('cohort_member', $cohortid, 'upsert', $userid)], $this->changes());

        $this->clear_changes();
        cohort_remove_member($cohortid, $userid);
        $this->assertSame([$this->change('cohort_member', $cohortid, 'delete', $userid)], $this->changes());
    }

    /**
     * A cohort as cohort_update_cohort() expects it.
     *
     * @param int $id The cohort id.
     * @return \stdClass
     */
    private function get_cohort(int $id): \stdClass {
        global $DB;
        return $DB->get_record('cohort', ['id' => $id], '*', MUST_EXIST);
    }
}
