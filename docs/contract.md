# local_arcs web service contract

This document is the contract between the `local_arcs` Moodle plugin and the
systems that read from it, chiefly Arcs Learning Intelligence Platform. It
describes every function, field, cursor rule and error code. The plugin
promises what is written here; anything not written here is not part of the
contract.

**Contract version: 1** (reported by `local_arcs_get_site_info` as
`contractversion`). The contract is in development until plugin release 1.0.0;
until then it may change without a version bump.

## Access

All functions belong to the pre-built external service **Arcs connector**
(shortname `local_arcs`). A caller needs:

1. Web services and the REST protocol enabled on the site.
2. The `local_arcs` service enabled. It is installed disabled.
3. A user authorised on the service (the service is restricted to listed users).
4. A role for that user, at system level, granting `local/arcs:export` and
   `webservice/rest:use`. No role has `local/arcs:export` by default.
5. A token for that user and service, ideally restricted to the caller's IP
   addresses.

Calls use Moodle's REST server:

```
POST {wwwroot}/webservice/rest/server.php
wstoken={token}&wsfunction={function}&moodlewsrestformat=json&{parameters}
```

Every function is read-only. Each checks its parameters, the system context
and `local/arcs:export` before doing anything. A caller without access is
refused with one of Moodle's own error codes: `invalidtoken` (unknown or
expired token), `accessexception` (the service check failed, including a token
whose user no longer has `local/arcs:export`), or `nopermissions` (the
function's own capability check failed).

## Conventions

These apply to every function unless its section says otherwise.

- **Names** use Moodle's own vocabulary: users, courses, user enrolments,
  course completions, grade items and so on. The plugin does not map Moodle's
  data to any other model.
- **Timestamps** are Moodle's Unix timestamps (integer seconds, UTC), exactly
  as stored. 0 means "not set" where Moodle uses 0 that way. The site's
  default timezone is in site info.
- **Strings** are returned raw, as stored in the database. No filters,
  multi-language processing or HTML formatting are applied. Rendering them is
  the reader's concern.
- **Booleans** are returned as JSON `true` or `false`.
- **Errors** are Moodle exceptions. In the REST JSON format they arrive as an
  object with `exception`, `errorcode` and `message`. The `errorcode` values
  listed in this document are stable; messages are not.

### Paging (state functions)

Functions that return rows of one kind (the "state functions", such as
`local_arcs_get_users`) share one shape:

- Parameters: `after` (integer, default 0), `limit` (integer, default 1000,
  at most 1000), and optionally `ids` (list of integers, default empty).
- Returns an object with the rows, in a list named after the function (`users`,
  `profilefields`, ...), and `next`.
- Without `ids`, rows with `id > after` are returned in ascending `id` order, at
  most `limit` of them. `next` is the last `id` returned, or null when no rows
  are left after this page. Pass `next` as `after` to read the following page.
- With `ids`, exactly those rows that still exist are returned, in ascending
  `id` order, `after` is ignored and `next` is null. Repeated ids count once,
  and at most `limit` ids may be given. This is how a reader follows the change
  log to fetch current state. An empty `ids` list means "no filter".
- A `limit` above the maximum, or more `ids` than the maximum, is refused with
  `limit_exceeded`. A `limit` below 1 or a negative `after` is refused with
  Moodle's `invalidparameter`.
- Some state functions also take one **filter**, a list of ids that narrows the
  rows paged by `after` and `limit`: `courseids` (rows in those courses),
  `categoryids` (rows in those categories or below them), `coursemoduleids`
  (rows for those course modules) or `itemids` (rows for those grade items). A function that takes two filters accepts
  only one of them per call. Paging works the
  same with a filter. A filter and `ids` together are refused with
  `invalidparameter`; more than 1000 filter ids are refused with
  `limit_exceeded`. Filter ids that don't exist match nothing. An empty list
  means "no filter".

## Keeping in sync

1. Call `local_arcs_get_site_info`. Check `contractversion`, and note
   `changeloghighwatermark` as your starting cursor.
2. Read every entity in full with the state functions.
3. From then on, call `local_arcs_get_changes` with your cursor, fetch the
   current state of each named record through the state functions' `ids`
   filter, and store the returned `next` as your cursor. Two kinds of change
   also mean "refetch what is under this record", through the filters:
   - a `course` `upsert` for course C: page every course-scoped function
     (such as `local_arcs_get_course_modules`) with `courseids` = [C];
   - a `course_category` `upsert` for category K: page
     `local_arcs_get_categories` and `local_arcs_get_courses` with
     `categoryids` = [K];
   - a `course_module` `upsert` for module M: page
     `local_arcs_get_activity_completions` with `coursemoduleids` = [M];
   - a `grade_item` `upsert` for grade item G: page `local_arcs_get_grades`
     with `itemids` = [G].
4. If `local_arcs_get_changes` reports `cursor_expired`, go back to step 1.
5. Also repeat step 2 from time to time (a full reconciliation). The change log
   is a hint: some Moodle code paths change data without telling anyone, and
   the state functions are always the truth.

## Versioning

- `contractversion` changes only when something is removed or changes
  meaning: a function, a field, a parameter, an error code, or a rule in this
  document. Such a change is listed in the plugin's `CHANGES.md`.
- Adding a function, an optional parameter or a field does not change it.
  Readers must ignore fields they don't know.
- A reader should refuse to sync from a site whose `contractversion` it does
  not know.

## Functions

### local_arcs_get_site_info

Site-level facts. Call it first on every sync: it says which contract the site
speaks and whether the log stream is available.

Parameters: none.

Returns an object:

| Field | Type | Meaning |
| --- | --- | --- |
| `contractversion` | int | Version of this contract that the site implements. |
| `pluginversion` | int | Installed `local_arcs` version (`YYYYMMDDXX`). |
| `pluginrelease` | string | Installed `local_arcs` release name, such as `0.1.0`. |
| `moodlerelease` | string | Moodle's release name, such as `4.5.15+ (Build: 20261030)`. |
| `moodlebranch` | string | Moodle's branch, such as `405` or `503`. |
| `siteidentifier` | string | Moodle's site identifier. Stable for the life of the site; use it to tell sites apart. |
| `wwwroot` | string | The site's URL. |
| `timezone` | string | The site's default timezone (an IANA name, such as `Europe/London`). |
| `logstoreenabled` | bool | Whether Moodle's standard log store is enabled, so that new events are recorded. |
| `logstorelifetime` | int | Days the standard log store keeps rows before Moodle deletes them; 0 means forever. |
| `changelogoldestid` | int or null | Lowest change id currently kept, or null when the change log is empty. |
| `changelognewestid` | int or null | Highest change id currently kept, or null when the change log is empty. |
| `changeloghighwatermark` | int | Highest change id a reader may read up to now (see `local_arcs_get_changes`). A new reader starts here. |
| `changelogpurgedupto` | int | Every change up to this id has been deleted for age; a cursor below it is expired. 0 when nothing has been deleted. |
| `changelogretentiondays` | int | Days the change log keeps rows. Read changes more often than this. |
| `optionalfields` | list of strings | The optional user fields this site exports (see `local_arcs_get_users`): user field names, such as `department`, and `profile_field_` followed by a custom profile field's shortname. Optional fields not listed are withheld. |

Errors: none beyond the access errors above.

### local_arcs_get_changes

The change log: which records changed, by id, in the order the changes were
recorded. Rows hold ids and times only, never field values; fetch the current
state of each record from its state function.

Parameters:

| Name | Type | Default | Meaning |
| --- | --- | --- | --- |
| `after` | int | 0 | Cursor: return changes with a higher id. |
| `limit` | int | 1000 | Most changes to return; at most 1000. |

There is no `ids` parameter; the change log is read by cursor only.

Returns an object:

| Field | Type | Meaning |
| --- | --- | --- |
| `changes` | list | The changes with `after < id <= highwatermark`, in ascending `id` order, at most `limit`. |
| `next` | int | The cursor to pass as `after` on the next call. Always set. It never passes `highwatermark`. |
| `hasmore` | bool | Whether more changes are ready now. When false, wait before calling again. |
| `highwatermark` | int | The highest change id a reader may read up to now. |

Each change:

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | int | Change id. Ascending, but not contiguous: gaps are normal. |
| `entity` | string | Kind of record; see the table below. |
| `entityid` | int | The record's id in its Moodle table. |
| `op` | string | `upsert` (created or changed: fetch its current state) or `delete`. |
| `userid` | int or null | The user the record belongs to, when it has one. |
| `courseid` | int or null | The course the record belongs to, when it has one. |
| `timecreated` | int | When the change was recorded. |

An `upsert` for a record that no longer exists by the time you fetch it means
it was deleted after the change was recorded.

**The high-water mark.** Change ids are allocated before the change is
committed, so under load a lower id can become visible after a higher one. The
plugin therefore only returns changes up to the highest id recorded at least
60 seconds ago. No change below the mark can still appear, so a reader that
never moves its cursor past the mark never misses one.

**Expiry.** Changes are kept for `changelogretentiondays`, then deleted
oldest first. If `after` is below `changelogpurgedupto`, changes you have not
read are gone, and the call fails with `cursor_expired`: read everything again
(see "Keeping in sync").

Entities:

| `entity` | `entityid` is | Notes |
| --- | --- | --- |
| `user` | `user.id` | |
| `profile_field` | `user_info_field.id` | |
| `cohort` | `cohort.id` | |
| `cohort_member` | `cohort.id` | The member is `userid`. Membership rows are named by the pair (cohort, user). |
| `course_category` | `course_categories.id` | |
| `course` | `course.id` | An `upsert` also means: refetch everything in the course (modules, enrolments, completions, grades). |
| `course_module` | `course_modules.id` | |
| `user_enrolment` | `user_enrolments.id` | |
| `course_completion` | `course_completions.id` | |
| `activity_completion` | `course_modules_completion.id` | |
| `grade_item` | `grade_items.id` | |
| `grade` | `grade_grades.id` | |

Which Moodle events record which changes:

| Moodle event | Change |
| --- | --- |
| `\core\event\user_created`, `user_updated` | `user` `upsert`, `userid` = the user. Saving a user's custom profile field values is followed by `user_updated`. |
| `\core\event\user_deleted` | `user` `delete`, `userid` = the user. |
| `\core\event\user_info_field_created`, `user_info_field_updated` | `profile_field` `upsert`. |
| `\core\event\user_info_field_deleted` | `profile_field` `delete`. |
| `\core\event\course_category_created`, `course_category_updated` | `course_category` `upsert`. Moving, hiding and showing a category are updates. |
| `\core\event\course_category_deleted` | `course_category` `delete`. |
| `\core\event\course_created`, `course_updated` | `course` `upsert`, `courseid` = the course. |
| `\core\event\course_restored`, `course_reset_ended`, `course_content_deleted` | `course` `upsert`. These write the course's modules, enrolments, completions and grades without changes of their own. |
| `\core\event\course_completion_updated` (completion criteria saved) | `course` `upsert`. |
| `\core\event\course_deleted` | `course` `delete`. |
| `\core\event\course_module_created`, `course_module_updated` | `course_module` `upsert`, `courseid` = its course. Renaming, hiding, showing and changing completion settings are updates. |
| `\core\event\course_module_deleted` | `course_module` `delete`. |
| `\core\event\user_enrolment_created`, `user_enrolment_updated` | `user_enrolment` `upsert`, with `userid` and `courseid`. |
| `\core\event\user_enrolment_deleted` | `user_enrolment` `delete`, with `userid` and `courseid`. |
| `\core\event\enrol_instance_updated` | `course` `upsert`: an enrolment method's status decides whether every enrolment through it is in effect. |
| `\core\event\role_assigned`, `role_unassigned`, in a course | `user_enrolment` `upsert` for each of the user's enrolments in that course (they carry the user's roles). Nothing for roles in other contexts. |
| `\core\event\course_completed` | `course_completion` `upsert`, with `userid` and `courseid`. |
| `\core\event\course_module_completion_updated` | `activity_completion` `upsert`, with `userid` and `courseid`. Viewing an activity is recorded this way when viewing counts towards its completion. |
| `\core\event\grade_item_created`, `grade_item_updated` | `grade_item` `upsert`, `courseid` = its course. Renaming, hiding, locking, moving and changing an item's grading settings are updates. |
| `\core\event\grade_item_deleted` | `grade_item` `delete`. |
| `\core\event\user_graded` | `grade` `upsert`, with `userid` and `courseid`. Recorded when a user's final grade is set or changes: grading in an activity, overriding in the gradebook, importing, and recalculation. |
| `\core\event\grade_deleted` | `grade` `delete`, with `userid` and `courseid`. |

