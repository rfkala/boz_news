=== Boz News ===
Contributors: arash
Tags: rss, atom, news, aggregator, ai, moderation, persian, rtl
Requires at least: 5.8
Tested up to: 6.4
Stable tag: 1.7.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fetch, moderate, rewrite, and publish news from RSS/Atom feeds, with a
bilingual Persian/English admin panel.

== Description ==

Boz News reads RSS and Atom sources on a schedule, filters the items, holds
them in a moderation queue, and publishes what you approve as standard posts
or as a dedicated News custom post type.

The admin panel is bilingual. Switch it between Persian and English in
Settings; the whole interface, including error messages, follows that choice
independently of the site language.

Key features:

* RSS/Atom source list with optional category mapping and source keys.
* Sources can be paused individually without losing their settings, and a
  feed that keeps failing pauses itself with a growing backoff.
* Source health panel: last success, last error, and a Test button that reads
  a feed without importing anything.
* Moderation queue with search, status filter, pagination, approve, reject,
  edit, delete, and bulk actions.
* Undo an approval: the published post goes to Trash and the item returns to
  the queue.
* Manual fetch with a per-source progress bar, plus operational logs and queue
  statistics.
* WP-Cron scheduling with a lock shared by scheduled and manual runs, so the
  two can never overlap.
* Duplicate detection by source URL, GUID, and stored post meta.
* Optional full-text extraction and image sideloading, with request size and
  timeout limits.
* AI assistant backed by OpenAI, Groq, Google Gemini or Anthropic Claude,
  with several keys per provider and automatic rotation when one runs out.
* Optional Telegram notification after publishing.
* CSV export of any queue view.
* Shortcode `[news_bulletin]` for the front end.

== Installation ==

1. Upload the `wp-news-collector` directory to `/wp-content/plugins/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Go to Boz News > Settings.
4. Add one RSS/Atom source per line.
5. Go to Boz News > Logs & Tools, press Test on a source to confirm it reads,
   then press Fetch Now.

== Source Formats ==

Use one source per line:

* `https://example.com/feed`
* `https://example.com/feed|5`
* `https://example.com/feed|5|example_source`

The second value is the WordPress category ID. The third value is an optional
source key, used to label log entries and to keep a source's failure history
attached to it when its URL changes.

Prefix a line with `#` to comment it out, or with `!` to keep the source but
pause it:

* `!https://example.com/feed|5|example_source`

A source that fails repeatedly is paused automatically with a growing backoff,
up to a day, and resumes on its own once it responds again. You can also pause,
resume, or clear a source's failure history from the Source Health panel.

== Shortcode ==

`[news_bulletin limit="10" category="world"]`

* `limit` — how many items to show, 1 to 50. Default 10.
* `category` — a category slug. Leave empty for all categories.

A Load More button appears when more items exist.

== Keyword Filters ==

Both lists are comma separated and matched case-insensitively against the
title and description together.

* **Must Include Words** — an item is kept if it contains *any one* of these.
  Leave empty to keep everything.
* **Exclude Words** — any match drops the item, and this wins over the include
  list.

Matching is substring based, so `iran` also matches `iranian`. Markup is
stripped before matching, so a word that only appears inside an HTML attribute
is not matched.

== Frequently Asked Questions ==

= Why did my feed not import anything? =

Open Boz News > Logs & Tools. The Source Health panel shows each feed's last
result, and the Test button reads a feed and reports the real error without
importing. The log below records invalid URLs, RSS errors, duplicate skips,
OpenAI failures, image sideload failures, and publish errors.

= When does the next scheduled fetch run? =

The Settings tab shows the next scheduled run under Update Interval. If your
site defines `DISABLE_WP_CRON`, it says so: scheduled fetches then only happen
when a real cron job calls `wp-cron.php`.

= Are API keys displayed in the admin? =

No. Saved OpenAI and Telegram secrets are never rendered back into the form.
Leave the field blank to keep the saved value, or enter `__delete__` to remove
it.

= What happens to old queue items? =

Approved and rejected rows are permanently deleted after the retention period
set in Settings (14 days by default). Published posts are never touched. Use
the CSV export first if you need a record.

= Does the plugin delete data on uninstall? =

Yes. `uninstall.php` removes plugin options, scheduled events, transients, the
post meta it wrote, the queue table, and the log table.

= What timezone are the stored dates in? =

Everything the plugin stores is UTC. The admin renders it in your site's
timezone, and the CSV export writes the raw UTC value.

== Development ==

Static checks (no PHP required):

`python tools/verify.py`

Unit tests, without installing anything (Windows):

`tools/run-tests.ps1`

It uses a portable PHP ZIP and phpunit.phar; run it with `-Setup` for the two
download links. Where Composer is available, `composer install && vendor/bin/phpunit`
works as well.

Regenerate translation files after changing any `__()` string:

`python tools/make_translations.py`

== Changelog ==

= 1.7.0 =
* Added: choose the AI provider - OpenAI, Groq, Google Gemini or Anthropic
  Claude. Each has its own model setting, and keys for the ones you are not
  using stay saved so switching back needs no re-entry.
* Added: several API keys per provider. They are tried in order; a key that
  reports no credit or a rate limit is set aside for half an hour and the next
  one takes over, so a spent balance no longer stops the assistant.
* Added: unit tests for all four wire formats and for the rotation order.
* Changed: an existing OpenAI key and model are migrated into the new pool on
  upgrade, so nothing has to be re-entered.
