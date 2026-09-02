# Boz News — working notes

WordPress plugin. Fetches RSS/Atom, holds items in a moderation queue,
publishes what an admin approves. Bilingual Persian/English admin panel.

## Conventions

- **Code, comments, commit messages, and UI strings are English.** Only the
  Persian half of a `wpnc__()` pair is Persian.
- WordPress coding standards: tabs, `snake_case`, Yoda conditions, spaces
  inside parentheses.
- Every datetime written to a plugin table is **UTC**, via `WPNC_Time`. Never
  call `current_time( 'mysql' )` — that returns site-local time and was the
  cause of retention deleting rows off by the GMT offset.

## Two translation layers, on purpose

- `wpnc__( $en, $fa )` / `wpnc_e()` — the **admin panel**. Follows the plugin's
  own language setting, not the site locale. Use this for anything an admin
  sees, including AJAX response messages.
- `__( $text, 'wp-news-collector' )` — **front end**, cron schedule names, and
  the privacy policy text. Follows the site locale and is backed by real
  `.po/.mo` files in `languages/`.

After changing any `__()` string: `python tools/make_translations.py`.

## Checks

There is no PHP runtime on the primary dev machine, so the static checks carry
real weight:

```
python tools/verify.py     # structure, references, i18n parity, translations
node --check assets/admin.js
tools/run-tests.ps1      # unit tests
```

`run-tests.ps1` needs no installer, no admin rights and no Docker: it finds a
portable PHP (a ZIP extracted to `.php/`) and `phpunit.phar`, writes the
minimal `php.ini` the ZIP omits, and runs the suite. `tools/run-tests.ps1
-Setup` prints the two download links. Composer works too where it is
available, but is not required.

`tools/check_references.py` catches a method renamed in one file and still
called in another — the failure mode `php -l` cannot see.

## Where this actually runs

This repo is the plugin source only - no WordPress, no PHP, no MySQL. See
`tools/local-wp.md` for the three separate environments and how to get each.

- `tools/link-to-wp.ps1` junctions this folder into a local WordPress, so
  there is no upload step while developing.
- `tools/build-zip.py` builds the release archive. Never hand-zip the repo -
  that ships tests/, tools/ and .github/ to the live site.

## Architecture

- `class-fetcher.php` — orchestration, cron, the run lock, source health.
- `class-queue-repository.php` — all queue SQL. Nothing else touches the table.
- `class-ajax.php` — every endpoint. All errors go through `fail()` so the
  response shape is `{ message, code, ... }` without exception.
- `class-filter.php` — keyword rules, deliberately free of WordPress state so
  it is unit testable.
- `assets/admin.js` — all requests go through `request()`, which rejects with
  one normalized error shape. Do not add a bare `$.post`.

## Testing constraint

`tests/bootstrap.php` stubs the handful of WordPress functions the pure classes
use. Anything needing `$wpdb` is out of scope for the unit suite.
