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

use local_arcs\local\changelog\reader;
use local_arcs\local\changelog\retention;
use local_arcs\local\settings\settings;

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
     * @param reader $changes The change log reader.
     * @param retention $retention The change log's retention state.
     * @param settings $settings The plugin's settings.
     */
    public function __construct(
        /** @var \stdClass The site configuration ($CFG). */
        private \stdClass $cfg,
        /** @var logstore The standard log store's state. */
        private logstore $logstore,
        /** @var reader The change log reader. */
        private reader $changes,
        /** @var retention The change log's retention state. */
        private retention $retention,
        /** @var settings The plugin's settings. */
        private settings $settings,
    ) {
    }

    /**
     * Returns the site facts, shaped for local_arcs_get_site_info.
     *
     * @return array
     */
    public function export(): array {
        $plugin = \core\plugin_manager::instance()->get_plugin_info('local_arcs');
        $bounds = $this->changes->bounds();

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
            'changelogoldestid' => $bounds['oldest'],
            'changelognewestid' => $bounds['newest'],
            'changeloghighwatermark' => $this->changes->high_water_mark(),
            'changelogpurgedupto' => $this->retention->purged_up_to(),
            'changelogretentiondays' => $this->settings->retention_days(),
            'optionalfields' => $this->settings->optional_fields(),
        ];
    }
}
