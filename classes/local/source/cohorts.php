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

namespace local_arcs\local\source;

/**
 * Reads cohorts (the cohort table), with the category that holds each one.
 *
 * A cohort belongs to the system context or to a course category's context. The
 * category is worked out from the context, so readers need not know context ids.
 * Descriptions, themes and custom fields are not read.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohorts extends source {
    /**
     * The columns to select.
     *
     * @return string
     */
    protected function fields(): string {
        return 'c.id, c.contextid, c.name, c.idnumber, c.visible, c.component, c.timecreated, c.timemodified, ' .
            'ctx.contextlevel, ctx.instanceid';
    }

    /**
     * The tables to read from.
     *
     * @return string
     */
    protected function from(): string {
        return '{cohort} c LEFT JOIN {context} ctx ON ctx.id = c.contextid';
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 'c.id';
    }

    /**
     * Shapes a page of cohorts.
     *
     * @param \stdClass[] $records The cohorts.
     * @return array[]
     */
    protected function shape(array $records): array {
        $rows = [];
        foreach ($records as $record) {
            $incategory = $record->contextlevel !== null && (int) $record->contextlevel === CONTEXT_COURSECAT;
            $rows[] = [
                'id' => (int) $record->id,
                'contextid' => (int) $record->contextid,
                'categoryid' => $incategory ? (int) $record->instanceid : null,
                'name' => $record->name,
                'idnumber' => $record->idnumber,
                'visible' => (bool) $record->visible,
                'component' => $record->component,
                'timecreated' => (int) $record->timecreated,
                'timemodified' => (int) $record->timemodified,
            ];
        }
        return $rows;
    }
}
