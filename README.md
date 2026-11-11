# Arcs connector (local_arcs)

A Moodle local plugin that makes a site's learning data available to Arcs
Learning Intelligence Platform through read-only web service functions. Arcs
pulls the data on a schedule and keeps its copy in sync. Moodle makes no
outbound calls and holds no Arcs credentials.

The plugin exposes users and their profile fields, cohorts, course categories,
courses, course modules, enrolments, course and activity completions, grade
items, grades, and the standard log, in Moodle's own terms. A change log tells
Arcs which records changed since its last pull.

**Status:** 1.0.0, stable.

## You need an Arcs account

This plugin only does something when Arcs connects to it. Arcs is a commercial
service from Arcs Learning, and you need an Arcs account to use it. See
<https://arcslearning.com> to find out more or to get started. Without an
account, the plugin installs and runs but sends no data anywhere.

## Requirements

- Moodle 4.5 to 5.3, on any database Moodle supports.
- PHP as required by your Moodle version (8.1 or later).
- Web services and the REST protocol enabled, for Arcs to connect.
- The standard log store enabled, if Arcs is to read activity (optional; everything else works without it).

The plugin has no dependencies and needs no Composer or other tools.

## Installation

Install the plugin into `local/arcs` (Moodle 4.5 to 5.0) or `public/local/arcs`
(Moodle 5.1 and later): either upload the ZIP through Site administration ›
Plugins › Install plugins, or copy the files into place. Then complete the
upgrade from Site administration › Notifications.

Installing changes nothing else on the site: the Arcs connector service is
installed disabled, and no role is given the plugin's capability.

## Setting up access for Arcs

Arcs connects as a dedicated Moodle user with a web service token. Each step
below is on the site administration menu.

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
   Assign system roles: assign the new role to the Arcs user.
7. **Authorise the user on the service.** Site administration › Server › Web
   services › External services: open *Authorised users* for *Arcs connector*
   and add the Arcs user.
8. **Create a token.** Site administration › Server › Web services › Manage
   tokens › Create token: choose the Arcs user and the *Arcs connector*
   service. Set *IP restriction* to the addresses Arcs connects from, and a
   *Valid until* date if your policy calls for one. Copy the token and give it
   to Arcs, with the site's URL; on recent Moodle versions it is shown only
   once.
9. **Check.** Site administration › Reports › System status lists the Arcs
   checks: web services, the REST protocol, the service, a usable token, the
   standard log store and the change log. The plugin's settings page links to
   it.

## Settings

Site administration › Plugins › Local plugins › Arcs connector:

- **Change log retention**: how long the change log keeps entries (default
  30 days). Arcs must read changes more often than this, or it has to read
  everything again.
- **Optional user fields to export**: department, institution, city and
  country are sent by default. Phone numbers, address, the alternative name
  fields and every custom profile field are withheld unless you select them.

## Privacy

The plugin sends personal data to Arcs, an external system, when Arcs calls
it with a token you issued. That data is users' identifiers, names, email
addresses, account status and access times, the optional fields you select,
their cohort memberships, enrolments and roles, course and activity
completions, grades (without feedback), and their entries in the standard log
(without the event details, IP addresses or "log in as" users; anonymous
events are sent without their users). The plugin's privacy metadata lists
every field.

The plugin stores one table of its own, a change log holding record ids and
times only, never field values. It is emptied after the retention period, and
handled by Moodle's privacy API. When a user is deleted, the rows recording
that deletion are kept until the retention period ends, so that Arcs deletes
its copy of the person.

How Arcs handles the data it receives is set out in the Arcs privacy policy:
<https://arcslearning.com/privacy-policy>.

To stop sending data, disable the Arcs connector service or delete its token.

## For developers

The web service contract Arcs relies on (every function, field, cursor rule
and error code) is in [docs/contract.md](docs/contract.md). Changes are listed
in [CHANGES.md](CHANGES.md).

## Support

Report issues at <https://github.com/Arc-Strata/moodle-local_arcs/issues>.

## Licence

GNU GPL v3 or later. See [LICENSE](LICENSE).
