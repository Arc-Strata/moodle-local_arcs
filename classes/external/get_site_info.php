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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_arcs\local\changelog\reader;
use local_arcs\local\changelog\retention;
use local_arcs\local\logstore;
use local_arcs\local\settings\settings;
use local_arcs\local\site_info;

/**
 * Web service function local_arcs_get_site_info.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_site_info extends external_api {
    /**
     * Describes the parameters: none.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Returns the site facts Arcs reads before each sync.
     *
     * @return array
     */
    public static function execute(): array {
        global $CFG, $DB;

        self::validate_parameters(self::execute_parameters(), []);
        $context = \core\context\system::instance();
        self::validate_context($context);
        require_capability('local/arcs:export', $context);

        $clock = \core\di::get(\core\clock::class);
        $retention = new retention($DB, $clock);
        $info = new site_info(
            $CFG,
            get_site(),
            new logstore($DB),
            new reader($DB, $clock, $retention),
            $retention,
            new settings()
        );
        return $info->export();
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'contractversion' => new external_value(PARAM_INT, 'Version of the web service contract'),
            'pluginversion' => new external_value(PARAM_INT, 'Installed local_arcs version (YYYYMMDDXX)'),
            'pluginrelease' => new external_value(PARAM_TEXT, 'Installed local_arcs release name'),
            'moodlerelease' => new external_value(PARAM_TEXT, 'Moodle release name'),
            'moodlebranch' => new external_value(PARAM_ALPHANUM, 'Moodle branch, such as 405 or 503'),
            'siteidentifier' => new external_value(PARAM_RAW, 'Moodle site identifier, stable for the life of the site'),
            'wwwroot' => new external_value(PARAM_URL, 'Site URL'),
            'sitefullname' => new external_value(PARAM_RAW, 'Site full name, raw (the site course fullname)'),
            'defaultlang' => new external_value(PARAM_ALPHANUMEXT, 'Site default language code, such as en or pt_br'),
            'timezone' => new external_value(PARAM_TIMEZONE, 'Site default timezone; all timestamps are Unix seconds'),
            'logstoreenabled' => new external_value(PARAM_BOOL, 'Whether the standard log store is enabled'),
            'logstorelifetime' => new external_value(PARAM_INT, 'Days the standard log store keeps rows; 0 means forever'),
            'logstoreoldestid' => new external_value(PARAM_INT, 'Lowest log id, or null', VALUE_REQUIRED, null, NULL_ALLOWED),
            'logstorenewestid' => new external_value(PARAM_INT, 'Highest log id, or null', VALUE_REQUIRED, null, NULL_ALLOWED),
            'changelogoldestid' => new external_value(PARAM_INT, 'Lowest change id, or null', VALUE_REQUIRED, null, NULL_ALLOWED),
            'changelognewestid' => new external_value(PARAM_INT, 'Highest change id, or null', VALUE_REQUIRED, null, NULL_ALLOWED),
            'changeloghighwatermark' => new external_value(PARAM_INT, 'Highest change id readers may read up to now'),
            'changelogpurgedupto' => new external_value(PARAM_INT, 'Highest change id deleted for age; lower cursors are expired'),
            'changelogretentiondays' => new external_value(PARAM_INT, 'Days the change log keeps rows'),
            'optionalfields' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'A user field column, or profile_field_ and a custom profile field shortname'),
                'Optional user fields the site exports; any optional field not listed is withheld'
            ),
        ]);
    }
}
