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

use mod_quiz\grade_calculator;

/**
 * Reads quiz attempts (the quiz_attempts table), with each attempt's grade on the quiz's scale.
 *
 * Only ids, states, times and marks are read, never responses. Previews, attempts that Moodle
 * created in advance and nobody has started yet (notstarted, from Moodle 5.3), and attempts at
 * quizzes on the site course are left out. When asked for detail, each attempt also carries its
 * slots: the question in each, the quiz section it is in, and its mark and state, from the
 * question attempt's latest step.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_attempts extends attempts {
    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int[] $courseids Only read attempts in these courses; empty for all.
     * @param int[] $coursemoduleids Only read attempts at these course modules; empty for all.
     * @param bool $includedetail Whether to read each attempt's slots.
     */
    public function __construct(
        \moodle_database $db,
        array $courseids = [],
        array $coursemoduleids = [],
        /** @var bool Whether to read each attempt's slots. */
        private bool $includedetail = false,
    ) {
        parent::__construct($db, $courseids, $coursemoduleids);
    }

    /**
     * The activity module's name.
     *
     * @return string
     */
    public function modname(): string {
        return 'quiz';
    }

    /**
     * The columns to select.
     *
     * @return string
     */
    protected function fields(): string {
        return 'qa.id, qa.userid, cm.id AS coursemoduleid, qa.attempt, qa.state, qa.timestart, qa.timefinish, ' .
            'qa.timemodified, qa.sumgrades, q.sumgrades AS maxsumgrades, q.grade AS grademax, qa.uniqueid, qa.quiz';
    }

    /**
     * The tables to read from.
     *
     * @return string
     */
    protected function from(): string {
        return '{quiz_attempts} qa
                 JOIN {quiz} q ON q.id = qa.quiz
                 JOIN {course_modules} cm ON cm.instance = q.id
                 JOIN {modules} m ON m.id = cm.module';
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 'qa.id';
    }

    /**
     * Leaves out previews and attempts not started, on top of the common attempt condition.
     *
     * @return array [string $sql, array $params]
     */
    protected function filter(): array {
        [$sql, $params] = parent::filter();
        // Moodle 4.5 has no notstarted state, so there the condition leaves nothing out.
        return [$sql . ' AND qa.preview = 0 AND qa.state <> :notstarted', $params + ['notstarted' => 'notstarted']];
    }

    /**
     * Shapes a page of quiz attempts.
     *
     * @param \stdClass[] $records The quiz attempts.
     * @return array[]
     */
    protected function shape(array $records): array {
        $slots = $this->includedetail ? $this->slots($records) : [];
        $rows = [];
        foreach ($records as $record) {
            $sumgrades = $record->sumgrades === null ? null : (float) $record->sumgrades;
            $maxsumgrades = (float) $record->maxsumgrades;
            $grademax = (float) $record->grademax;
            $row = [
                'id' => (int) $record->id,
                'userid' => (int) $record->userid,
                'coursemoduleid' => (int) $record->coursemoduleid,
                'attempt' => (int) $record->attempt,
                'state' => $record->state,
                'timestart' => (int) $record->timestart,
                'timefinish' => (int) $record->timefinish,
                'timemodified' => (int) $record->timemodified,
                'sumgrades' => $sumgrades,
                'maxsumgrades' => $maxsumgrades,
                'grade' => $this->grade($sumgrades, $maxsumgrades, $grademax),
                'grademax' => $grademax,
                'passed' => null,
            ];
            if ($this->includedetail) {
                $row['slots'] = $slots[$record->uniqueid] ?? [];
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Reads the slots of a page of attempts: each question attempt in their question usages, with
     * its latest step and the quiz section its slot is in.
     *
     * A question attempt always has a first step, so every slot has a latest one.
     *
     * @param \stdClass[] $records The quiz attempts, with their question usage (uniqueid) and quiz.
     * @return array[] Slots keyed by question usage id, in slot order.
     */
    private function slots(array $records): array {
        if (!$records) {
            return [];
        }
        $quizzes = [];
        foreach ($records as $record) {
            $quizzes[$record->uniqueid] = (int) $record->quiz;
        }
        $sections = $this->sections(array_unique($quizzes));

        [$usagesql, $params] = $this->db->get_in_or_equal(array_keys($quizzes), SQL_PARAMS_NAMED, 'usage');
        $sql = "SELECT qa.id, qa.questionusageid, qa.slot, qa.questionid, q.name AS questionname, qa.maxmark,
                       qa.minfraction, qa.maxfraction, qas.state, qas.fraction
                  FROM {question_attempts} qa
                  JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
             LEFT JOIN {question} q ON q.id = qa.questionid
                 WHERE qa.questionusageid $usagesql
                   AND qas.sequencenumber = (
                           SELECT MAX(latest.sequencenumber)
                             FROM {question_attempt_steps} latest
                            WHERE latest.questionattemptid = qa.id
                       )
              ORDER BY qa.questionusageid, qa.slot";

        $slots = [];
        $rs = $this->db->get_recordset_sql($sql, $params);
        foreach ($rs as $slot) {
            $section = $this->section($sections[$quizzes[$slot->questionusageid]] ?? [], (int) $slot->slot);
            $slots[$slot->questionusageid][] = [
                'slot' => (int) $slot->slot,
                'questionid' => (int) $slot->questionid,
                'questionname' => $slot->questionname,
                'sectionfirstslot' => (int) $section->firstslot,
                'sectionheading' => (string) $section->heading,
                'maxmark' => (float) $slot->maxmark,
                'minfraction' => (float) $slot->minfraction,
                'maxfraction' => (float) $slot->maxfraction,
                'fraction' => $slot->fraction === null ? null : (float) $slot->fraction,
                'state' => $slot->state,
            ];
        }
        $rs->close();
        return $slots;
    }

    /**
     * Reads the sections of some quizzes.
     *
     * @param int[] $quizids The quizzes.
     * @return array[] Sections (with firstslot and heading) keyed by quiz id, by first slot, highest first.
     */
    private function sections(array $quizids): array {
        [$quizsql, $params] = $this->db->get_in_or_equal($quizids, SQL_PARAMS_NAMED, 'quiz');
        $sections = [];
        $rs = $this->db->get_recordset_select(
            'quiz_sections',
            "quizid $quizsql",
            $params,
            'quizid, firstslot DESC',
            'id, quizid, firstslot, heading'
        );
        foreach ($rs as $section) {
            $sections[$section->quizid][] = $section;
        }
        $rs->close();
        return $sections;
    }

    /**
     * The section a slot is in: the one with the highest first slot at or before it.
     *
     * Every quiz has a section starting at slot 1, so one is always found; the fallback covers a
     * quiz whose sections are missing.
     *
     * @param \stdClass[] $sections The quiz's sections, by first slot, highest first.
     * @param int $slot The slot.
     * @return \stdClass The section, with firstslot and heading.
     */
    private function section(array $sections, int $slot): \stdClass {
        foreach ($sections as $section) {
            if ((int) $section->firstslot <= $slot) {
                return $section;
            }
        }
        return (object) ['firstslot' => 1, 'heading' => ''];
    }

    /**
     * An attempt's marks rescaled to the quiz's maximum grade, as quiz_rescale_grade does, unrounded.
     *
     * @param float|null $sumgrades The attempt's total mark; null while it is not graded.
     * @param float $maxsumgrades The quiz's total of question marks.
     * @param float $grademax The quiz's maximum grade.
     * @return float|null Null when the attempt is not graded, or the quiz has no marks to scale from.
     */
    private function grade(?float $sumgrades, float $maxsumgrades, float $grademax): ?float {
        if ($sumgrades === null || $maxsumgrades < grade_calculator::ALMOST_ZERO) {
            return null;
        }
        return $sumgrades * $grademax / $maxsumgrades;
    }
}
