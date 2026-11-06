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
 * Event observers for local_arcs: each records a change log row.
 *
 * All are external (internal false), so they run only after the change commits.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => \core\event\user_created::class,
        'callback' => [\local_arcs\observer::class, 'user_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\user_updated::class,
        'callback' => [\local_arcs\observer::class, 'user_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\user_deleted::class,
        'callback' => [\local_arcs\observer::class, 'user_deleted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\user_info_field_created::class,
        'callback' => [\local_arcs\observer::class, 'profile_field_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\user_info_field_updated::class,
        'callback' => [\local_arcs\observer::class, 'profile_field_upserted'],
        'internal' => false,
    ],
    [
        'eventname' => \core\event\user_info_field_deleted::class,
        'callback' => [\local_arcs\observer::class, 'profile_field_deleted'],
        'internal' => false,
    ],
];
