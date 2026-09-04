=== Boz News ===
Contributors: arash
Tags: rss, atom, news, aggregator, ai, moderation, persian, rtl
Requires at least: 5.8
Tested up to: 6.4
Stable tag: 1.12.0
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
* AI assistant backed by OpenAI, Groq, Google Gemini, Anthropic Claude or
  GapGPT, with several keys per provider and automatic rotation when one runs
  out. Any other service speaking the OpenAI chat-completions format works
  too, and every provider's address can be overridden.
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

= The AI provider answers 403 Forbidden. Are my keys wrong? =

Probably not. A 403 that says only "Forbidden" is usually the provider's edge
network refusing your server's address before the request ever reaches the
API - most often because the provider does not serve the region the server is
in. Every key gets the same answer, so trying more of them proves nothing.

The plugin recognises this case and stops on the first key instead of standing
the whole pool down, and says so in the message.

There are three ways out, in the order worth trying:

1. Use a provider that answers from where your server is. Any service with an
   OpenAI-compatible API works: choose "OpenAI-compatible endpoint" as the
   provider and put its address in Base URL.
2. Put a gateway you control between the plugin and the provider, and set
   Base URL to the gateway.
3. Run a model on the server itself and point Base URL at it. Anything
   exposing an OpenAI-compatible `/chat/completions` will do.

Base URL sits under Settings, in the AI Assistant section, per provider. Only
the address changes - the request format, the key rotation and the editor all
behave the same.

= AI requests hang for 30 seconds and then time out. Is the provider down? =

Check IPv6 before concluding anything about the provider. If the server
publishes an IPv6 route that does not actually work, every request tries that
address first and stalls until the timeout, and the provider looks dead when
it is answering perfectly over IPv4. It is worth ruling out first because it
looks exactly like a block.

Two symptoms tell them apart, from the server:

* `curl -4 https://host/...` succeeds while plain `curl https://host/...`
  hangs - that is the IPv6 route, not the provider.
* The TCP connection succeeds and the TLS handshake never finishes - that is
  the connection being filtered by hostname, and a different address for the
  same service usually works. Set it in Base URL.

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

= 1.12.0 =
* Fixed: Suggest titles and Suggest tags replaced the article with their own
  output. The prompt told the model to return an article body no matter what
  was asked for, and the browser wrote whatever came back into the editor, so
  asking for headlines destroyed the story to make room for a list of them.
  Both now arrive as suggestions under the field they belong to - headlines as
  a radio list under the title, tags as chips under the tag box - and nothing
  is written until Apply is pressed.
* Fixed: Add Media did nothing. The editor was started with its media button
  enabled, but `wp_enqueue_media()` was never called, so the button opened a
  modal that did not exist.
* Added: translating into the language the text is already in is refused
  before the request is sent, and says so. Persian and Arabic are told apart
  by the letters unique to each, so translating an Arabic source into Persian
  still works - which is the case that a script check alone would have broken.
* Added: translating with no target language set says which setting to fill in
  rather than quietly keeping the original language.

= 1.11.0 =
* Added: GapGPT as a provider. It fronts the other providers under its own
  address in the OpenAI format, so it needs no adapter - only an entry, a key
  and a model name.
* Note: it is pointed at `api.gapapi.com`, not the documented
  `api.gapgpt.app`. On the machine this was tested from, the documented host
  accepts a TCP connection and then never completes the TLS handshake, while
  the alternate answers in about a second. Either can be set explicitly in
  Base URL.

= 1.10.0 =
* Fixed: a provider refusing the server itself was reported as every key
  failing. A 403 aimed at where the request came from gets the same answer
  from every key, so the plugin tried all of them, stood the whole pool down
  for half an hour, and blamed the keys - which meant the pool was still
  asleep once the routing was fixed. It now stops on the first key, rests
  none of them, and says what is actually wrong.
* Added: Base URL per provider. Point any provider at a gateway, a reseller,
  or a model running on the server; the wire format, the key rotation and the
  editor are unchanged. A URL that leaves the server must be https, since the
  API key travels with it.
* Added: "OpenAI-compatible endpoint" as a fifth provider, for services that
  speak the OpenAI chat-completions format under their own address.
* Fixed: changing a provider's Base URL wakes its resting keys. The refusal
  that sends you to that field is the same one that put them to rest, so the
  fix would otherwise have looked like it had not worked for half an hour.

= 1.9.0 =
* Changed: the panel header is a real app bar. The title, the section nav and
  each tab's toolbar used to run together with nothing between them, and on a
  wide screen the whole panel was stranded against one edge; every row now
  sits on one measure and the bar stays put while you scroll.
* Added: the header carries how many items are awaiting review, how many
  failed, and when the next fetch is due - three things previously answerable
  only by opening a tab and reading a paragraph.
* Added: the moderation tab count, so the queue announces itself without being
  visited.
* Changed: the settings screen is one form by necessity, but no longer one
  scroll. Its seventeen fields are grouped under named sections - sources,
  filters, publishing, housekeeping, panel - with jump links, and Save follows
  you down the page instead of sitting 900px below the field you changed.
* Changed: the CSV export moved into the page header. It acts on the whole
  view, and in its own strip between the tabs and the filters it exported it
  looked like part of neither.
* Changed: messages appear as toasts in the corner and errors wait to be
  dismissed. Prepending them to the tab meant a message could land above the
  fold and be scrolled past unseen.
* Added: empty states offer the next step rather than only naming it.
* Added: a visible focus ring on every control. The panel replaces wp-admin's
  own styling, which meant it had removed one.

= 1.8.0 =
* Added: a live preview beside the editor, rendered server-side through the
  same template the publisher uses, so it shows the real published result
  rather than an approximation.
* Added: word count and reading time, counted in a way that works for Persian.
* Fixed: dismissing the edit modal discarded unsaved work without asking. An
  AI run can cost money and replace the whole article, and Cancel, the
  backdrop and Escape all threw it away silently. All three now confirm, and
  leaving the page mid-edit warns.
* Added: Ctrl+Enter (Cmd+Enter) saves from anywhere in the modal.

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
