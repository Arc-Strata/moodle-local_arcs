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
 * Reads assignment submissions (the assign_submission table), each with the grade for that attempt.
 *
 * Only ids, statuses, times and grades are read, never submitted content or feedback. A grade is
 * returned only once Moodle would release it to the gradebook: not while blind marking hides the
 * students' identities (unless the assignment uses anonymous marking), nor, under a marking
 * workflow, before the user's grade is released. The rows of team submissions that belong to the
 * group (userid 0) and submissions to assignments on the site course are left out.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_submissions extends attempts {
    /** @var string The marking workflow state of a released grade (ASSIGN_MARKING_WORKFLOW_STATE_RELEASED). */
    private const RELEASED = 'released';

    /** @var bool Whether assign_grades has penalty (grade penalties, from Moodle 5.0). */
    private bool $haspenalty;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int[] $courseids Only read submissions in these courses; empty for all.
     * @param int[] $coursemoduleids Only read submissions to these course modules; empty for all.
     */
    public function __construct(\moodle_database $db, array $courseids = [], array $coursemoduleids = []) {
        parent::__construct($db, $courseids, $coursemoduleids);
        $this->haspenalty = array_key_exists('penalty', $db->get_columns('assign_grades'));
    }

    /**
     * The activity module's name.
     *
     * @return string
     */
    public function modname(): string {
        return 'assign';
    }

    /**
     * The columns to select.
     *
     * @return string
     */
    protected function fields(): string {
        return 's.id, s.userid, cm.id AS coursemoduleid, s.attemptnumber, s.status, s.timecreated, s.timemodified, ' .
            's.timestarted, a.grade AS assigngrade, a.blindmarking, a.revealidentities, a.markinganonymous, ' .
            'a.markingworkflow, f.workflowstate, g.grade, g.timemodified AS timegraded' .
            ($this->haspenalty ? ', g.penalty' : '');
    }

    /**
     * The tables to read from. A user has at most one grade per attempt and one set of flags per assignment.
     *
     * @return string
     */
    protected function from(): string {
        return '{assign_submission} s
                 JOIN {assign} a ON a.id = s.assignment
                 JOIN {course_modules} cm ON cm.instance = a.id
                 JOIN {modules} m ON m.id = cm.module
            LEFT JOIN {assign_grades} g ON g.assignment = s.assignment AND g.userid = s.userid
                      AND g.attemptnumber = s.attemptnumber
            LEFT JOIN {assign_user_flags} f ON f.assignment = s.assignment AND f.userid = s.userid';
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 's.id';
    }

    /**
     * Leaves out team submissions' group rows, on top of the common attempt condition.
     *
     * @return array [string $sql, array $params]
     */
    protected function filter(): array {
        [$sql, $params] = parent::filter();
        return [$sql . ' AND s.userid > 0', $params];
    }

    /**
     * Shapes a page of submissions.
     *
     * @param \stdClass[] $records The submissions.
     * @return array[]
     */
    protected function shape(array $records): array {
        $rows = [];
        foreach ($records as $record) {
            $assigngrade = (int) $record->assigngrade;
            $grade = $this->released($record) ? $this->grade($record->grade, $assigngrade) : null;
            $rows[] = [
                'id' => (int) $record->id,
                'userid' => (int) $record->userid,
                'coursemoduleid' => (int) $record->coursemoduleid,
                'attempt' => (int) $record->attemptnumber + 1,
                'status' => $record->status,
                'timecreated' => (int) $record->timecreated,
                'timemodified' => (int) $record->timemodified,
                'timestarted' => $record->timestarted ? (int) $record->timestarted : null,
                'grade' => $grade,
                'grademax' => $assigngrade > 0 ? (float) $assigngrade : null,
                'scaleid' => $assigngrade < 0 ? -$assigngrade : null,
                'penalty' => $grade !== null && $this->haspenalty ? (float) $record->penalty : null,
                'timegraded' => $grade !== null ? (int) $record->timegraded : null,
                'passed' => null,
            ];
        }
        return $rows;
    }

    /**
     * Whether Moodle would release the user's grade to the gradebook, as assign::gradebook_item_update decides.
     *
     * @param \stdClass $record The submission, with its assignment's settings and the user's workflow state.
     * @return bool
     */
    private function released(\stdClass $record): bool {
        if ($record->blindmarking && !$record->revealidentities && !$record->markinganonymous) {
            return false;
        }
        return !$record->markingworkflow || $record->workflowstate === self::RELEASED;
    }

    /**
     * A grade as the gradebook would take it: points, or a position on the scale.
     *
     * @param string|null $grade The grade in assign_grades; null or -1 when there is none.
     * @param int $assigngrade The assignment's grade setting: the maximum points, minus the scale id, or 0 for none.
     * @return float|null Null when there is no grade, or the assignment is not graded.
     */
    private function grade(?string $grade, int $assigngrade): ?float {
        if ($grade === null || (float) $grade < 0 || $assigngrade === 0) {
            return null;
        }
        return (float) $grade;
    }
}
