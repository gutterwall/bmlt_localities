# Changelog

## 1.4.0 (2026-10-22)

Fixed
- A PHP warning ("Undefined variable $path") on every settings save; with errors displayed it could also break the
  redirect after saving.
- Saving the settings while BMLT was unreachable cleared the service body selection (the form had no checkboxes, so
  an empty selection was saved).
- The same state written differently ("VA", "Va.", "Virginia", "virginia") gave separate rows; localities differing
  only in letter case or spacing were listed more than once.
- On themes that set `word-break: break-all` on tables for small screens (Nisarg, for one), names and states were
  split mid-word ("Virg/inia"); the list now wraps whole words.
- When BMLT was down, visitors saw an empty space, and every page view waited up to 20 seconds per service body.

Changed
- One `GetSearchResults` request for all service bodies with `services[]`, asking only for `service_body_bigint`,
  `location_municipality` and `location_province` (`data_field_key`), instead of one full request per body: on a real
  Region's root server, 12 KB instead of 158 KB for four Areas.
- The last good BMLT answer (up to a week old) is shown while BMLT is unreachable; failures are remembered for five
  minutes. Visitors see a short notice only when there is no earlier answer.
- Saving the settings refreshes the cache. The cache lifetime can be changed with `bmlt_localities_cache_ttl`.
- The settings page lists selected service bodies that are no longer on the root server, and says the selection is
  kept when the list cannot be loaded.
- Websites entered without a scheme ("example.org") are linked as https; the stylesheet is enqueued once instead of
  printed with every list.
- Uninstall deletes the settings and cached data on every site; strings are translatable.
- Requires WordPress 5.9 (the oldest version the tests run on).

## 1.3.0 (2026-09-28)

- Area names link to the service body website; dialable helplines link with tel:; alternating Area bands.