Changes to the site course's modules (front page activities), and to their
grade items and grades, are not recorded, since they are not exported.

**Volume.** When the gradebook recalculates a course (after an item's
grading settings change, for example), each grade whose final grade changes
is recorded, so one action in a large course can record many `grade` rows.

Changes are recorded only after the Moodle transaction that made them commits,
so a change that is rolled back is never listed.

Deletes that imply others (Moodle removes or scrubs the dependent rows without
a change of their own):

- A **`user` delete** means the user is deleted: Moodle keeps a scrubbed user
  row with `deleted` true (see `local_arcs_get_users`). Treat the user's
  enrolments, completions, grades and cohort memberships as gone, and erase
  the person downstream.
- A **`profile_field` delete** means every user's value for that field is gone.
- A **`course_category` delete** comes with a delete, or an update for a move,
  of each category and course that was in it.
- A **`course` delete** means everything in the course is gone: its modules,
  user enrolments, course and activity completions, grade items and grades.
- A **`course_module` delete** means its activity completions and grade items
  are gone. Each grade item's own `grade_item` `delete` is recorded too.
- A **`grade_item` delete** means its grades are gone. Moodle records a `grade`
  `delete` for each only in some configurations, so don't rely on them.
- Deleting an enrolment method unenrols each user through it first, so each
  enrolment has its own `user_enrolment` `delete`.

