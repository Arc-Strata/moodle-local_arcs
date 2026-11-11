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
 * Status check: web services are enabled, so Arcs can call the site.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webservices extends check {
    /**
     * Constructor.
     *
     * @param \stdClass $cfg The site configuration ($CFG).
     */
    public function __construct(
        /** @var \stdClass The site configuration ($CFG). */
        private \stdClass $cfg,
    ) {
    }

    /**
     * A link to the web services overview.
     *
     * @return action_link
     */
    public function get_action_link(): ?action_link {
        return new action_link(
            new \core\url('/admin/settings.php', ['section' => 'webservicesoverview']),
            get_string('webservicesoverview', 'webservice')
        );
    }

    /**
     * Whether web services are enabled.
     *
     * @return result
     */
    public function get_result(): result {
        if (empty($this->cfg->enablewebservices)) {
            return new result(result::ERROR, get_string('checkwebservices_error', 'local_arcs'));
        }
        return new result(result::OK, get_string('checkwebservices_ok', 'local_arcs'));
    }
}
