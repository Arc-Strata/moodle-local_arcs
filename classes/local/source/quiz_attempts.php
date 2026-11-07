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
 * quizzes on the site course are left out.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_attempts extends attempts {
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
            'qa.timemodified, qa.sumgrades, q.sumgrades AS maxsumgrades, q.grade AS grademax';
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
        $rows = [];
        foreach ($records as $record) {
            $sumgrades = $record->sumgrades === null ? null : (float) $record->sumgrades;
            $maxsumgrades = (float) $record->maxsumgrades;
            $grademax = (float) $record->grademax;
            $rows[] = [
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
        }
        return $rows;
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