**Upserts that mean "refetch what is under it"** (Moodle changes those rows
without changes of their own; see "Keeping in sync", step 3):

- A **`course` upsert**: the course's modules, enrolments, completions and
  grades.
- A **`course_category` upsert**: the categories below it (hiding, showing and
  moving change their `visible` and `path`) and the courses in it and below it
  (hiding and showing change their `visible`).
- A **`course_module` upsert**: its activity completions. When a teacher
  changes an activity's completion settings after users have completion data,
  Moodle deletes that data and recalculates it, recording only the users who
  end up with a new state.
- A **`grade_item` upsert**: its grades. Hiding, showing, locking and
  unlocking an item also set the `hidden` and locked state of its grades.

**Changes Moodle makes without any event**, which only a full reconciliation
finds:

- Moving a course module to another section changes its `section`, and moving
  it into a hidden section hides it.
- On Moodle 5.3 and later, a course waiting for asynchronous deletion is hidden
  and has `deletioninprogress` set. Its `course` `delete` is recorded when the
  deletion runs.
- Moodle's completion tasks create course completion rows when users enrol and
  update them as users start (`timeenrolled`, `timestarted`, `reaggregate`).
  Only a course's completion (`timecompleted`) is recorded.
- Renaming a role changes the `shortname` in every user enrolment that lists
  it. Key roles by `roleid`.
