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
 * Reads course categories (the course_categories table).
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class categories extends source {
    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int[] $categoryids Only read these categories and the categories below them; empty for all.
     */
    public function __construct(
        \moodle_database $db,
        /** @var int[] Only read these categories and the categories below them; empty for all. */
        private array $categoryids = [],
    ) {
        parent::__construct($db);
    }

    /**
     * SQL that is true when a category is one of the given categories or below one of them.
     *
     * Categories are matched by path, so a category's own path ('/3/12') and those below it
     * ('/3/12/40') match, and a sibling with a longer id ('/3/120') does not. Paths hold only
     * digits and slashes, so a plain LIKE against another column is safe on every database,
     * as core_course_category does for context paths.
     *
     * @param \moodle_database $db The database.
     * @param string $column The category id column to test, qualified by table alias, such as c.category.
     * @param int[] $categoryids The categories, at least one.
     * @param string $prefix A prefix for the parameter names, unique in the query.
     * @return array [string $sql, array $params]
     */
    public static function within_sql(\moodle_database $db, string $column, array $categoryids, string $prefix): array {
        [$insql, $params] = $db->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, $prefix);
        $sql = "EXISTS (
                    SELECT 1
                      FROM {course_categories} {$prefix}tc
                      JOIN {course_categories} {$prefix}top ON {$prefix}top.id $insql
                     WHERE {$prefix}tc.id = $column
                       AND ({$prefix}tc.id = {$prefix}top.id
                            OR {$prefix}tc.path LIKE " . $db->sql_concat("{$prefix}top.path", "'/%'") . ")
                )";
        return [$sql, $params];
    }

    /**
     * The columns to select.
     *
     * @return string
     */
    protected function fields(): string {
        return 'c.id, c.name, c.idnumber, c.parent, c.path, c.visible, c.timemodified';
    }

    /**
     * The table to read from.
     *
     * @return string
     */
    protected function from(): string {
        return '{course_categories} c';
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
     * Only the requested categories and those below them, when the reader asked for some.
     *
     * @return array [string $sql, array $params]
     */
    protected function filter(): array {
        if (!$this->categoryids) {
            return ['', []];
        }
        return self::within_sql($this->db, 'c.id', $this->categoryids, 'cat');
    }

    /**
     * Shapes a page of categories.
     *
     * @param \stdClass[] $records The categories.
     * @return array[]
     */
    protected function shape(array $records): array {
        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => (int) $record->id,
                'name' => $record->name,
                'idnumber' => $record->idnumber,
                'parent' => (int) $record->parent,
                'path' => $record->path,
                'visible' => (bool) $record->visible,
                'timemodified' => (int) $record->timemodified,
            ];
        }
        return $rows;
    }
}
