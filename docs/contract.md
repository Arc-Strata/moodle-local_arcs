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
  rows paged by `after` and `limit`: `courseids` (rows in those courses) or
  `categoryids` (rows in those categories or below them). Paging works the
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
     `categoryids` = [K].
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

Changes to the site course's modules (front page activities) are not
recorded, since they are not exported.

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
  are gone.

**Upserts that mean "refetch what is under it"** (Moodle changes those rows
without changes of their own; see "Keeping in sync", step 3):

- A **`course` upsert**: the course's modules, enrolments, completions and
  grades.
- A **`course_category` upsert**: the categories below it (hiding, showing and
  moving change their `visible` and `path`) and the courses in it and below it
  (hiding and showing change their `visible`).

**Changes Moodle makes without any event**, which only a full reconciliation
finds:

- Moving a course module to another section changes its `section`, and moving
  it into a hidden section hides it.
- On Moodle 5.3 and later, a course waiting for asynchronous deletion is hidden
  and has `deletioninprogress` set. Its `course` `delete` is recorded when the
  deletion runs.

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
| `id` | int | Course module id. Other records (completions, grade items) refer to modules by this id. |
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