- The LDAP enrolment method suspends and reactivates enrolments (`status`)
  directly.
- A grade is recorded only when its `finalgrade` changes. Changing only a
  grade's `hidden` or `overridden` flag, its feedback, or its `rawgrade` while
  it is overridden or locked, records nothing.
- While a grade item has `needsupdate` set, Moodle has yet to recalculate its
  grades (on Moodle 5.3 and later, a course reset recalculates in the
  background). The recalculation records the grades it changes when it runs.

Errors: `limit_exceeded`, `invalidparameter`, `cursor_expired`.

### local_arcs_get_users

Users, from Moodle's `user` table, with their custom profile field values.
A state function (see "Paging"): returns `users` and `next`.

Parameters: `after`, `limit`, `ids` (user ids), as in "Paging".

Every user is returned, including the guest user, administrators, suspended
users and deleted users. Passwords, secrets, IP addresses and session data are
never returned.

Each user:

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | int | User id: the stable key. |
| `username` | string | Username. Unique per site (with `mnethostid`, not exported). |
| `idnumber` | string | ID number, often an HR or student number; empty if not set. |
| `email` | string | Email address. |
| `auth` | string | Authentication method, such as `manual`, `ldap` or `oauth2`. |
| `firstname`, `lastname` | string | Names. |
| `timezone` | string | Timezone name, or `99` for the site default. |
| `lang` | string | Preferred language code; may be empty. |
| optional fields | string | See below. Present only when the site exports them. |
| `suspended` | bool | Whether the account is suspended. |
| `deleted` | bool | Whether the user is deleted. |
| `confirmed` | bool | Whether the account is confirmed. |
| `firstaccess`, `lastaccess` | int | First and last access to the site; 0 if never. |
| `lastlogin` | int | The login before the current one; 0 if none. |
| `timecreated`, `timemodified` | int | When the user was created and last changed. |
| `profilefields` | list | The user's values for the exported custom profile fields. |

