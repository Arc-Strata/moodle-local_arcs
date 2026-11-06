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

Functions that return rows of one kind (the "state functions", added in later
releases) share one shape:

- Parameters: `after` (integer, default 0), `limit` (integer, default and
  maximum set by the plugin and stated in each function's section), and
  optionally `ids` (list of integers).
- Without `ids`, rows with `id > after` are returned in ascending `id` order, at
  most `limit` of them. `next` is the last `id` returned, or null when there are
  no more rows. Pass `next` as `after` to read the following page.
- With `ids`, exactly those rows that still exist are returned, in ascending
  `id` order, and `after` is ignored. At most `limit` ids may be given. This is
  how a reader follows the change log to fetch current state.
- A `limit` above the maximum, or more `ids` than the maximum, is refused with
  `limit_exceeded`.

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

Errors: none beyond the access errors above.
