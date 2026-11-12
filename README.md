# Arcs connector (local_arcs)

A Moodle local plugin that gives other systems read-only access to a site's
learning data through Moodle web services, under a published, versioned
contract. It was built for Arcs Learning Intelligence Platform, but any system
that can call Moodle's REST web services can use it: a data warehouse, a
reporting tool, or an integration of your own. A reader pulls the data with a
token you issue. Moodle makes no outbound calls and holds no credentials for
any other system.

The plugin exposes users and their profile fields, cohorts, course categories,
courses, course modules, enrolments, course and activity completions, grade
items, grades, quiz, H5P and SCORM attempts, assignment submissions, and the
standard log, in Moodle's own terms. A change log tells a reader which records
changed since its last pull, so it can keep its copy in sync without reading
everything again.

**Status:** 1.0.1, stable.

## Who can use it

Any system that can call Moodle's REST web services with a token. No account
or subscription with any other service is needed. Every function, field,
cursor rule and error code is documented in
[docs/contract.md](docs/contract.md), which is what a reader is built against.
Arcs is one such reader.

## Requirements

- Moodle 4.5 to 5.3, on any database Moodle supports.
- PHP as required by your Moodle version (8.1 or later).
- Web services and the REST protocol enabled, for a reader to connect.
- The standard log store enabled, if the reader is to read activity (optional;
  everything else works without it).

The plugin has no dependencies and needs no Composer or other tools.

## Installation

Install the plugin into `local/arcs` (Moodle 4.5 to 5.0) or `public/local/arcs`
(Moodle 5.1 and later): either upload the ZIP through Site administration ›
Plugins › Install plugins, or copy the files into place. Then complete the
upgrade from Site administration › Notifications.

Installing changes nothing else on the site: the Arcs connector service is
installed disabled, and no role is given the plugin's capability.

## Setting up access

A reader connects as a dedicated Moodle user with a web service token. Each
step below is on the site administration menu.

1. **Enable web services.** Site administration › Advanced features: tick
   *Enable web services*.
2. **Enable the REST protocol.** Site administration › Server › Web services ›
   Manage protocols: enable *REST protocol*.
3. **Enable the service.** Site administration › Server › Web services ›
   External services: edit *Arcs connector* and tick *Enabled*. It holds only
   this plugin's read-only functions.
4. **Create the user.** Site administration › Users › Accounts › Add a new
   user. Use a dedicated account (for example `arcs`) that nobody logs in as;
   the *Web services authentication* method, enabled under Site administration
   › Plugins › Authentication, prevents interactive logins.
5. **Create a role.** Site administration › Users › Permissions › Define
   roles › Add a new role, with *System* as the only context type in which it
   can be assigned. Allow two capabilities: `local/arcs:export` (Export site
   data to Arcs) and `webservice/rest:use` (Use REST protocol).
6. **Give the user the role.** Site administration › Users › Permissions ›
   Assign system roles: assign the new role to the user.
7. **Authorise the user on the service.** Site administration › Server › Web
   services › External services: open *Authorised users* for *Arcs connector*
   and add the user.
8. **Create a token.** Site administration › Server › Web services › Manage
   tokens › Create token: choose the user and the *Arcs connector* service.
   Set *IP restriction* to the addresses the reader connects from, and a
   *Valid until* date if your policy calls for one. Copy the token and give
   it, with the site's URL, to whoever runs the reader; on recent Moodle
   versions it is shown only once.
9. **Check.** Site administration › Reports › System status lists the
   plugin's checks: web services, the REST protocol, the service, a usable
   token, the standard log store and the change log. The plugin's settings
   page links to it.

## Trying it

With the token from step 8, any HTTP client can call the functions. With
`curl`, replacing the site URL and token:

```bash
SITE=https://moodle.example.com
TOKEN=0123456789abcdef0123456789abcdef

# The site, the plugin's release and the contract version.
curl -s "$SITE/webservice/rest/server.php" -d "wstoken=$TOKEN" \
  -d "wsfunction=local_arcs_get_site_info" -d "moodlewsrestformat=json"

# The first two users, then the page after them (pass "next" as "after").
curl -s "$SITE/webservice/rest/server.php" -d "wstoken=$TOKEN" \
  -d "wsfunction=local_arcs_get_users" -d "moodlewsrestformat=json" -d "limit=2"
```

The other functions, their parameters and the cursor rules are in
[docs/contract.md](docs/contract.md).

## Settings

Site administration › Plugins › Local plugins › Arcs connector:

- **Change log retention**: how long the change log keeps entries (default
  30 days). A reader must read changes more often than this, or it has to read
  everything again.
- **Optional user fields to export**: department, institution, city and
  country are sent by default. Phone numbers, address, the alternative name
  fields and every custom profile field are withheld unless you select them.

## Privacy

The plugin sends personal data to the external system that holds a token you
issued, when that system calls it. That data is users' identifiers, names,
email addresses, account status and access times, the optional fields you
select, their cohort memberships, enrolments and roles, course and activity
completions, grades (without feedback), quiz, H5P and SCORM attempts with
their scores (not the answers), assignment submissions with their status and released grades
(not what was submitted, nor the feedback), and their entries in the standard
log (without the event details, IP addresses or "log in as" users; anonymous
events are sent without their users). The plugin's privacy metadata lists
every field.

The plugin stores one table of its own, a change log holding record ids and
times only, never field values. It is emptied after the retention period, and
handled by Moodle's privacy API. When a user is deleted, the rows recording
that deletion are kept until the retention period ends, so that a reader
deletes its copy of the person.

When the reader is Arcs, how Arcs handles the data it receives is set out in
the Arcs privacy policy: <https://arcslearning.com/privacy-policy>.

To stop sending data, disable the Arcs connector service or delete its token.

## For developers

The web service contract (every function, field, cursor rule and error code)
is in [docs/contract.md](docs/contract.md). Changes are listed in
[CHANGES.md](CHANGES.md).

## Support

Report issues at <https://github.com/Arc-Strata/moodle-local_arcs/issues>.

## Licence

GNU GPL v3 or later. See [LICENSE](LICENSE).