**Optional fields.** A site administrator chooses which of these are exported
(plugin setting "Optional user fields to export"); `optionalfields` in site info
lists the choice. A field that is not chosen is absent from every user, never
returned empty.

| Field | Exported by default |
| --- | --- |
| `department`, `institution`, `city`, `country` | yes |
| `phone1`, `phone2`, `address`, `middlename`, `alternatename`, `firstnamephonetic`, `lastnamephonetic` | no |
| custom profile fields (`profile_field_<shortname>`) | no |

`country` is a two-letter ISO 3166 code, or empty.

Each `profilefields` entry:

| Field | Type | Meaning |
| --- | --- | --- |
| `fieldid` | int | The profile field's id (see `local_arcs_get_profile_fields`). |
| `shortname` | string | The profile field's shortname when the user was read. |
| `value` | string | The value as Moodle stores it: menu fields hold the chosen option's text, checkboxes `0` or `1`, dates a Unix timestamp. |

A user with no stored value for a field has no entry for it. Exported fields
are chosen by shortname, so a field whose shortname changes stops being
exported until the administrator chooses it again.

**Deleted users.** When Moodle deletes a user it keeps the row with `deleted`
true: the username is replaced (with the old email address and a timestamp),
the email with a hash of the old username, and the ID number is cleared. Other
fields, names included, are left as they were. Profile field values are
deleted. The change log records a `user` `delete`.

Errors: `limit_exceeded`, `invalidparameter`.

### local_arcs_get_profile_fields

Custom profile field definitions, from Moodle's `user_info_field` table, so
that values can be labelled. Every definition is returned, whether or not its
values are exported. A state function (see "Paging"): returns `profilefields`
and `next`.

Parameters: `after`, `limit`, `ids` (profile field ids), as in "Paging".

Each profile field:

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | int | Profile field id. |
| `shortname` | string | Shortname, unique on the site. |
| `name` | string | Name, raw. |
| `datatype` | string | Field type: `text`, `textarea`, `menu`, `checkbox`, `datetime`, `social`, or a third-party type. |
| `categoryid` | int | The profile field category it is shown under. |
| `visible` | int | Who can see it in Moodle: 0 nobody, 1 the user, 2 everyone, 3 the user and their teachers. It does not affect the export. |

Errors: `limit_exceeded`, `invalidparameter`.

### local_arcs_get_categories

Course categories, from Moodle's `course_categories` table. A state function
(see "Paging"): returns `categories` and `next`.

Parameters: `after`, `limit`, `ids` (category ids), as in "Paging", and the
filter `categoryids`: only these categories and every category below them.

Each category:

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | int | Category id. |
| `name` | string | Name, raw. |
| `idnumber` | string or null | ID number; may be null or empty. |
| `parent` | int | Parent category id; 0 for a top-level category. |
| `path` | string | The ids from the top-level category down to this one, such as `/3/12`. |
| `visible` | bool | Whether the category is visible. A hidden category hides everything in it. |
| `timemodified` | int | When the category was last changed. |

Errors: `limit_exceeded`, `invalidparameter`.

### local_arcs_get_courses

Courses, from Moodle's `course` table. The site course (the front page) is
never returned, even by id. A state function (see "Paging"): returns
`courses` and `next`.

Parameters: `after`, `limit`, `ids` (course ids), as in "Paging", and the
filter `categoryids`: only courses in these categories or in any category below
them.

Each course:

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | int | Course id. |
| `category` | int | The course's category id. |
| `shortname`, `fullname` | string | Names, raw. |
| `idnumber` | string | ID number; empty if not set. |
| `format` | string | Course format, such as `topics`, `weeks` or `singleactivity`. |
| `visible` | bool | Whether the course is visible. |
| `startdate` | int | Start date. |
| `enddate` | int | End date; 0 if none. |
| `enablecompletion` | bool | Whether completion tracking is enabled. |
| `timecreated`, `timemodified` | int | When the course was created and last changed. |
| `deletioninprogress` | bool | Whether the course is waiting to be deleted (asynchronous course deletion, Moodle 5.3 and later). Always false before 5.3. |

Errors: `limit_exceeded`, `invalidparameter`.

### local_arcs_get_course_modules

