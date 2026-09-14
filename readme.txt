=== Boz News ===
Contributors: arash
Tags: rss, atom, news, aggregator, ai, moderation, persian, rtl
Requires at least: 5.8
Tested up to: 6.4
Stable tag: 1.23.0
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
* Send an approved item to the site, to Telegram, to Bale, or to all of
  them, with each destination unlocked by a passing connection test.
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

= The assistant says this server is cutting requests short. Is that true? =

Open Logs & Tools and press Connection check. It asks three addresses for an
answer, gives each thirty seconds, and reports how long each actually took:
the AI endpoint you have configured, WordPress.org, and your own site.

* More than one address cut off well before thirty seconds - a limit on this
  server, not the provider. Ask the host what caps outbound HTTP requests; a
  security plugin or a proxy can do it too.
* Only the AI endpoint failing, while WordPress.org answered - that address
  is unreachable from here. Set a Base URL that answers, or pick another
  provider.
* Everything answered - outbound requests are fine, and an action that still
  times out is one whose answer genuinely takes that long. The actions that
  rebuild the whole article are the slowest; Suggest titles is the fastest.

The check writes its result to the log, so it can be compared with a later
run.

Next to it, Check addresses asks every AI address the plugin knows - and any
address you type - whether it answers from this server, and lists the ones
that do. Put one of those in Base URL under Settings, with a key that works
there.

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

= Why does a post sometimes publish without its featured image? =

Open Logs & Tools and look for "No featured image was found for this item" or
"Image sideload failed" - each records the reason. The usual ones:

* The article page did not respond in time, so the picture it declares was
  never read. The feed's own media and the item's HTML are now checked first,
  and neither needs the page at all.
* The image server refused the download (HTTP 403). News CDNs often block
  requests that do not look like a browser or carry no Referer; downloads now
  send both. A site that prefers to identify itself can change the agent with
  the `wpnc_http_user_agent` filter.
* The address had no file extension, or pointed to an AVIF. Both are accepted
  now: the type is read from the downloaded bytes, not guessed from the URL.

Each item's featured image can also be checked and changed in the editor
before sending, under Featured image.

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

= AI actions time out, but only the ones that rewrite the whole article =

Generation sends nothing back until the whole answer is ready, so the time a
request takes is set by how much text comes out. Add structure, Rewrite and
Expand rebuild the entire article; Suggest titles returns a few lines. That is
why the heavy ones can time out on a server where the light ones are fine.

The plugin now reads the duration out of the transport error and says which of
two situations it is:

* Cut off well before the time it asked for - something on the server is
  capping outbound requests (a host limit, a proxy, a security plugin).
  Raising the plugin's own timeout will not help until that is lifted; ask the
  host what the outbound cap is.
* Ran the full time - the answer really did take that long. Work on a shorter
  article, or allow more time:

`add_filter( 'wpnc_ai_timeout', function() { return 120; } );`

A timeout no longer retries the remaining keys. Every key would wait exactly
as long, so trying them only multiplied the delay and then blamed the keys.

== Changelog ==

= 1.23.0 =
* Added: Telegram and Bale posts carry the featured image with a caption, laid
  out by a template per channel using {title} {summary} {link} {hashtags} and
  {source}. If the service cannot fetch the picture, the same words go out as
  a text message. Captions are cut to fit - the summary first - and never lose
  the link.
* Added: tags become hashtags that can be tapped. A two-word Persian tag, the
  zero-width non-joiner inside a word and the Persian comma all used to break
  them.
* Added: Write caption, in the editor's assistant. It fills a caption field
  under the tags for you to read and change; nothing is sent unread.
* Added: alerts to your own chat when a source stops responding or comes back,
  and when every AI key has run out. The same alert repeats at most every six
  hours, and a chat your readers follow is refused as the destination.

= 1.22.0 =
* Changed: the preview appears as you type. Typing in the article body never
  asked for a preview at all, so the pane kept showing the text as it was when
  the editor opened; and every refresh waited for a round trip through
  admin-ajax behind a 700ms delay. It is now drawn in the browser from the
  same template and allowlist the server uses, then confirmed by the server
  once typing pauses.
* Added: keyboard moderation. J and K move between items; E edits, A approves
  to the site, R rejects, X selects, / searches and ? lists them. A sends to
  the site only - a post can be undone, a message in Telegram or Bale cannot.
* Added: a publication time per item, under Advanced in the editor. Leave it
  empty to publish on approval.
