# Changes

Releases of `local_arcs`. Breaking changes to the web service contract bump the
contract version and are listed here.

## 1.0.0 (2026-11-06)

First release, beta. Contract version 1 (see `docs/contract.md`). Supports Moodle 4.5 to 5.3.

- Capability `local/arcs:export` and the Arcs connector external service.
- Web service function `local_arcs_get_site_info`.
- Change log with retention setting and cleanup task, and web service function `local_arcs_get_changes`.
- Web service functions `local_arcs_get_users` and `local_arcs_get_profile_fields`, with
  change log entries for users and custom profile field definitions.
- Setting for the optional user fields to export. Custom profile fields and contact
  details are withheld unless an administrator selects them.
- Web service functions `local_arcs_get_categories`, `local_arcs_get_courses` and
  `local_arcs_get_course_modules`, with `categoryids` and `courseids` filters, and change
  log entries for categories, courses and course modules.
- Web service functions `local_arcs_get_user_enrolments` (with roles),
  `local_arcs_get_course_completions` and `local_arcs_get_activity_completions` (with a
  `coursemoduleids` filter), and change log entries for enrolments, role changes and
  completions.
- Web service functions `local_arcs_get_grade_items` (with course modules and scale
  items) and `local_arcs_get_grades` (with an `itemids` filter), and change log entries
  for grade items and grades.
- Web service functions `local_arcs_get_cohorts` (with each cohort's category) and
  `local_arcs_get_cohort_members` (with combinable `cohortids` and `userids` filters),
  and change log entries for cohorts and memberships.
- Web service function `local_arcs_get_log_events`, which reads the standard log store by
  cursor, withholding the users of anonymous events. Site info reports the log's lowest
  and highest event ids.
- Checks on the system status report saying whether Arcs can connect (web services,
  the REST protocol, the Arcs connector service and a usable token) and read log events,
  with the change log's size, and a link to them from the settings page.