Course modules (activities and resources), from Moodle's `course_modules`
table, with each one's type and name. Modules in the site course are never
returned. A state function (see "Paging"): returns `coursemodules` and `next`.

Parameters: `after`, `limit`, `ids` (course module ids), as in "Paging", and
the filter `courseids`: only modules in these courses.

Each course module:

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | int | Course module id. Activity completions refer to modules by this id; grade items name their module by `modname` and `instance`, and also carry this id as `coursemoduleid`. |
| `course` | int | Course id. |
| `modname` | string | Module type, such as `quiz`, `scorm`, `assign` or `page`. Includes types that are not learning activities, such as `label`, `subsection` (Moodle 4.5+) and `qbank` (question banks, Moodle 5.0+). |
| `instance` | int | The instance's id in the module type's own table. |
| `instancename` | string or null | The instance's name, raw. Null when the module type's plugin is not installed. |
| `section` | int | The id of the course section it is in. |
| `idnumber` | string or null | ID number; may be null or empty. |
| `visible` | bool | Whether the module is visible. |
| `completion` | int | Completion tracking: 0 none, 1 manual, 2 automatic. |
| `completionexpected` | int | Date completion is expected by; 0 if none. |
| `completiongradeitemnumber` | int or null | When completion requires a grade, the item number of the grade item it requires (usually 0); otherwise null. |
| `completionview` | bool | Whether completion requires viewing the module. |
| `added` | int | When the module was added. |
| `deletioninprogress` | bool | Whether the module is waiting to be deleted. |

Errors: `limit_exceeded`, `invalidparameter`.

### local_arcs_get_user_enrolments

User enrolments, from Moodle's `user_enrolments` table, with their enrolment
method and the user's roles in the course. A state function (see "Paging"):
returns `userenrolments` and `next`.

Parameters: `after`, `limit`, `ids` (user enrolment ids), as in "Paging", and
the filter `courseids`.

A user can have several enrolments in one course, one per enrolment method.
An enrolment is in effect when its `status` and `enrolstatus` are both 0 and
the current time is within `timestart` and `timeend` (where set).

Each user enrolment:

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | int | User enrolment id. |
| `enrolid` | int | The enrolment method instance it belongs to. |
| `userid` | int | User id. |
| `courseid` | int | Course id. |
| `enrol` | string | Enrolment method, such as `manual`, `self`, `cohort`, `meta` or `ldap`. |
| `enrolstatus` | int | The enrolment method instance's status: 0 enabled, 1 disabled. |
| `status` | int | The enrolment's status: 0 active, 1 suspended. |
| `timestart`, `timeend` | int | When the enrolment starts and ends; 0 if not limited. |
| `timecreated`, `timemodified` | int | When the enrolment was created and last changed. |
| `roles` | list | The user's roles in the course: `roleid` and `shortname` (such as `student`, `editingteacher`), each listed once, in Moodle's role order. |

**Roles** are those assigned to the user directly in the course, by hand or by
an enrolment method. Roles assigned in a category or site-wide, and roles held
without an enrolment, are not included. Key roles by `roleid`: a role's
`shortname` can be renamed.

Errors: `limit_exceeded`, `invalidparameter`.

### local_arcs_get_course_completions

Course completions, from Moodle's `course_completions` table: one row per user
and course that completion tracking has seen. A state function (see
"Paging"): returns `coursecompletions` and `next`.

Parameters: `after`, `limit`, `ids` (course completion ids), as in "Paging",
and the filter `courseids`.

Each course completion:

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | int | Course completion id. |
| `userid` | int | User id. |
| `course` | int | Course id. |
| `timeenrolled` | int | When completion tracking saw the user enrol; 0 if unknown. |
| `timestarted` | int | When the user started the course; 0 if not started. |
| `timecompleted` | int or null | When the user completed the course; null until they do. |
| `reaggregate` | int | When Moodle is due to recalculate this completion; 0 if not due. |

A row exists only once Moodle's completion tasks or a completion have created
it, so a user enrolled in a course may have none yet. Rows of deleted users
stay.

Errors: `limit_exceeded`, `invalidparameter`.

### local_arcs_get_activity_completions

Activity completions, from Moodle's `course_modules_completion` table, with
whether the user viewed the activity. A state function (see "Paging"):
returns `activitycompletions` and `next`.

