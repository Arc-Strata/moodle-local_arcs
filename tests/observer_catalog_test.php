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
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/externallib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
/**
 * Tests that core actions on categories, courses and course modules record change log rows.
 *
 * Each test performs the real core action; none calls the observer directly.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_arcs\observer
 */
final class observer_catalog_test extends \advanced_testcase {
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
     * Creating, moving, hiding and showing a category each record a category upsert.
     */
    public function test_category_created_and_updated(): void {
        $this->clear_changes();
        $parent = \core_course_category::create(['name' => 'Parent']);
        $child = \core_course_category::create(['name' => 'Child']);
        $this->assertSame([
            $this->change('course_category', (int) $parent->id, 'upsert'),
            $this->change('course_category', (int) $child->id, 'upsert'),
        ], $this->changes());

        $this->clear_changes();
        $child->change_parent($parent);
        $parent->hide();
        $parent->show();

        $upsert = $this->change('course_category', (int) $parent->id, 'upsert');
        $this->assertSame([$this->change('course_category', (int) $child->id, 'upsert'), $upsert, $upsert], $this->changes());
    }

    /**
     * Deleting a category with its contents records a delete for it, its subcategory and its course.
     */
    public function test_category_deleted(): void {
        $this->setAdminUser();
        $parent = $this->getDataGenerator()->create_category();
        $child = $this->getDataGenerator()->create_category(['parent' => $parent->id]);
        $course = $this->getDataGenerator()->create_course(['category' => $child->id]);
        $this->clear_changes();

        \core_course_category::get($parent->id)->delete_full(false);

        $changes = $this->changes();
        $this->assertContains($this->change('course_category', (int) $parent->id, 'delete'), $changes);
        $this->assertContains($this->change('course_category', (int) $child->id, 'delete'), $changes);
        $this->assertContains($this->change('course', (int) $course->id, 'delete', null, (int) $course->id), $changes);
    }

    /**
     * Creating and updating a course each record a course upsert, and nothing about what is in it.
     */
    public function test_course_created_and_updated(): void {
        $this->clear_changes();
        $course = create_course((object) ['fullname' => 'Fire safety', 'shortname' => 'FS101', 'category' => 1]);
        $upsert = $this->change('course', (int) $course->id, 'upsert', null, (int) $course->id);
        $this->assertSame([$upsert], $this->changes());

        $this->clear_changes();
        update_course((object) ['id' => $course->id, 'fullname' => 'Fire safety 2']);
        $this->assertSame([$upsert], $this->changes());
    }

    /**
     * Deleting a course records a course delete.
     */
    public function test_course_deleted(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->clear_changes();

        delete_course($course, false);

        $this->assertContains($this->change('course', (int) $course->id, 'delete', null, (int) $course->id), $this->changes());
    }

    /**
     * Where courses are deleted asynchronously (Moodle 5.3+), the delete is recorded when the deletion task runs.
     */
    public function test_course_deleted_asynchronously(): void {
        if (!class_exists(\core_course\task\course_async_deletion::class)) {
            $this->markTestSkipped('Asynchronous course deletion arrived in Moodle 5.3.');
        }
        set_config('enablecourseasyncdeletion', 1, 'moodlecourse');
        $course = $this->getDataGenerator()->create_course();
        $this->clear_changes();
        $delete = $this->change('course', (int) $course->id, 'delete', null, (int) $course->id);

        delete_course($course, false);
        $this->assertNotContains($delete, $this->changes());

        // The deletion task reports its progress as it runs.
        $this->expectOutputRegex('/Deleted/');
        $this->runAdhocTasks(\core_course\task\course_async_deletion::class);
        $this->assertContains($delete, $this->changes());
    }

