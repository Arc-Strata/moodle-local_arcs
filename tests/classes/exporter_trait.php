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

namespace local_arcs\tests;

use local_arcs\local\changelog\recorder;

/**
 * Test helper: acts as the user Arcs connects as.
 *
 * @package    local_arcs
 * @category   test
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait exporter_trait {
    /**
     * Creates a user holding local/arcs:export at system level and makes them the current user.
     *
     * Creating the user records a change; the change log is emptied afterwards, so that
     * tests start from an empty log on every database. (On PostgreSQL, PHPUnit runs each
     * test in a transaction, so the change would never be recorded; elsewhere it would.)
     *
     * @return \stdClass The user.
     */
    protected function set_exporter(): \stdClass {
        global $DB;
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $roleid = $generator->create_role();
        $systemcontext = \core\context\system::instance();
        assign_capability('local/arcs:export', CAP_ALLOW, $roleid, $systemcontext->id);
        role_assign($roleid, $user->id, $systemcontext->id);
        $this->setUser($user);
        $DB->delete_records(recorder::TABLE);
        return $user;
    }
}
