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
 * Reads H5P activity attempts (the h5pactivity_attempts table).
 *
 * Only the attempt's own row is read, never its results (the user's responses). Attempts at
 * H5P activities on the site course are left out.
 *
 * @package    local_arcs
 * @copyright  2026 David Ells
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5pactivity_attempts extends attempts {
    /**
     * The activity module's name.
     *
     * @return string
     */
    public function modname(): string {
        return 'h5pactivity';
    }

    /**
     * The columns to select.
     *
     * @return string
     */
    protected function fields(): string {
        return 'ha.id, ha.userid, cm.id AS coursemoduleid, ha.attempt, ha.rawscore, ha.maxscore, ha.scaled, ' .
            'ha.duration, ha.completion, ha.success, ha.timecreated, ha.timemodified';
    }

    /**
     * The tables to read from.
     *
     * @return string
     */
    protected function from(): string {
        return '{h5pactivity_attempts} ha
                 JOIN {course_modules} cm ON cm.instance = ha.h5pactivityid
                 JOIN {modules} m ON m.id = cm.module';
    }

    /**
     * The id column.
     *
     * @return string
     */
    protected function id_column(): string {
        return 'ha.id';
    }

    /**
     * Shapes a page of H5P activity attempts.
     *
     * @param \stdClass[] $records The H5P activity attempts.
     * @return array[]
     */
    protected function shape(array $records): array {
        $rows = [];
        foreach ($records as $record) {
            $success = self::nullable_bool($record->success);
            $rows[] = [
                'id' => (int) $record->id,
                'userid' => (int) $record->userid,
                'coursemoduleid' => (int) $record->coursemoduleid,
                'attempt' => (int) $record->attempt,
                'rawscore' => $record->rawscore === null ? null : (int) $record->rawscore,
                'maxscore' => $record->maxscore === null ? null : (int) $record->maxscore,
                'scaled' => (float) $record->scaled,
                'duration' => $record->duration === null ? null : (int) $record->duration,
                'completion' => self::nullable_bool($record->completion),
                'success' => $success,
                'timecreated' => (int) $record->timecreated,
                'timemodified' => (int) $record->timemodified,
                'passed' => $success,
            ];
        }
        return $rows;
    }

    /**
     * An xAPI result flag as stored (1, 0 or null when the content did not report it).
     *
     * @param string|int|null $value The stored value.
     * @return bool|null
     */
    private static function nullable_bool($value): ?bool {
        return $value === null ? null : (bool) $value;
    }
}
