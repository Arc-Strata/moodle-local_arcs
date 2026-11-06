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
 * Reads custom profile field definitions (the user_info_field table).
 *
 * Every definition is listed, whether or not its values are exported, so that readers
 * can label the values they do receive.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profile_fields extends source {
    /**
     * The columns to select.
     *
     * @return string
     */
    protected function fields(): string {
        return 'f.id, f.shortname, f.name, f.datatype, f.categoryid, f.visible';
    }

    /**
     * The table to read from.
     *
     * @return string
     */
    protected function from(): string {
        return '{user_info_field} f';
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 'f.id';
    }

    /**
     * Shapes a page of profile field definitions.
     *
     * @param \stdClass[] $records The definitions.
     * @return array[]
     */
    protected function shape(array $records): array {
        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => (int) $record->id,
                'shortname' => $record->shortname,
                'name' => $record->name,
                'datatype' => $record->datatype,
                'categoryid' => (int) $record->categoryid,
                'visible' => (int) $record->visible,
            ];
        }
        return $rows;
    }
}