* Fixed: Telegram and Bale were sent links to posts that were not live yet. A
  post scheduled for later - by pacing, or now by a chosen time - answers "not
  found" until then. Its messages are now held and sent when it publishes.


= 1.21.0 =
* Added: Check addresses, under Logs & Tools. It asks every AI address the
  plugin knows - plus one you type - whether it answers from this server, and
  names the ones that do. "Choose another provider" is not advice anyone can
  act on without that list.
* Fixed: a single timed-out request was reported as proof that the server caps
  outbound requests. It is equally consistent with an address that does not
  answer from there, which is what it turned out to be on the install that
  prompted the message. It now names both possibilities and points at
  Connection check, which can tell them apart.


= 1.20.0 =
* Added: Connection check, under Logs & Tools. The plugin could already tell
  you that something was cutting its requests short, but only ever on the
  evidence of one failed request. This runs the experiment - three addresses,
  thirty seconds each, timed - and reports what happened, so a limit on the
  server can be told apart from an endpoint that is unreachable and from an
  answer that is simply slow.


= 1.19.0 =
* Fixed: two long addresses from the same section could collide and the
  second story was lost. Uniqueness rested on the first 191 characters of the
  address, which is all an index can cover of a column that size - and one
  Persian letter costs six of those once encoded. It now rests on a key
  derived from the whole address. Existing rows are migrated in the
  background; no queue row is deleted to make room for the new index.
* Changed: the article page is downloaded once per item instead of twice. The
  picture and the text were fetched separately, from the same URL.
* Changed: a feed that publishes the whole article in content:encoded is now
  read properly. Only the teaser was taken before, which is why the plugin
  went to the article page to recover text the feed had already sent - and
  with full-text extraction on, that page is no longer fetched at all when
  the feed body is already a full article.
* Fixed: the "Load more news" button stopped working on cached sites. A public
  read-only endpoint required a nonce, which expires with the cached page and
  took the button down for every visitor. There is no action to forge here, so
  the nonce is gone.
* Changed: unattended rewriting treats the feed as data rather than as
  instructions. The article now travels in its own fenced message with the
  instructions in a system turn, and the result may not contain links - the
  model is handed text with every link already stripped, so any link in the
  answer is invented, and nobody reads that answer before it is published.
* Fixed: auto-published rewrites arrived as one unbroken block of text.


= 1.18.0 =
* Fixed: new stories arrived at most twice a day whatever the update interval
  was set to. WordPress stores every fetched feed for twelve hours by default
  and the plugin never said otherwise, so a fifteen-minute schedule re-read
  the same stored copy all day - and so did Fetch Now. Feeds are now reused
  for at most half the update interval, and never at all for Fetch Now or for
  Test.
* Fixed: approving could publish the same story twice. The queue row was only
  marked after the post had been created and both messengers had been given
  up to half a minute each, so a request killed in between left the post live
  and the row still pending, and the next click published it again. The row is
  now claimed before the work starts and marked as soon as the post exists.
* Fixed: two runs could hold the fetch lock at once. Taking it read the lock
  and then wrote it, which is not one step; it is now a single atomic write,
  so a manual fetch can no longer run beside a scheduled one.
* Fixed: sources late in the list could go unfetched forever. Every run walked
  them from the top, so on a host that cut the request short the same early
  feeds were fetched every time. A run now stops cleanly inside the server's
  time limit, says how far it got, and the next one starts where it left off.
* Fixed: rejected stories came back. Retention deletes processed rows, and
  that deleted the only record the story had ever been seen, so a feed still
  carrying it delivered it again as new - and with auto-publish on, straight
  to the site. Imported links are now remembered for six months in their own
  table, independently of the queue row.
* Changed: two addresses that differ only by https, a www prefix, a trailing
  slash or a campaign parameter are recognised as one article.
* Changed: bulk approve stops inside the server's time limit and reports how
  many were not reached, rather than being killed partway through.
* Fixed: uninstall left three settings and one post meta key behind.


= 1.17.0 =
* Fixed: the featured image attached for some sources and never for others.
  WordPress's media_sideload_image() refused any address without a
  .jpg/.png/.gif/.webp extension, sent no Referer and WordPress's own user
  agent - which many news CDNs block - and reported all of it as the same
  "Invalid image URL". Downloads now go through the plugin, send a browser
  agent and the article as Referer, and take the type from the bytes.
