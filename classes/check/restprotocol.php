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
 * Status check: the REST protocol is enabled, since Arcs calls the site through it.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restprotocol extends check {
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
     * A link to the web service protocols.
     *
     * @return action_link
     */
    public function get_action_link(): ?action_link {
        return new action_link(
            new \core\url('/admin/settings.php', ['section' => 'webserviceprotocols']),
            get_string('manageprotocols', 'webservice')
        );
    }

    /**
     * Whether the REST protocol is enabled.
     *
     * @return result
     */
    public function get_result(): result {
        $protocols = explode(',', (string) ($this->cfg->webserviceprotocols ?? ''));
        if (!in_array('rest', $protocols, true)) {
            return new result(result::ERROR, get_string('checkrestprotocol_error', 'local_arcs'));
        }
        return new result(result::OK, get_string('checkrestprotocol_ok', 'local_arcs'));
    }
}
