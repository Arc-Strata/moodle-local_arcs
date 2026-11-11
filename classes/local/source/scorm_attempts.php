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
 * Reads SCORM attempts (the scorm_attempt table), each with its SCOs' status and score.
 *
 * A SCORM package reports what happened in each SCO as tracking elements (scorm_scoes_value,
 * named through scorm_element). Only the status, score and start time elements are read, for
 * SCORM 1.2 and 2004, in one query per page; nothing else the package stored (responses,
 * interactions, suspend data) is read. Attempts at SCORM packages on the site course are left out.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorm_attempts extends attempts {
    /** @var string[] The tracking elements read, and the SCO field each fills. */
    private const ELEMENTS = [
        'cmi.core.lesson_status' => 'status',
        'cmi.completion_status' => 'status',
        'cmi.success_status' => 'successstatus',
        'cmi.core.score.raw' => 'scoreraw',
        'cmi.score.raw' => 'scoreraw',
        'cmi.core.score.min' => 'scoremin',
        'cmi.score.min' => 'scoremin',
        'cmi.core.score.max' => 'scoremax',
        'cmi.score.max' => 'scoremax',
        'x.start.time' => 'starttime',
    ];

    /**
     * The activity module's name.
     *
     * @return string
     */
    public function modname(): string {
        return 'scorm';
    }

    /**
     * The columns to select.
     *
     * @return string
     */
    protected function fields(): string {
        return 'sa.id, sa.userid, cm.id AS coursemoduleid, sa.attempt';
    }

    /**
     * The tables to read from.
     *
     * @return string
     */
    protected function from(): string {
        return '{scorm_attempt} sa
                 JOIN {course_modules} cm ON cm.instance = sa.scormid
                 JOIN {modules} m ON m.id = cm.module';
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 'sa.id';
    }

    /**
     * Shapes a page of SCORM attempts, adding their SCOs.
     *
     * @param \stdClass[] $records The SCORM attempts.
     * @return array[]
     */
    protected function shape(array $records): array {
        $scos = $this->scos(array_map(fn(\stdClass $record): int => (int) $record->id, $records));

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => (int) $record->id,
                'userid' => (int) $record->userid,
                'coursemoduleid' => (int) $record->coursemoduleid,
                'attempt' => (int) $record->attempt,
                'passed' => null,
                'scos' => array_values($scos[$record->id] ?? []),
            ];
        }
        return $rows;
    }

    /**
     * Reads the SCOs tracked in a page of attempts, from their status, score and start time elements.
     *
     * @param int[] $attemptids The attempts.
     * @return array[] SCOs keyed by attempt id, then by SCO id, in SCO id order.
     */
    private function scos(array $attemptids): array {
        if (!$attemptids) {
            return [];
        }
        [$attemptsql, $params] = $this->db->get_in_or_equal($attemptids, SQL_PARAMS_NAMED, 'attempt');
        [$elementsql, $elementparams] = $this->db->get_in_or_equal(
            array_keys(self::ELEMENTS),
            SQL_PARAMS_NAMED,
            'element'
        );
        $sql = "SELECT v.id, v.attemptid, v.scoid, s.identifier, s.title, e.element, v.value, v.timemodified
                  FROM {scorm_scoes_value} v
                  JOIN {scorm_element} e ON e.id = v.elementid
                  JOIN {scorm_scoes} s ON s.id = v.scoid
                 WHERE v.attemptid $attemptsql
                   AND e.element $elementsql
              ORDER BY v.attemptid, v.scoid, v.id";

        $scos = [];
        $rs = $this->db->get_recordset_sql($sql, $params + $elementparams);
        foreach ($rs as $value) {
            $sco = $scos[$value->attemptid][$value->scoid] ?? [
                'scoid' => (int) $value->scoid,
                'identifier' => $value->identifier,
                'title' => $value->title,
                'status' => null,
                'successstatus' => null,
                'scoreraw' => null,
                'scoremin' => null,
                'scoremax' => null,
                'starttime' => null,
                'timemodified' => 0,
            ];
            $field = self::ELEMENTS[$value->element];
            $sco[$field] = match ($field) {
                'status', 'successstatus' => $value->value,
                'starttime' => (int) $value->value,
                default => is_numeric($value->value) ? (float) $value->value : null,
            };
            $sco['timemodified'] = max($sco['timemodified'], (int) $value->timemodified);
            $scos[$value->attemptid][$value->scoid] = $sco;
        }
        $rs->close();
        return $scos;
    }
}
