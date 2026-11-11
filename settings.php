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

/**
 * Admin settings for local_arcs.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_arcs\local\settings\settings;

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_arcs', new \core\lang_string('pluginname', 'local_arcs'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading(
            'local_arcs/intro',
            '',
            new \core\lang_string('settingsintro', 'local_arcs', (new \core\url('/report/status/index.php'))->out())
        ));

        $choices = [];
        foreach (settings::RETENTION_DAYS_CHOICES as $days) {
            $choices[$days] = new \core\lang_string('numdays', 'core', $days);
        }
        $settings->add(new admin_setting_configselect(
            'local_arcs/retentiondays',
            new \core\lang_string('retentiondays', 'local_arcs'),
            new \core\lang_string('retentiondays_desc', 'local_arcs'),
            settings::DEFAULT_RETENTION_DAYS,
            $choices
        ));

        $settings->add(new admin_setting_configmultiselect(
            'local_arcs/optionalfields',
            new \core\lang_string('optionalfields', 'local_arcs'),
            new \core\lang_string('optionalfields_desc', 'local_arcs'),
            settings::DEFAULT_OPTIONAL_FIELDS,
            function (): array {
                global $CFG;
                require_once($CFG->dirroot . '/user/profile/lib.php');

                $choices = [];
                foreach (settings::OPTIONAL_USER_FIELDS as $field) {
                    $choices[$field] = new \core\lang_string($field);
                }
                $context = \core\context\system::instance();
                foreach (profile_get_custom_fields() as $field) {
                    $choices[\core_user\fields::PROFILE_FIELD_PREFIX . $field->shortname] = new \core\lang_string(
                        'optionalfields_custom',
                        'local_arcs',
                        format_string($field->name, true, ['context' => $context, 'escape' => false])
                    );
                }
                return $choices;
            }
        ));
    }
}
