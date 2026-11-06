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

use core_privacy\manager;

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
    /**
     * The plugin is compliant with the privacy API.
     */
    public function test_component_is_compliant(): void {
        $this->assertTrue((new manager())->component_is_compliant('local_arcs'));
    }

    /**
     * The null provider's reason is a defined language string.
     */
    public function test_get_reason(): void {
        $reason = provider::get_reason();

        $this->assertTrue(get_string_manager()->string_exists($reason, 'local_arcs'));
    }
}
