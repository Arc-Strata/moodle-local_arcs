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

namespace local_arcs\check;

use core\check\check;
use core\check\result;
use core\output\action_link;

/**
 * Status check: the Arcs connector external service is enabled. It is installed disabled.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service extends check {
    /** @var string Shortname of the plugin's external service. */
    public const SHORTNAME = 'local_arcs';

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        private \moodle_database $db,
    ) {
    }

    /**
     * A link to the service's settings.
     *
     * @return action_link|null
     */
    public function get_action_link(): ?action_link {
        $id = $this->db->get_field('external_services', 'id', ['shortname' => self::SHORTNAME]);
        if ($id === false) {
            return null;
        }
        return new action_link(
            new \core\url('/admin/webservice/service.php', ['id' => $id]),
            get_string('editexternalservice', 'webservice')
        );
    }

    /**
     * Whether the service is enabled.
     *
     * @return result
     */
    public function get_result(): result {
        $enabled = $this->db->get_field('external_services', 'enabled', ['shortname' => self::SHORTNAME]);
        if (empty($enabled)) {
            return new result(result::ERROR, get_string('checkservice_error', 'local_arcs'));
        }
        return new result(result::OK, get_string('checkservice_ok', 'local_arcs'));
    }
}
