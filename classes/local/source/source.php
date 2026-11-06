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
 * Reads one kind of Moodle record for export, a page at a time, in ascending id order.
 *
 * Subclasses name the columns and tables to read, and shape each page of records
 * for the web service. They read a whole page at once, so that anything a page
 * needs from other tables can be read in one query per page, not one per row.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class source {
    /** @var int Rows per page when the reader does not say. */
    public const DEFAULT_LIMIT = 1000;

    /** @var int Most rows, or ids, a reader may ask for in one call. */
    public const MAX_LIMIT = 1000;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected \moodle_database $db,
    ) {
    }

    /**
     * The columns to select, qualified by table alias. They must include the record's own id, as id.
     *
     * @return string
     */
    abstract protected function fields(): string;

    /**
     * The tables to read from, with aliases, as for a FROM clause.
     *
     * @return string
     */
    abstract protected function from(): string;

    /**
     * The record's id column, qualified by table alias, such as u.id.
     *
     * @return string
     */
    abstract protected function id_column(): string;

    /**
     * A condition every record must meet, on top of the cursor or ids, with its named parameters.
     *
     * Subclasses override it to leave records out (such as the site course) or to apply
     * a reader's filter (such as courseids). The default is no condition.
     *
     * @return array [string $sql, array $params]; $sql is empty for no condition.
     */
    protected function filter(): array {
        return ['', []];
    }

    /**
     * Shapes a page of records for the web service.
     *
     * @param \stdClass[] $records The records, in ascending id order.
     * @return array[] One array per record, in the same order.
     */
    abstract protected function shape(array $records): array;

    /**
     * Returns the records with an id above a cursor.
     *
     * @param int $after Cursor: return records with a higher id.
     * @param int $limit Most records to return.
     * @return array With 'rows' (the shaped records) and 'next' (the last id returned,
     *     or null when no records are left after this page).
     */
    public function page(int $after, int $limit): array {
        $records = $this->read($this->id_column() . ' > :after', ['after' => $after], $limit + 1);
        $hasmore = count($records) > $limit;
        if ($hasmore) {
            array_pop($records);
        }

        return [
            'rows' => $this->shape($records),
            'next' => $hasmore ? (int) end($records)->id : null,
        ];
    }

    /**
     * Returns the records with the given ids that exist.
     *
     * @param int[] $ids The ids. Callers keep the number of ids within MAX_LIMIT.
     * @return array With 'rows' (the shaped records that exist and meet the filter, in ascending
     *     id order) and 'next' (always null).
     */
    public function by_ids(array $ids): array {
        [$insql, $params] = $this->db->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'id');
        $records = $this->read($this->id_column() . ' ' . $insql, $params, 0);
        return ['rows' => $this->shape($records), 'next' => null];
    }

    /**
     * Reads records in ascending id order.
     *
     * @param string $where The condition on the records.
     * @param array $params Its named parameters.
     * @param int $limit Most records to read; 0 for no limit.
     * @return \stdClass[] The records, as a list.
     */
    private function read(string $where, array $params, int $limit): array {
        [$filtersql, $filterparams] = $this->filter();
        if ($filtersql !== '') {
            $where .= ' AND ' . $filtersql;
            $params += $filterparams;
        }

        $sql = 'SELECT ' . $this->fields() .
              ' FROM ' . $this->from() .
             ' WHERE ' . $where .
          ' ORDER BY ' . $this->id_column() . ' ASC';

        $records = [];
        $rs = $this->db->get_recordset_sql($sql, $params, 0, $limit);
        foreach ($rs as $record) {
            $records[] = $record;
        }
        $rs->close();
        return $records;
    }
}
