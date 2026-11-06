# Arcs connector (local_arcs)

A Moodle local plugin that makes a site's learning data available to Arcs
Learning Intelligence Platform through read-only web service functions. Arcs pulls the data on a schedule and
keeps its copy in sync. Moodle makes no outbound calls.

The plugin exposes users and their profile fields, cohorts, course
categories, courses, course modules, enrolments, course and activity
completions, grade items, grades, and the standard log, in Moodle's own terms.
A change log tells Arcs which records changed since its last pull.

**Status:** in development (alpha). Not yet ready for production sites.

## Requirements

- Moodle 4.5 to 5.3.
- PHP as required by your Moodle version (8.1 or later).
- Web services and the REST protocol enabled, for Arcs to connect.

## Installation

Install the plugin into `local/arcs` (Moodle 4.5 to 5.0) or
`public/local/arcs` (Moodle 5.1 and later), either from the ZIP through
Site administration › Plugins › Install plugins, or by copying the files, then
complete the upgrade from Site administration › Notifications.

Setup for Arcs (service, user, role and token) will be documented here before
the first release.

## Licence

GNU GPL v3 or later. See [LICENSE](LICENSE).
