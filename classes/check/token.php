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
 * Status check: Arcs has a token it can use for the Arcs connector service.
 *
 * A token is usable when it is permanent and has not expired, and its user is active,
 * is authorised on the service (when the service is restricted to authorised users) and
 * holds local/arcs:export and webservice/rest:use at system level. Tokens are counted,
 * never shown.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token extends check {
    /** @var string[] Capabilities a token's user needs at system level. */
    private const CAPABILITIES = ['local/arcs:export', 'webservice/rest:use'];

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param \core\clock $clock The clock that decides which tokens and authorisations have expired.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        private \moodle_database $db,
        /** @var \core\clock The clock that decides which tokens and authorisations have expired. */
        private \core\clock $clock,
    ) {
    }

    /**
     * A link to the web service tokens.
     *
     * @return action_link
     */
    public function get_action_link(): ?action_link {
        return new action_link(
            new \core\url('/admin/webservice/tokens.php'),
            get_string('managetokens', 'webservice')
        );
    }

    /**
     * Counts the usable tokens.
     *
     * @return result
     */
    public function get_result(): result {
        $serviceid = $this->db->get_field('external_services', 'id', ['shortname' => service::SHORTNAME]);
        $tokens = $serviceid ? $this->db->count_records('external_tokens', [
            'externalserviceid' => $serviceid,
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        ]) : 0;
        $details = get_string('checktoken_details', 'local_arcs');
        if ($tokens === 0) {
            return new result(result::ERROR, get_string('checktoken_none', 'local_arcs'), $details);
        }

        $usable = $this->count_usable((int) $serviceid);
        if ($usable === 0) {
            return new result(result::ERROR, get_string('checktoken_unusable', 'local_arcs', $tokens), $details);
        }
        return new result(result::OK, get_string('checktoken_ok', 'local_arcs', $usable));
    }

    /**
     * Counts the service's tokens that Arcs can use.
     *
     * @param int $serviceid The service.
     * @return int
     */
    private function count_usable(int $serviceid): int {
        $now = $this->clock->time();
        $sql = "SELECT t.userid, COUNT(t.id) AS tokens
                  FROM {external_tokens} t
                  JOIN {external_services} s ON s.id = t.externalserviceid
                  JOIN {user} u ON u.id = t.userid
                 WHERE t.externalserviceid = :serviceid
                   AND t.tokentype = :permanent
                   AND (t.validuntil IS NULL OR t.validuntil = 0 OR t.validuntil > :now)
                   AND u.deleted = 0
                   AND u.suspended = 0
                   AND (s.restrictedusers = 0 OR EXISTS (
                           SELECT 1
                             FROM {external_services_users} su
                            WHERE su.externalserviceid = t.externalserviceid
                              AND su.userid = t.userid
                              AND (su.validuntil IS NULL OR su.validuntil = 0 OR su.validuntil > :authnow)
                       ))
              GROUP BY t.userid";
        $params = [
            'serviceid' => $serviceid,
            'permanent' => EXTERNAL_TOKEN_PERMANENT,
            'now' => $now,
            'authnow' => $now,
        ];

        $context = \core\context\system::instance();
        $usable = 0;
        foreach ($this->db->get_records_sql_menu($sql, $params) as $userid => $count) {
            if (has_all_capabilities(self::CAPABILITIES, $context, $userid)) {
                $usable += (int) $count;
            }
        }
        return $usable;
    }
}
