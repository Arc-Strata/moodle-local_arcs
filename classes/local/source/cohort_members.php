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
 * Reads cohort memberships (the cohort_members table).
 *
 * Core's membership events name a membership by its cohort and user, not by its row id,
 * so readers can select memberships by cohorts and users as well as by id.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohort_members extends source {
    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int[] $cohortids Only read memberships of these cohorts; empty for all.
     * @param int[] $userids Only read memberships of these users; empty for all.
     */
    public function __construct(
        \moodle_database $db,
        /** @var int[] Only read memberships of these cohorts; empty for all. */
        private array $cohortids = [],
        /** @var int[] Only read memberships of these users; empty for all. */
        private array $userids = [],
    ) {
        parent::__construct($db);
    }

    /**
     * The columns to select.
     *
     * @return string
     */
    protected function fields(): string {
        return 'cm.id, cm.cohortid, cm.userid, cm.timeadded';
    }

    /**
     * The table to read from.
     *
     * @return string
     */
    protected function from(): string {
        return '{cohort_members} cm';
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 'cm.id';
    }

    /**
     * Only the requested cohorts' and users' memberships, when the reader asked for some.
     * Both together select the memberships of any of the users in any of the cohorts.
     *
     * @return array [string $sql, array $params]
     */
    protected function filter(): array {
        $conditions = [];
        $params = [];
        if ($this->cohortids) {
            [$insql, $inparams] = $this->db->get_in_or_equal($this->cohortids, SQL_PARAMS_NAMED, 'cohort');
            $conditions[] = 'cm.cohortid ' . $insql;
            $params += $inparams;
        }
        if ($this->userids) {
            [$insql, $inparams] = $this->db->get_in_or_equal($this->userids, SQL_PARAMS_NAMED, 'user');
            $conditions[] = 'cm.userid ' . $insql;
            $params += $inparams;
        }
        return [implode(' AND ', $conditions), $params];
    }

    /**
     * Shapes a page of cohort memberships.
     *
     * @param \stdClass[] $records The memberships.
     * @return array[]
     */
    protected function shape(array $records): array {
        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => (int) $record->id,
                'cohortid' => (int) $record->cohortid,
                'userid' => (int) $record->userid,
                'timeadded' => (int) $record->timeadded,
            ];
        }
        return $rows;
    }
}
