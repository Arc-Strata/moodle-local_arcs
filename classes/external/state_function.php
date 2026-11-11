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

use core\exception\invalid_parameter_exception;
use core\exception\moodle_exception;
use core_external\external_api;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_arcs\local\source\source;

/**
 * The paging parameters, checks and return shape shared by the state functions.
 *
 * A state function returns rows of one kind, by cursor (after, limit) or by id (ids).
 * Some also take one filter (such as courseids) that narrows the rows paged by cursor.
 * Each subclass still validates its own parameters, context and capability in execute.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class state_function extends external_api {
    /**
     * Describes the paging parameters: after, limit and ids.
     *
     * @return array For external_function_parameters.
     */
    protected static function paging_parameters(): array {
        return [
            'after' => new external_value(PARAM_INT, 'Return rows with a higher id than this cursor', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Most rows, or ids, to return', VALUE_DEFAULT, source::DEFAULT_LIMIT),
            'ids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Row id'),
                'Return only the rows with these ids that exist; after is then ignored',
                VALUE_DEFAULT,
                []
            ),
        ];
    }

    /**
     * Describes a filter parameter: a list of ids that narrows the rows, such as courseids.
     *
     * @param string $description What the filter keeps.
     * @return external_multiple_structure
     */
    protected static function filter_parameter(string $description): external_multiple_structure {
        return new external_multiple_structure(
            new external_value(PARAM_INT, 'Id'),
            $description . '; cannot be combined with ids',
            VALUE_DEFAULT,
            []
        );
    }

    /**
     * Checks a filter parameter against the ids parameter and the maximum.
     *
     * @param int[] $ids The ids parameter.
     * @param int[] $filterids The filter's ids.
     * @return int[] The filter's ids, each once.
     * @throws invalid_parameter_exception When both ids and the filter are given.
     * @throws moodle_exception limit_exceeded, when the filter has more ids than MAX_LIMIT.
     */
    protected static function check_filter(array $ids, array $filterids): array {
        if ($ids && $filterids) {
            throw new invalid_parameter_exception('ids cannot be combined with a filter');
        }
        $filterids = array_values(array_unique($filterids));
        if (count($filterids) > source::MAX_LIMIT) {
            throw new moodle_exception('limit_exceeded', 'local_arcs', '', source::MAX_LIMIT);
        }
        return $filterids;
    }

    /**
     * Checks the paging parameters and reads a page from the source.
     *
     * @param source $source The source to read.
     * @param int $after Cursor.
     * @param int $limit Most rows, or ids.
     * @param int[] $ids Ids to return; empty to page by cursor.
     * @return array With 'rows' and 'next'.
     * @throws invalid_parameter_exception When after is negative or limit is below 1.
     * @throws moodle_exception limit_exceeded, when limit is above the maximum or there are more ids than limit.
     */
    protected static function read(source $source, int $after, int $limit, array $ids): array {
        if ($after < 0 || $limit < 1) {
            throw new invalid_parameter_exception('after must be 0 or more, and limit 1 or more');
        }
        if ($limit > source::MAX_LIMIT) {
            throw new moodle_exception('limit_exceeded', 'local_arcs', '', source::MAX_LIMIT);
        }
        if (!$ids) {
            return $source->page($after, $limit);
        }

        $ids = array_unique($ids);
        if (count($ids) > $limit) {
            throw new moodle_exception('limit_exceeded', 'local_arcs', '', $limit);
        }
        return $source->by_ids($ids);
    }

    /**
     * Describes the return value: a list of rows under the given key, and the next cursor.
     *
     * @param string $key The name of the list, such as users.
     * @param external_single_structure $row The shape of one row.
     * @return external_single_structure
     */
    protected static function paged_returns(string $key, external_single_structure $row): external_single_structure {
        return new external_single_structure([
            $key => new external_multiple_structure($row),
            'next' => new external_value(
                PARAM_INT,
                'Cursor to pass as after for the next page; null when there are no more rows',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
        ]);
    }
}