* Fixed: detection read only the first enclosure, and only when its type said
  "image/"; a relative or protocol-relative og:image was rejected as unsafe;
  and a lazy-loaded article image was read as its placeholder. The feed's
  media, the item's own HTML and the page's declared image are now all tried,
  in that order, with every address resolved against the article.
* Fixed: a post was inserted as published and only then given its featured
  image, so page caches, sitemaps and sharing plugins saw it without one. It
  is now built as a draft, given its image, and published last.
* Fixed: the default image from Settings was downloaded again for every post
  without a picture, adding a copy to the media library each time. An image
  already in the library is now used as it is.
* Added: the featured image is its own field in the editor - thumbnail,
  address, choose from the media library, find in the source, remove - and
  the preview shows it apart from the text.
* Changed: the featured image is no longer repeated inside the article body.
  When the lead picture of an article is also its featured image, it is
  removed from the text at publish time, together with its caption.
* Added: a log entry, with the reason, when a fetched item has no featured
  image, so "the page did not respond" can be told from "the page has none".
* Fixed: "Load full article" reported about 0 words for Persian text.

= 1.16.0 =
* Fixed: an edit that failed to save was reported as saved. The queue update
  returned a bare false that the endpoint ignored, so the panel said "saved",
  closed the editor, and the work was gone. The failure is now surfaced, and
  the reason is written to the log.
* Fixed: the schema check verified that the tables existed but never that
  their columns did. dbDelta adds a column as quietly as it creates a table,
  so a column it failed to add left the schema looking healthy while every
  write touching it was rejected - which is how the save above could fail in
  the first place. Columns are now verified, and one that is missing is added
  directly rather than by asking dbDelta again.
* Added: "Save and send" in the editor, next to Save. It saves first and only
  sends if the save was confirmed, so the version that goes out is the one on
  screen. With more than one destination ready, a selector beside it chooses
  which.



= 1.15.0 =
* Fixed: a timed-out AI request was reported as every key failing, and tried
  each key first - so a 12 second timeout became a 36 second wait ending in a
  message about the wrong thing. It now stops on the first timeout and says
  what actually happened.
* Added: the timeout message distinguishes "the answer took too long" from
  "this server cut the request short", by comparing the duration in the
  transport error against the timeout that was requested. The two need
  opposite fixes and looked identical before.
* Changed: AI requests no longer borrow the feed timeout, which is clamped to
  30 seconds. Generation returns nothing until the whole answer is ready, so
  it gets its own 60 second default, filterable with `wpnc_ai_timeout`.


= 1.14.0 =
* Added: post type, status, author and category can be set per item, under
  Advanced in the editor. The Settings values become defaults rather than
  rules, so one piece can go out as a draft, or under a different author,
  without changing a global setting and changing it back.
* Note: an item only records where it disagrees. Leaving a field on "use the
  default" means it keeps following Settings, so changing a setting later
  still moves every item that never had an opinion.
* Added: the assistant can be asked for structure - "put these in a table",
  "tidy this up" - and a matching Add structure action. Tables, definition
  lists, code blocks and h4-h6 now survive; previously the model returned
  them correctly and they were stripped back to running text on the way in,
  so the instruction looked as though it had been ignored.
* Fixed: the category a source mapping assigned at fetch time is shown in the
  editor rather than appearing blank, so saving an item no longer clears it.

= 1.13.0 =
* Added: approving an item now asks where to send it. The queue row carries a
  Send to group - Site, Telegram, Bale, All - and the bulk bar carries the
  same choice as a dropdown.
* Added: Bale, which speaks the same bot API as Telegram, so both share one
  transport and differ only by address and credentials.
* Added: a destination appears as a button only once its credentials are
  saved AND its Test connection has passed. The test result is stored against
  a fingerprint of those credentials, so editing the token or chat id
  invalidates it rather than leaving a button that promises something it can
  no longer do.
* Added: Test connection checks the chat as well as the token. A valid token
  paired with a chat the bot cannot post to used to be indistinguishable from
  a working setup until the first real send.
* Changed: sending to a messenger without publishing to the site is allowed,
  and links readers to the original article, since there is no post to link.
* Changed: approval reports where the item actually went, and names any
  destination that refused it rather than reporting a flat success.
* Fixed: a bot token could reach the screen or the log inside a transport
  error, because it travels in the URL. It is redacted now.
* Note: unattended publishing is unchanged - a scheduled run still notifies
  every destination that has credentials, tested or not, so an existing
  install does not go quiet.

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
