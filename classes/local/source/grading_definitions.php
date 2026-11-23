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
 * Reads the rubrics and marking guides of assignments (the grading_definitions table), with their criteria.
 *
 * Only the definitions in assignments' submissions grading areas are read, of the two methods whose
 * fillings an assignment submission can carry, rubric and marking guide, and of those only the
 * methods whose plugins are installed. Definitions in assignments on the site course are left out.
 * Rubric criterion descriptions and level definitions are returned as plain text. The definition's
 * own description, a marking guide's criterion descriptions and its frequently used comments are
 * never read.
 *
 * @package    local_arcs
 * @copyright  2026 Arcs Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grading_definitions extends source {
    /** @var string[] Each method read, by the table that holds its criteria. */
    private const METHODS = ['gradingform_guide_criteria' => 'guide', 'gradingform_rubric_criteria' => 'rubric'];

    /** @var string[] The methods whose plugins are installed (their tables exist). */
    private array $methods;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int[] $courseids Only read definitions in these courses; empty for all.
     * @param int[] $coursemoduleids Only read definitions of these course modules; empty for all.
     */
    public function __construct(
        \moodle_database $db,
        /** @var int[] Only read definitions in these courses; empty for all. */
        private array $courseids = [],
        /** @var int[] Only read definitions of these course modules; empty for all. */
        private array $coursemoduleids = [],
    ) {
        parent::__construct($db);
        $this->methods = array_values(array_intersect_key(self::METHODS, $db->get_tables()));
    }

    /**
     * The activity module whose definitions are read.
     *
     * @return string
     */
    public function modname(): string {
        return 'assign';
    }

    /**
     * Whether the module is installed. When it has been uninstalled, its grading areas are gone.
     *
     * @return bool
     */
    public function is_installed(): bool {
        return $this->db->record_exists('modules', ['name' => $this->modname()]);
    }

    /**
     * The columns to select.
     *
     * @return string
     */
    protected function fields(): string {
        return 'd.id, cm.id AS coursemoduleid, d.method, ga.activemethod, d.timemodified';
    }

    /**
     * The tables to read from. A grading area belongs to a module context.
     *
     * @return string
     */
    protected function from(): string {
        return '{grading_definitions} d
                 JOIN {grading_areas} ga ON ga.id = d.areaid
                 JOIN {context} ctx ON ctx.id = ga.contextid
                 JOIN {course_modules} cm ON cm.id = ctx.instanceid
                 JOIN {modules} m ON m.id = cm.module';
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 'd.id';
    }

    /**
     * Keeps the installed methods' definitions in assignments' submissions areas outside the site
     * course, narrowed to the requested courses or course modules when the reader asked for some.
     *
     * @return array [string $sql, array $params]
     */
    protected function filter(): array {
        // With neither method installed, the empty method matches nothing.
        [$methodsql, $params] = $this->db->get_in_or_equal($this->methods, SQL_PARAMS_NAMED, 'method', true, '');
        $sql = "ctx.contextlevel = :contextlevel AND ga.component = :component AND ga.areaname = :areaname
                AND d.method $methodsql AND m.name = :modname AND cm.course <> :siteid";
        $params += [
            'contextlevel' => CONTEXT_MODULE,
            'component' => 'mod_assign',
            'areaname' => 'submissions',
            'modname' => $this->modname(),
            'siteid' => SITEID,
        ];
        if ($this->courseids) {
            [$insql, $inparams] = $this->db->get_in_or_equal($this->courseids, SQL_PARAMS_NAMED, 'course');
            $sql .= ' AND cm.course ' . $insql;
            $params += $inparams;
        }
        if ($this->coursemoduleids) {
            [$insql, $inparams] = $this->db->get_in_or_equal($this->coursemoduleids, SQL_PARAMS_NAMED, 'cm');
            $sql .= ' AND cm.id ' . $insql;
            $params += $inparams;
        }
        return [$sql, $params];
    }

    /**
     * Shapes a page of definitions, adding their criteria.
     *
     * @param \stdClass[] $records The definitions.
     * @return array[]
     */
    protected function shape(array $records): array {
        $ids = [];
        foreach ($records as $record) {
            $ids[$record->method][] = (int) $record->id;
        }
        $criteria = [];
        if (!empty($ids['rubric'])) {
            $criteria += $this->rubric_criteria($ids['rubric']);
        }
        if (!empty($ids['guide'])) {
            $criteria += $this->guide_criteria($ids['guide']);
        }

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => (int) $record->id,
                'coursemoduleid' => (int) $record->coursemoduleid,
                'method' => $record->method,
                'active' => $record->activemethod === $record->method,
                'timemodified' => (int) $record->timemodified,
                'criteria' => $criteria[$record->id] ?? [],
            ];
        }
        return $rows;
    }

    /**
     * Reads the criteria of some rubrics, each with its levels.
     *
     * @param int[] $definitionids The rubrics.
     * @return array[] Criteria keyed by definition id, in the rubric's order; levels by score.
     */
    private function rubric_criteria(array $definitionids): array {
        [$insql, $params] = $this->db->get_in_or_equal($definitionids, SQL_PARAMS_NAMED, 'definition');
        $sql = "SELECT l.id AS levelid, c.id, c.definitionid, c.sortorder, c.description, c.descriptionformat,
                       l.score, l.definition, l.definitionformat
                  FROM {gradingform_rubric_criteria} c
             LEFT JOIN {gradingform_rubric_levels} l ON l.criterionid = c.id
                 WHERE c.definitionid $insql
              ORDER BY c.definitionid, c.sortorder, c.id, l.score, l.id";

        $criteria = [];
        $rs = $this->db->get_recordset_sql($sql, $params);
        foreach ($rs as $record) {
            $criteria[$record->definitionid][$record->id] ??= [
                'id' => (int) $record->id,
                'sortorder' => (int) $record->sortorder,
                'description' => $this->plain_text($record->description, (int) $record->descriptionformat),
                'shortname' => null,
                'maxscore' => null,
                'levels' => [],
            ];
            if ($record->levelid !== null) {
                $criteria[$record->definitionid][$record->id]['levels'][] = [
                    'id' => (int) $record->levelid,
                    'score' => (float) $record->score,
                    'definition' => $this->plain_text($record->definition, (int) $record->definitionformat),
                ];
            }
        }
        $rs->close();
        return array_map('array_values', $criteria);
    }

    /**
     * Reads the criteria of some marking guides.
     *
     * @param int[] $definitionids The marking guides.
     * @return array[] Criteria keyed by definition id, in the guide's order.
     */
    private function guide_criteria(array $definitionids): array {
        [$insql, $params] = $this->db->get_in_or_equal($definitionids, SQL_PARAMS_NAMED, 'definition');
        $rs = $this->db->get_recordset_select(
            'gradingform_guide_criteria',
            "definitionid $insql",
            $params,
            'definitionid, sortorder, id',
            'id, definitionid, sortorder, shortname, maxscore'
        );
        $criteria = [];
        foreach ($rs as $record) {
            $criteria[$record->definitionid][] = [
                'id' => (int) $record->id,
                'sortorder' => (int) $record->sortorder,
                'description' => null,
                'shortname' => $record->shortname,
                'maxscore' => (float) $record->maxscore,
                'levels' => [],
            ];
        }
        $rs->close();
        return $criteria;
    }

    /**
     * Converts text in one of Moodle's formats to plain text, as content_to_text does but without
     * wrapping lines, and keeping the line breaks of text in Moodle's own format (which the rubric
     * editor always saves).
     *
     * @param string|null $text The text.
     * @param int $format Its format, FORMAT_*.
     * @return string
     */
    private function plain_text(?string $text, int $format): string {
        $text = (string) $text;
        switch ($format) {
            case FORMAT_PLAIN:
                return trim($text, "\r\n ");
            case FORMAT_HTML:
                break;
            case FORMAT_MARKDOWN:
                $text = markdown_to_html($text);
                break;
            default:
                $text = text_to_html($text, null, false, true);
        }
        return trim(html_to_text($text, 0, false), "\r\n ");
    }
}
