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
 * Web service functions and the pre-built service for local_arcs.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_arcs_get_categories' => [
        'classname' => \local_arcs\external\get_categories::class,
        'description' => 'Returns course categories, by cursor, by id, or below given categories.',
        'type' => 'read',
        'capabilities' => 'local/arcs:export',
        'ajax' => false,
        'readonlysession' => true,
    ],
    'local_arcs_get_changes' => [
        'classname' => \local_arcs\external\get_changes::class,
        'description' => 'Returns a page of the change log: which records changed, by id, after a cursor.',
        'type' => 'read',
        'capabilities' => 'local/arcs:export',
        'ajax' => false,
        'readonlysession' => true,
    ],
    'local_arcs_get_course_modules' => [
        'classname' => \local_arcs\external\get_course_modules::class,
        'description' => 'Returns course modules with their type and instance name, by cursor, by id, or by course.',
        'type' => 'read',
        'capabilities' => 'local/arcs:export',
        'ajax' => false,
        'readonlysession' => true,
    ],
    'local_arcs_get_courses' => [
        'classname' => \local_arcs\external\get_courses::class,
        'description' => 'Returns courses other than the site course, by cursor, by id, or by category.',
        'type' => 'read',
        'capabilities' => 'local/arcs:export',
        'ajax' => false,
        'readonlysession' => true,
    ],
    'local_arcs_get_profile_fields' => [
        'classname' => \local_arcs\external\get_profile_fields::class,
        'description' => 'Returns custom profile field definitions, by cursor or by id.',
        'type' => 'read',
        'capabilities' => 'local/arcs:export',
        'ajax' => false,
        'readonlysession' => true,
    ],
    'local_arcs_get_site_info' => [
        'classname' => \local_arcs\external\get_site_info::class,
        'description' => 'Returns the contract version, plugin and Moodle versions, site identity and log store state.',
        'type' => 'read',
        'capabilities' => 'local/arcs:export',
        'ajax' => false,
        'readonlysession' => true,
    ],
    'local_arcs_get_users' => [
        'classname' => \local_arcs\external\get_users::class,
        'description' => 'Returns users, with the optional fields an admin has chosen to export, by cursor or by id.',
        'type' => 'read',
        'capabilities' => 'local/arcs:export',
        'ajax' => false,
        'readonlysession' => true,
    ],
];

$services = [
    'Arcs connector' => [
        'functions' => [
            'local_arcs_get_categories',
            'local_arcs_get_changes',
            'local_arcs_get_course_modules',
            'local_arcs_get_courses',
            'local_arcs_get_profile_fields',
            'local_arcs_get_site_info',
            'local_arcs_get_users',
        ],
        'shortname' => 'local_arcs',
        'requiredcapability' => 'local/arcs:export',
        'restrictedusers' => 1,
        'enabled' => 0,
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
];
