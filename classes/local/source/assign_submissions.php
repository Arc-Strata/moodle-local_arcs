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
 * When asked for detail, each submission with a grade also carries how the grade was reached with a
 * rubric or marking guide: the grading definition, and the level chosen or score given for each of
 * its criteria. These come from the current grading instance (active, or needing an update after the
 * definition was edited) of the assignment's active grading method for the submission's grade.
 * Remarks and overall feedback are never read.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_submissions extends attempts {
    /** @var string The marking workflow state of a released grade (ASSIGN_MARKING_WORKFLOW_STATE_RELEASED). */
    private const RELEASED = 'released';

    /** @var int A grading instance in use (gradingform_instance::INSTANCE_STATUS_ACTIVE). */
    private const INSTANCE_ACTIVE = 1;

    /**
     * @var int A grading instance still in use, whose definition was edited after grading
     *     (gradingform_instance::INSTANCE_STATUS_NEEDUPDATE).
     */
    private const INSTANCE_NEEDUPDATE = 2;

    /** @var bool Whether assign_grades has penalty (grade penalties, from Moodle 5.0). */
    private bool $haspenalty;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int[] $courseids Only read submissions in these courses; empty for all.
     * @param int[] $coursemoduleids Only read submissions to these course modules; empty for all.
     * @param bool $includedetail Whether to read each graded submission's rubric or marking guide fillings.
     */
    public function __construct(
        \moodle_database $db,
        array $courseids = [],
        array $coursemoduleids = [],
        /** @var bool Whether to read each graded submission's rubric or marking guide fillings. */
        private bool $includedetail = false,
    ) {
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
            'a.markingworkflow, f.workflowstate, g.id AS gradeid, g.grade, g.timemodified AS timegraded' .
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
        $grades = [];
        foreach ($records as $record) {
            $grade = $this->released($record) ? $this->grade($record->grade, (int) $record->assigngrade) : null;
            $grades[$record->id] = $grade;
        }
        $instances = $this->includedetail ? $this->instances($records, $grades) : [];

        $rows = [];
        foreach ($records as $record) {
            $assigngrade = (int) $record->assigngrade;
            $grade = $grades[$record->id];
            $row = [
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
            if ($this->includedetail) {
                $instance = $grade !== null ? ($instances[$record->gradeid] ?? null) : null;
                $row['gradingdefinitionid'] = $instance['definitionid'] ?? null;
                $row['fillings'] = $instance['fillings'] ?? [];
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Reads the current rubric and marking guide instances of a page's released grades, with their fillings.
     *
     * A method's tables are read only while its plugin is installed: uninstalling it drops them, along
     * with its definitions and instances.
     *
     * @param \stdClass[] $records The submissions, with their grade's id.
     * @param array $grades The grade each submission returns, keyed by submission id; null when none.
     * @return array[] Keyed by grade id: 'definitionid', and 'fillings' in criterion order.
     */
    private function instances(array $records, array $grades): array {
        $gradeids = [];
        foreach ($records as $record) {
            if ($grades[$record->id] !== null) {
                $gradeids[] = (int) $record->gradeid;
            }
        }
        if (!$gradeids) {
            return [];
        }

        $tables = $this->db->get_tables();
        $instances = [];
        if (isset($tables['gradingform_rubric_fillings'])) {
            $instances += $this->read_instances('rubric', $gradeids);
        }
        if (isset($tables['gradingform_guide_fillings'])) {
            $instances += $this->read_instances('guide', $gradeids);
        }
        return $instances;
    }

    /**
     * Reads one method's current instances for some grades, with their fillings.
     *
     * Only the instance of the area's active method counts, as assign shows it. Fillings for a
     * criterion that a later edit deleted are left out, as Moodle no longer shows them; a rubric
     * filling whose level was deleted has no level.
     *
     * @param string $method rubric or guide.
     * @param int[] $gradeids The grades (assign_grades ids, the instances' item ids).
     * @return array[] Keyed by grade id: 'definitionid', and 'fillings' in criterion order.
     */
    private function read_instances(string $method, array $gradeids): array {
        if ($method === 'rubric') {
            $fillingfields = 'l.id AS levelid, l.score';
            $fillingjoins = 'LEFT JOIN {gradingform_rubric_fillings} fl ON fl.instanceid = gi.id
                             LEFT JOIN {gradingform_rubric_criteria} c ON c.id = fl.criterionid
                             LEFT JOIN {gradingform_rubric_levels} l ON l.id = fl.levelid AND l.criterionid = c.id';
        } else {
            $fillingfields = 'fl.score';
            $fillingjoins = 'LEFT JOIN {gradingform_guide_fillings} fl ON fl.instanceid = gi.id
                             LEFT JOIN {gradingform_guide_criteria} c ON c.id = fl.criterionid';
        }
        [$itemsql, $params] = $this->db->get_in_or_equal($gradeids, SQL_PARAMS_NAMED, 'item');
        $sql = "SELECT fl.id AS fillingid, gi.id AS instanceid, gi.itemid, gi.definitionid, c.id AS criterionid,
                       c.sortorder, $fillingfields
                  FROM {grading_instances} gi
                  JOIN {grading_definitions} d ON d.id = gi.definitionid
                  JOIN {grading_areas} ga ON ga.id = d.areaid
                       $fillingjoins
                 WHERE gi.itemid $itemsql
                   AND gi.status IN (:active, :needupdate)
                   AND d.method = :method
                   AND ga.activemethod = :activemethod
                   AND ga.component = :component
                   AND ga.areaname = :areaname
              ORDER BY gi.itemid, c.sortorder, c.id";
        $params += [
            'active' => self::INSTANCE_ACTIVE,
            'needupdate' => self::INSTANCE_NEEDUPDATE,
            'method' => $method,
            'activemethod' => $method,
            'component' => 'mod_assign',
            'areaname' => 'submissions',
        ];

        $instances = [];
        $rs = $this->db->get_recordset_sql($sql, $params);
        foreach ($rs as $filling) {
            $instances[$filling->itemid] ??= ['definitionid' => (int) $filling->definitionid, 'fillings' => []];
            if ($filling->criterionid === null) {
                continue;
            }
            $instances[$filling->itemid]['fillings'][] = [
                'criterionid' => (int) $filling->criterionid,
                'levelid' => $method === 'rubric' && $filling->levelid !== null ? (int) $filling->levelid : null,
                'score' => $filling->score === null ? null : (float) $filling->score,
            ];
        }
        $rs->close();
        return $instances;
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