Parameters: `after`, `limit`, `ids` (activity completion ids), as in
"Paging", and one of two filters: `courseids` or `coursemoduleids`.

Each activity completion:

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | int | Activity completion id. |
| `coursemoduleid` | int | Course module id (see `local_arcs_get_course_modules`). |
| `userid` | int | User id. |
| `completionstate` | int | 0 incomplete, 1 complete, 2 complete and passed, 3 complete and failed, 4 failed with a grade hidden from the user. |
| `viewed` | bool | Whether the user has viewed the activity. Moodle records views only for activities where viewing counts towards completion. |
| `timemodified` | int | When the completion state last changed. |

A row exists once a user has some completion state for the activity; no row
means no completion data. Rows of deleted users stay.

Errors: `limit_exceeded`, `invalidparameter`.

### local_arcs_get_grade_items

Grade items, from Moodle's `grade_items` table: every column of each course's
gradebook, including the course total. Items in the site course are never
returned. A state function (see "Paging"): returns `gradeitems` and `next`.

Parameters: `after`, `limit`, `ids` (grade item ids), as in "Paging", and the
filter `courseids`.

Each grade item:

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | int | Grade item id. |
| `courseid` | int | Course id. |
| `itemtype` | string | `course` (the course total), `category` (a grade category's total), `mod` (an activity's grade) or `manual` (added in the gradebook). |
| `itemmodule` | string or null | For a `mod` item, the module type, such as `assign`; null otherwise. |
| `iteminstance` | int or null | For a `mod` item, the module instance id; for a `course` or `category` item, the grade category id; null for `manual` items. |
| `itemnumber` | int or null | Which of an activity's grade items this is (usually 0; some activities have several). |
| `coursemoduleid` | int or null | For a `mod` item, its course module id (see `local_arcs_get_course_modules`); null for other items, and when the module no longer exists. |
| `itemname` | string or null | Item name, raw. Null for the course total. |
| `idnumber` | string or null | ID number; may be null or empty. |
| `gradetype` | int | 0 none, 1 value, 2 scale, 3 text (feedback only). |
| `grademin`, `grademax` | float | The lowest and highest grade. For a scale item, 1 and the number of scale items. |
| `gradepass` | float | The grade needed to pass; 0 if none is set. |
| `scaleid` | int or null | For a scale item, the scale id; null otherwise. |
| `scaleitems` | list of strings | For a scale item, the scale's items, raw, lowest first: grade 1 is the first item. Empty otherwise. Moodle does not let a scale's items change once grades use it. |
| `hidden` | int | 0 visible, 1 hidden, or a timestamp until which the item is hidden. |
| `needsupdate` | bool | Whether Moodle has still to recalculate the item's grades. Until it does, its grades may be out of date. |
| `timemodified` | int or null | When the item was last changed. |

Errors: `limit_exceeded`, `invalidparameter`.

### local_arcs_get_grades

Users' grades, from Moodle's `grade_grades` table: one row per user and grade
item that has a grade or a placeholder for one. Grades in the site course are
never returned. Feedback is never returned. A state function (see "Paging"):
returns `grades` and `next`.

Parameters: `after`, `limit`, `ids` (grade ids), as in "Paging", and one of two
filters: `courseids` or `itemids` (grade item ids).

Each grade:

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | int | Grade id. |
| `itemid` | int | Grade item id (see `local_arcs_get_grade_items`). |
| `userid` | int | User id. |
| `finalgrade` | float or null | The grade the gradebook shows, between the item's `grademin` and `grademax`. Null if the user has no grade. For a scale item, the position of the scale item, from 1. |
| `rawgrade` | float or null | The grade the activity sent, on its own range (`rawgrademin` to `rawgrademax`); null if it sent none, as for `manual` items. Use `finalgrade`. |
| `rawgrademin`, `rawgrademax` | float | The range of `rawgrade`. |
| `hidden` | int | 0 visible, 1 hidden, or a timestamp until which the grade is hidden. A grade is also hidden from the user when its item is. |
| `overridden` | int | When the grade was overridden in the gradebook; 0 if not. An overridden grade no longer follows the activity. |
| `timemodified` | int or null | When the user was graded; null if not graded yet. |

Pass or fail is `finalgrade >= gradepass` where the item has a `gradepass`
above 0. Grades of deleted users are deleted by Moodle.

Errors: `limit_exceeded`, `invalidparameter`.
