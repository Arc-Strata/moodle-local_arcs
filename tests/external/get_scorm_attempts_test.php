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
require_once($CFG->dirroot . '/mod/scorm/locallib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(get_scorm_attempts::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\scorm_attempts::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_arcs\local\source\attempts::class)]
/**
 * Tests for local_arcs_get_scorm_attempts.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\external\get_scorm_attempts
 * @covers     \local_arcs\local\source\scorm_attempts
 * @covers     \local_arcs\local\source\attempts
 */
final class get_scorm_attempts_test extends \advanced_testcase {
    use exporter_trait;

    /**
     * Calls the function as the web service layer would and cleans the result.
     *
     * @param int $after Cursor.
     * @param int $limit Page size.
     * @param int[] $ids Ids to fetch.
     * @param int[] $courseids Courses whose attempts to fetch.
     * @param int[] $cmids SCORM packages, by course module, whose attempts to fetch.
     * @return array
     */
    private function call(
        int $after = 0,
        int $limit = source::DEFAULT_LIMIT,
        array $ids = [],
        array $courseids = [],
        array $cmids = []
    ): array {
        return external_api::clean_returnvalue(
            get_scorm_attempts::execute_returns(),
            get_scorm_attempts::execute($after, $limit, $ids, $courseids, $cmids)
        );
    }

    /**
     * Creates a SCORM package in a course.
     *
     * @param int $courseid The course.
     * @param string $package The package file in mod/scorm/tests/packages.
     * @return \stdClass The SCORM instance, with cmid.
     */
    private function scorm(int $courseid, string $package = 'singlescobasic.zip'): \stdClass {
        return $this->getDataGenerator()->create_module(
            'scorm',
            ['course' => $courseid, 'packagefilepath' => 'mod/scorm/tests/packages/' . $package]
        );
    }

    /**
     * The package's SCOs, in id order.
     *
     * @param \stdClass $scorm The SCORM instance.
     * @return \stdClass[]
     */
    private function scos(\stdClass $scorm): array {
        global $DB;
        return array_values($DB->get_records('scorm_scoes', ['scorm' => $scorm->id, 'scormtype' => 'sco'], 'id'));
    }

    /**
     * Records a new user's first attempt, with a completed status in the package's first SCO.
     *
     * @param \stdClass $scorm The SCORM instance.
     * @return int The attempt id.
     */
    private function attempt(\stdClass $scorm): int {
        $user = $this->getDataGenerator()->create_user();
        scorm_insert_track($user->id, $scorm->id, $this->scos($scorm)[0]->id, 1, 'cmi.core.lesson_status', 'completed');
        return (int) scorm_get_attempt($user->id, $scorm->id, 1, false)->id;
    }

    /**
     * The ids of the attempts in a result.
     *
     * @param array $result The cleaned result.
     * @return int[]
     */
    private function ids(array $result): array {
        return array_column($result['scormattempts'], 'id');
    }

    /**
     * A site with no SCORM attempts returns none.
     */
    public function test_execute_on_empty_site(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->assertSame(['scormattempts' => [], 'next' => null], $this->call());
    }

    /**
     * Every field is returned, with a SCO for each SCO the package reported on; other tracking data is not read.
     */
    public function test_execute_returns_fields_and_scos(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $scorm = $this->scorm((int) $this->getDataGenerator()->create_course()->id, 'complexscorm.zip');
        [$first, $second] = $this->scos($scorm);
        $user = $this->getDataGenerator()->create_user();
        $tracks = [
            [$first, 'x.start.time', '1700000000'],
            [$first, 'cmi.core.lesson_status', 'passed'],
            [$first, 'cmi.core.score.raw', '85'],
            [$first, 'cmi.core.score.min', '0'],
            [$first, 'cmi.core.score.max', '100'],
            [$first, 'cmi.suspend_data', 'not exported'],
            [$second, 'cmi.core.lesson_status', 'incomplete'],
            [$second, 'cmi.core.score.raw', ''],
        ];
        foreach ($tracks as [$sco, $element, $value]) {
            scorm_insert_track($user->id, $scorm->id, $sco->id, 1, $element, $value);
        }
        $attempt = scorm_get_attempt($user->id, $scorm->id, 1, false);

        $row = $this->call()['scormattempts'][0];

        $this->assertSame((int) $attempt->id, $row['id']);
        $this->assertSame((int) $user->id, $row['userid']);
        $this->assertSame((int) $scorm->cmid, $row['coursemoduleid']);
        $this->assertSame(1, $row['attempt']);
        $this->assertNull($row['passed']);
        $this->assertCount(2, $row['scos']);
        $this->assertGreaterThan(0, $row['scos'][0]['timemodified']);
        unset($row['scos'][0]['timemodified']);
        $this->assertSame([
            'scoid' => (int) $first->id,
            'identifier' => $first->identifier,
            'title' => $first->title,
            'status' => 'passed',
            'successstatus' => null,
            'scoreraw' => 85.0,
            'scoremin' => 0.0,
            'scoremax' => 100.0,
            'starttime' => 1700000000,
        ], $row['scos'][0]);
        $this->assertSame('incomplete', $row['scos'][1]['status']);
        $this->assertNull($row['scos'][1]['scoreraw']);
    }

    /**
     * SCORM 2004 elements fill the same fields, and success status its own.
     */
    public function test_execute_reads_scorm_2004(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $scorm = $this->scorm((int) $this->getDataGenerator()->create_course()->id);
        $sco = $this->scos($scorm)[0];
        $user = $this->getDataGenerator()->create_user();
        $tracks = [
            'cmi.completion_status' => 'completed',
            'cmi.success_status' => 'failed',
            'cmi.score.raw' => '0.5',
            'cmi.score.min' => '0',
            'cmi.score.max' => '1',
        ];
        foreach ($tracks as $element => $value) {
            scorm_insert_track($user->id, $scorm->id, $sco->id, 1, $element, $value);
        }

        $sco = $this->call()['scormattempts'][0]['scos'][0];

        $this->assertSame('completed', $sco['status']);
        $this->assertSame('failed', $sco['successstatus']);
        $this->assertSame(0.5, $sco['scoreraw']);
        $this->assertSame(1.0, $sco['scoremax']);
    }

    /**
     * Attempts at SCORM packages on the site course are left out.
     */
    public function test_execute_leaves_out_site_course(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $kept = $this->attempt($this->scorm((int) $this->getDataGenerator()->create_course()->id));
        $this->attempt($this->scorm(SITEID));

        $this->assertSame([$kept], $this->ids($this->call()));
    }

    /**
     * A reader can page with next; ids return exactly the existing attempts.
     */
    public function test_execute_pages_and_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $scorm = $this->scorm((int) $this->getDataGenerator()->create_course()->id);
        $one = $this->attempt($scorm);
        $two = $this->attempt($scorm);

        $page1 = $this->call(0, 1);
        $page2 = $this->call($page1['next'], 1);

        $this->assertSame([$one], $this->ids($page1));
        $this->assertSame([$two], $this->ids($page2));
        $this->assertNull($page2['next']);
        $this->assertSame([$two], $this->ids($this->call(0, 10, [$two, 999999])));
    }

    /**
     * With courseids, only those courses' attempts are returned; with coursemoduleids, only those packages'.
     */
    public function test_execute_by_course_and_by_module(): void {
        $this->resetAfterTest();
        $this->set_exporter();
        $course = $this->getDataGenerator()->create_course();
        $first = $this->scorm((int) $course->id);
        $second = $this->scorm((int) $course->id);
        $one = $this->attempt($first);
        $two = $this->attempt($second);
        $this->attempt($this->scorm((int) $this->getDataGenerator()->create_course()->id));

        $this->assertSame([$one, $two], $this->ids($this->call(0, 10, [], [(int) $course->id])));
        $this->assertSame([$two], $this->ids($this->call(0, 10, [], [], [(int) $second->cmid])));
        $this->assertSame([], $this->ids($this->call(0, 10, [], [999999])));
    }

    /**
     * courseids and coursemoduleids together are refused, as is either with ids.
     */
    public function test_execute_filters_not_combined(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        try {
            get_scorm_attempts::execute(0, 10, [1], [], [2]);
            $this->fail('ids with coursemoduleids should be refused');
        } catch (invalid_parameter_exception $e) {
            $this->assertInstanceOf(invalid_parameter_exception::class, $e);
        }
        $this->expectException(invalid_parameter_exception::class);
        get_scorm_attempts::execute(0, 10, [], [1], [2]);
    }

    /**
     * More courseids than the maximum are refused with limit_exceeded.
     */
    public function test_execute_too_many_course_ids(): void {
        $this->resetAfterTest();
        $this->set_exporter();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('limit_exceeded', 'local_arcs', source::MAX_LIMIT));
        get_scorm_attempts::execute(0, 10, [], range(1, source::MAX_LIMIT + 1));
    }

    /**
     * A site where the SCORM module has been uninstalled is refused with module_not_installed.
     */
    public function test_execute_module_not_installed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_exporter();
        // Uninstalling a module deletes its modules row (and its tables, which tests cannot drop).
        $DB->delete_records('modules', ['name' => 'scorm']);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('module_not_installed', 'local_arcs', 'scorm'));
        get_scorm_attempts::execute();
    }

    /**
     * A user without local/arcs:export is refused.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\core\exception\required_capability_exception::class);
        get_scorm_attempts::execute();
    }
}