* Changed: assistant errors name the provider and repeat its own explanation
  instead of reducing it to a status code.

= 1.6.1 =
* Fixed: publication pacing did not pace. Every item in an approved batch was
  given the same slot, so twenty approvals still produced twenty simultaneous
  posts - the exact problem the feature exists to solve. Caught by its own
  unit test before release.

= 1.6.0 =
* Added: a post template. The body of every published post is assembled from
  a template with placeholders for the article, title, source, date, image and
  tags, instead of the hardcoded "body then source line". Empty placeholders
  leave no debris behind.
* Added: publication pacing. Approving twenty items used to put twenty posts
  on the site in the same second; they can now be spaced by a configurable
  interval, with the first still going out immediately.
* Added: unit tests for both, including the batch-approval case where each
  slot has to be computed from the previous one.

= 1.5.0 =
* Added: the edit screen is a real editor. WordPress's own TinyMCE with
  formatting, links, lists and media, in place of a plain textarea.
* Added: "Load full article" pulls the whole story into the editor on demand,
  for the one item in front of you rather than as a global setting.
* Added: an AI assistant in the editor - rewrite, expand, shorten, translate,
  suggest titles or tags - plus a free-form box where you say what you want
  changed and it applies it. Every result is undoable.
* Changed: full-text extraction keeps the article's structure. Headings,
  lists, quotes, links and images survive, with relative URLs made absolute.
  It previously ran esc_html() over each paragraph and threw all of that away.
* Fixed: admin scripts and styles lost their modification-time versioning
  because the translation generator rewrote that block from a stale template.
  A check now fails the build if it regresses again.
* Fixed: the activity chart wasted most of its height when one day was busy,
  put its value axis on the wrong side in Persian, and showed approved/total
  ratios reversed inside a right-to-left line.

= 1.4.0 =
* Changed: the admin panel has its own design system - colour, spacing, radii
  and elevation tokens built around the gold crown - instead of inheriting
  wp-admin's default look. Segmented tabs, elevated cards, restyled forms and
  tables.
* Added: an approval ring showing what share of collected items reach the site,
  and skeleton loaders in place of a spinner.
* Fixed: panel direction now follows the plugin's own language setting. A
  Persian panel on an English site previously resolved its layout left-to-right.

= 1.3.1 =
* Added: a Dashboard tab with headline cards, a 14-day activity chart and a
  per-source breakdown.
* Fixed: scripts and styles were versioned by the plugin version alone, so a
  changed asset shipped under an unchanged version left browsers on the cached
  copy. They are versioned by file modification time now.

= 1.3.0 =
* Fixed: approving or rejecting the same item twice could publish one story
  twice or leave a published post live while its queue row said "rejected".
* Fixed: the manual per-source fetch took no lock, so it could interleave with
  a scheduled run and import an item twice.
* Fixed: stored datetimes were a mix of local and UTC, so retention deleted
  rows off by the site's GMT offset. All storage is UTC now, and existing rows
  are migrated once on upgrade.
* Fixed: published posts received a UTC timestamp in the local `post_date`
  column, so news appeared shifted by the site offset.
* Fixed: re-publishing an item accumulated duplicate tags.
* Fixed: a failed table creation was silent; it now raises an admin notice.
* Fixed: `wpnc_admin_lang` was left behind on uninstall.
* Added: delete a queue item, and undo an approval.
* Added: per-source Test, Pause/Resume, and failure-history reset.
* Added: automatic backoff for feeds that keep failing.
* Added: CSV export of any queue view.
* Added: log filtering by level.
* Added: configurable retention for queue rows and logs, stated plainly in the
  UI.
* Added: post status (draft/pending/private) and post author settings.
* Added: next scheduled run, and a warning when WP-Cron is disabled.
* Changed: every AJAX failure now names its cause — network, server, session,
  or validation — and offers a retry, instead of one generic message or, in
  four places, no message at all.
* Changed: settings now report rejected and clamped values instead of applying
  them silently.
* Changed: the admin panel is fully bilingual; 38 runtime messages and the
  queue status labels were previously English-only in Persian mode.
* Changed: the admin stylesheet has responsive rules; it previously had none.
* Added: a unit test suite and static checks, run in CI.
* Removed: the Elementor widget. It only wrapped the shortcode with no extra
  controls; use Elementor's own Shortcode widget.
* Removed: the admin email notification. Its daily lock made the count in the
  message wrong.
* Removed: Source Rules XPath. It had no validation, no test, and failed
  silently.

= 1.1.0 =
* Rebuilt fetch pipeline with logging, queue repository, cron lock, and source parsing.
* Added manual fetch, real logs, queue pagination/search, and safer admin rendering.
* Added schema migration fields for feed URL, source key, GUID, post ID, timestamps, and errors.
* Hardened AJAX, frontend shortcode output, image extraction, OpenAI, and Telegram handling.
* Added deactivation cleanup, uninstall cleanup, and privacy policy text.

= 1.0.0 =
* Initial release with RSS fetching, moderation, AI rewrite, Telegram support, and Elementor integration.

== Upgrade Notice ==

= 1.3.0 =
Fixes duplicate publishing, overlapping fetches, and timezone-shifted dates.
Stored timestamps are migrated to UTC once on upgrade. The Elementor widget,
the admin email notification, and Source Rules XPath have been removed.
