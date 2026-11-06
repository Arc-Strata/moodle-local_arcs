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

namespace local_arcs\local;

/**
 * Collects the site-level facts Arcs reads before each sync.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class site_info {
    /**
     * Constructor.
     *
     * @param \stdClass $cfg The site configuration ($CFG).
     * @param logstore $logstore The standard log store's state.
     */
    public function __construct(
        /** @var \stdClass The site configuration ($CFG). */
        private \stdClass $cfg,
        /** @var logstore The standard log store's state. */
        private logstore $logstore,
    ) {
    }

    /**
     * Returns the site facts, shaped for local_arcs_get_site_info.
     *
     * @return array
     */
    public function export(): array {
        $plugin = \core\plugin_manager::instance()->get_plugin_info('local_arcs');

        return [
            'contractversion' => contract::VERSION,
            'pluginversion' => (int) $plugin->versiondb,
            'pluginrelease' => (string) $plugin->release,
            'moodlerelease' => (string) $this->cfg->release,
            'moodlebranch' => (string) $this->cfg->branch,
            'siteidentifier' => get_site_identifier(),
            'wwwroot' => (string) $this->cfg->wwwroot,
            'timezone' => \core_date::get_server_timezone(),
            'logstoreenabled' => $this->logstore->is_enabled(),
            'logstorelifetime' => $this->logstore->lifetime_days(),
        ];
    }
}
