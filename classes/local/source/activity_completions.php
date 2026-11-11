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
 * Reads activity completions (the course_modules_completion table), with whether the user viewed the activity.
 *
 * Since Moodle 4.3, views are kept apart from completion states, in course_modules_viewed
 * (one row per module and user); viewed is derived from it as core does.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_completions extends source {
    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int[] $courseids Only read completions in these courses; empty for all.
     * @param int[] $coursemoduleids Only read completions of these course modules; empty for all.
     */
    public function __construct(
        \moodle_database $db,
        /** @var int[] Only read completions in these courses; empty for all. */
        private array $courseids = [],
        /** @var int[] Only read completions of these course modules; empty for all. */
        private array $coursemoduleids = [],
    ) {
        parent::__construct($db);
    }

    /**
     * The columns to select.
     *
     * @return string
     */
    protected function fields(): string {
        return 'cmc.id, cmc.coursemoduleid, cmc.userid, cmc.completionstate, cmc.timemodified, ' .
            'CASE WHEN v.id IS NULL THEN 0 ELSE 1 END AS viewed';
    }

    /**
     * The tables to read from.
     *
     * @return string
     */
    protected function from(): string {
        return '{course_modules_completion} cmc
                 JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
            LEFT JOIN {course_modules_viewed} v ON v.coursemoduleid = cmc.coursemoduleid AND v.userid = cmc.userid';
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 'cmc.id';
    }

    /**
     * Only the requested courses' or course modules' completions, when the reader asked for some.
     *
     * @return array [string $sql, array $params]
     */
    protected function filter(): array {
        if ($this->courseids) {
            [$insql, $params] = $this->db->get_in_or_equal($this->courseids, SQL_PARAMS_NAMED, 'course');
            return ['cm.course ' . $insql, $params];
        }
        if ($this->coursemoduleids) {
            [$insql, $params] = $this->db->get_in_or_equal($this->coursemoduleids, SQL_PARAMS_NAMED, 'cm');
            return ['cmc.coursemoduleid ' . $insql, $params];
        }
        return ['', []];
    }

    /**
     * Shapes a page of activity completions.
     *
     * @param \stdClass[] $records The activity completions.
     * @return array[]
     */
    protected function shape(array $records): array {
        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => (int) $record->id,
                'coursemoduleid' => (int) $record->coursemoduleid,
                'userid' => (int) $record->userid,
                'completionstate' => (int) $record->completionstate,
                'viewed' => (bool) $record->viewed,
                'timemodified' => (int) $record->timemodified,
            ];
        }
        return $rows;
    }
}
