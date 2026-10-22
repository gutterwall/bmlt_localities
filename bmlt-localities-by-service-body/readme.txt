=== BMLT Localities by Service Body ===
Contributors: gutterwall
Tags: bmlt, narcotics anonymous, meetings, service body, localities
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A table of the towns where each service body has meetings, by state, with links to each Area's website and helpline.

== Description ==

For Regional or Zonal sites: one table listing every chosen service body (usually the Areas) with its helpline, and,
for each state it meets in, the unique localities (towns and cities) of its meetings, read from your BMLT root server.

* Each Area and state is one row, with the localities in a comma-separated list; the Area and phone cell spans its
  state rows. Areas alternate light blue and white, with a thick divider before each new Area.
* The Area name links to the website in its BMLT service body record, and the phone number to its helpline (tel:).
  A website entered without https:// is linked as https; anything that is not a web address stays plain text.
* State codes and names in any letter case ("VA", "Va.", "virginia") are shown as the full state name; other
  provinces are shown as entered. Localities that differ only in letter case or spacing are listed once.
* Unpublished meetings, and meetings without a locality or state (virtual meetings), are left out.
* Parent and child service bodies are listed separately: a sub-Area's meetings are not added to its parent.

This is a list derived from meetings, not an official boundary or coverage-area definition. The public BMLT
semantic interface is used; no credentials are needed.

== Installation ==

1. Plugins → Add New → Upload Plugin, choose the zip, then Activate.
2. Settings → BMLT Localities: enter your BMLT root server URL (HTTPS, for example
   https://example.org/main_server) and save.
3. Tick the service bodies to list and save again.
4. Add `[bmlt_localities]` to a page.

To list other service bodies on one page, use `[bmlt_localities services="12,34"]`; the numbers are the IDs shown
beside the names on the settings page.

== Frequently Asked Questions ==

= How fresh is the list? =

BMLT data is cached for an hour (filter `bmlt_localities_cache_ttl`, in seconds); saving the settings refreshes it
at once. If BMLT cannot be reached, the last list it sent (up to a week old) is shown, and BMLT is asked again five
minutes later. Visitors see "Meeting locality data is temporarily unavailable." only when there is no earlier list;
administrators see the reason.

= My root server is on a private network or an unusual port =

WordPress only fetches from public addresses on ports 80, 443 and 8080. Allow yours with the
`http_request_host_is_external` and `http_allowed_safe_ports` filters.

= What happens on uninstall? =

The settings and cached BMLT data are deleted (on every site of a network).

== Changelog ==

= 1.4.0 =
* Fixed: a PHP warning (undefined variable) on every settings save.
* Fixed: saving the settings while BMLT was unreachable cleared the service body selection.
* Fixed: the same state written differently ("VA", "Va.", "Virginia", "virginia") gave separate rows, and localities
  differing in letter case or spacing were listed more than once.
* Fixed: when BMLT was down, visitors saw nothing at all, and every page view waited for BMLT again.
* New: the last good list is shown while BMLT is unreachable, and failures are retried after five minutes.
* New: one small BMLT request for all service bodies and only the three fields needed (about 13 times less data than
  before), instead of one full request per service body.
* New: saving the settings refreshes the cached data; the settings page points out selected service bodies that are
  no longer on the root server.
* New: websites entered without https:// are linked; the stylesheet is printed once per page.
* Fixed: themes that break table text anywhere on small screens (word-break: break-all) split words such as
  "Virg-inia"; the list now wraps whole words.
* New: uninstall removes the settings and cache; translatable strings.
* Requires WordPress 5.9 or later (the oldest version it is tested on).

= 1.3.0 =
* Area websites and dialable helpline links; alternating Area bands.
