# Changes

Releases of `local_arcs`. Breaking changes to the web service contract bump the
contract version and are listed here.

## Unreleased

- Initial plugin skeleton.
- Capability `local/arcs:export` and the Arcs connector external service.
- Web service function `local_arcs_get_site_info` (contract version 1).
- Change log with retention setting and cleanup task, and web service function `local_arcs_get_changes`.
- Web service functions `local_arcs_get_users` and `local_arcs_get_profile_fields`, with
  change log entries for users and custom profile field definitions.
- Setting for the optional user fields to export. Custom profile fields and contact
  details are withheld unless an administrator selects them.
- Web service functions `local_arcs_get_categories`, `local_arcs_get_courses` and
  `local_arcs_get_course_modules`, with `categoryids` and `courseids` filters, and change
  log entries for categories, courses and course modules.