    /**
     * Resetting a course records a course upsert (reset can move its dates without an event) and a course contents upsert.
     */
    public function test_course_reset(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->clear_changes();

        reset_course_userdata((object) [
            'id' => $course->id,
            'reset_start_date_old' => $course->startdate,
            'reset_start_date' => $course->startdate + WEEKSECS,
            'reset_end_date_old' => 0,
        ]);

        $this->assertSame(
            [
                $this->change('course', (int) $course->id, 'upsert', null, (int) $course->id),
                $this->change('course_contents', (int) $course->id, 'upsert', null, (int) $course->id),
            ],
            $this->changes()
        );
    }

    /**
     * Deleting a course's contents records a course contents upsert, and no course upsert.
     */
    public function test_course_content_deleted(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->clear_changes();

        remove_course_contents($course->id, false);

        $contents = $this->change('course_contents', (int) $course->id, 'upsert', null, (int) $course->id);
        $this->assertContains($contents, $this->changes());
        $this->assertNotContains($this->change('course', (int) $course->id, 'upsert', null, (int) $course->id), $this->changes());
    }

    /**
     * Restoring into a course records a course upsert and a course contents upsert for it.
     */
    public function test_course_restored(): void {
        global $USER;
        $this->setAdminUser();
        $source = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $source->id]);
        $target = $this->getDataGenerator()->create_course();
        $this->clear_changes();

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $source->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();
        $rc = new \restore_controller(
            $backupid,
            $target->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_EXISTING_ADDING
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $changes = $this->changes();
        $this->assertContains($this->change('course', (int) $target->id, 'upsert', null, (int) $target->id), $changes);
        $this->assertContains($this->change('course_contents', (int) $target->id, 'upsert', null, (int) $target->id), $changes);
    }

    /**
     * Restoring an activity from the recycle bin records a course contents upsert, since the restore
     * writes the module, its completions and its grades without events of their own.
     */
    public function test_recycle_bin_item_restored(): void {
        $this->setAdminUser();
        set_config('coursebinenable', 1, 'tool_recyclebin');
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $bin = new \tool_recyclebin\course_bin($course->id);
        $bin->store_item(get_coursemodule_from_id('page', $page->cmid));
        $items = $bin->get_items();
        $this->clear_changes();

        $bin->restore_item(reset($items));

        $this->assertContains(
            $this->change('course_contents', (int) $course->id, 'upsert', null, (int) $course->id),
            $this->changes()
        );
    }

    /**
     * Saving completion criteria records a course contents upsert. Core fires this event only from the
     * course completion settings page, so the test fires it as that page does.
     */
    public function test_course_completion_updated(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->clear_changes();

        \core\event\course_completion_updated::create(['courseid' => $course->id,
            'context' => \core\context\course::instance($course->id)])->trigger();

        $this->assertSame(
            [$this->change('course_contents', (int) $course->id, 'upsert', null, (int) $course->id)],
            $this->changes()
        );
    }

    /**
     * Adding, renaming, hiding and deleting a course module record module changes.
     */
    public function test_course_module_lifecycle(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $this->clear_changes();

        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $upsert = $this->change('course_module', (int) $page->cmid, 'upsert', null, (int) $course->id);
        $this->assertSame([$upsert], $this->changes());

        $this->clear_changes();
        \core_courseformat\formatactions::cm($course->id)->rename($page->cmid, 'Renamed');
        $this->assertSame([$upsert], $this->changes());

        $this->clear_changes();
        \core_course_external::delete_modules([$page->cmid]);
        $this->assertSame([$this->change('course_module', (int) $page->cmid, 'delete', null, (int) $course->id)], $this->changes());
    }

    /**
     * Changes to the site course's modules (front page activities) are not recorded, since they are not exported.
     */
    public function test_site_course_modules_ignored(): void {
        $this->setAdminUser();
        $this->clear_changes();

        $page = $this->getDataGenerator()->create_module('page', ['course' => SITEID]);
        \core_courseformat\formatactions::cm(SITEID)->rename($page->cmid, 'Renamed');
        \core_course_external::delete_modules([$page->cmid]);

        $this->assertSame([], $this->changes());
    }
}
