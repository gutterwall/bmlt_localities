# BMLT Localities by Service Body

A WordPress plugin for Regional and Zonal Narcotics Anonymous websites: one table of every chosen service body with
its helpline and, for each state it meets in, the towns where its meetings are, read from your BMLT root server.

| Area - Phone Number | State | Localities |
|---|---|---|
| [Riverside Area](https://riverside.example.org) - [(555) 010-1001](tel:5550101001) | Maryland | Eastbank |
| | Virginia | Château Ville, Millbrook, Riverton, Riverton 2, Riverton 10 |
| [Hillcrest Area](https://hillcrest.example.org) - [555-0102](tel:5550102) | Ohio | Hillcrest, Ridge |

| | |
|---|---|
| Plugin | [`bmlt-localities-by-service-body/`](bmlt-localities-by-service-body) — usage in its [readme](bmlt-localities-by-service-body/readme.txt) |
| Release file | `bmlt-localities-by-service-body-<version>.zip` from [Releases](../../releases) (Plugins → Add New → Upload Plugin) |
| Requires | WordPress 5.9+, PHP 7.4+, a BMLT root server on HTTPS |
| License | GPL-2.0-or-later ([LICENSE](LICENSE)) |
| Changes | [CHANGELOG.md](CHANGELOG.md) |

Setup: Settings → BMLT Localities, enter the root server URL and save, tick the service bodies and save again, then
put `[bmlt_localities]` on a page (`[bmlt_localities services="12,34"]` for other bodies on one page).

Each release is built by CI from the tagged commit, and the zip carries a GitHub build provenance attestation:

```sh
gh attestation verify bmlt-localities-by-service-body-1.4.0.zip --repo gutterwall/bmlt_localities
sha256sum -c bmlt-localities-by-service-body-1.4.0.zip.sha256
```

Inside the zip, `build.json` names the commit it was built from and `checksums.sha256` lists every file.

## Fake BMLT root server

[`fake-bmlt/`](fake-bmlt) answers the BMLT `client_interface/json/` requests the plugin makes (`GetServiceBodies`,
`GetSearchResults` with `services[]`, `recursive` and `data_field_key`, `GetServerInfo`) the way a real root server
does (every value a string, unpublished meetings left out), over a fictional Region whose data is as untidy as real
data: one state written five ways, a town with odd spacing and letter case, virtual meetings, a sub-Area, an empty
Area, a website without `https://`, a `javascript:` website, a helpline with words in it, and markup in names. It is
used in-process by the PHPUnit tests and over HTTP for the end-to-end test or a local site:

```sh
php -S 127.0.0.1:8090 fake-bmlt/router.php
# /down/main_server answers HTTP 503, /html/main_server an HTML page (a wrong URL);
# FAKE_BMLT_FAULT_FILE=<file> containing "down" or "html" does the same for every path.
```

The plugin only accepts `https://` root servers, so put a TLS proxy in front of it (bin/e2e.sh shows nginx with a
test certificate). WordPress also refuses private addresses and unusual ports: allow the local one with the
`http_request_host_is_external` and `http_allowed_safe_ports` filters.

## Tests

| Command | What it covers | Needs |
|---|---|---|
| `vendor/bin/phpunit -c tests/wordpress/phpunit.xml.dist` | 51 WordPress integration tests: the list (grouping, state and locality spellings, sorting, bands and rowspans, sub-Areas, virtual and unpublished meetings, links, escaping of everything BMLT sends), the requests made and the cache, BMLT outages and wrong answers (last good list, retry delay, what visitors and administrators see), the settings page and its save handler (permissions, nonce, URL checks, keeping the selection during an outage), uninstall on single sites and networks | `WP_TESTS_DIR` (`bin/install-wp-tests.sh`), `composer install`; `WP_MULTISITE=1` for a network |
| `bin/e2e.sh <dir>` | the release zip on Apache and on nginx with the fake BMLT server behind HTTPS: the administrator sets the plugin up in Chrome, visitors view the list on both servers (rows, links, styles, no console errors), BMLT goes down (last list still shown, BMLT not asked on every view, the selection survives a save), then uninstall leaves nothing | Apache, nginx, PHP-FPM, WP-CLI, Chrome, MySQL (see the script's header) |
| `vendor/bin/phpcs`, `vendor/bin/phpstan analyse` | WordPress security rules (escaping, nonces, input, SQL, i18n) and PHP 7.4+ compatibility; static analysis at level 6 | `composer install` |

`BMLT_LOC_PLUGIN_FILE=<path to another copy of the plugin>` runs the integration tests against that copy (for
example 1.3.0, to see what the tests catch), and `PLUGIN_ZIP=<zip>` does the same for the end-to-end test.

CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs all of them on every push: WordPress 5.9 on PHP 7.4,
the latest WordPress on PHP 8.1 to 8.5, single site and multisite, MySQL 5.7 and 8.0, MariaDB 10.6, 11.4 and 11.8.

## Releasing

`bin/build.sh` checks that every version string agrees and that both changelogs have an entry, then builds the zip
from the committed files (byte-for-byte reproducible). A release is tagged `v<version>`; CI builds, tests and attests
the zip, and that zip is published.
