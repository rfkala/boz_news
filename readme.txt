=== WP News Collector ===
Contributors: jules
Tags: rss, atom, news, aggregator, ai, moderation
Requires at least: 5.8
Tested up to: 6.4
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fetch, moderate, rewrite, and publish news from RSS/Atom feeds with logging and admin review.

== Description ==

WP News Collector fetches RSS/Atom sources, filters items, stores them in a moderation queue, and publishes approved news as standard posts or a dedicated News custom post type.

Key features:

* RSS/Atom source list with optional category mapping and source keys.
* Optional source-specific XPath rules for content and image extraction.
* Moderation queue with AJAX search, pagination, approve, reject, edit, and bulk actions.
* Manual fetch tool with operational logs and queue statistics.
* WP-Cron scheduling with a fetch lock to avoid overlapping runs.
* Duplicate detection by source URL, GUID, and stored post meta.
* Optional full-text extraction and image sideloading with request limits.
* Optional OpenAI rewrite/translation/tag generation.
* Optional Telegram notification after publishing.
* Shortcode `[news_bulletin]` and Elementor widget support.

== Installation ==

1. Upload the `wp-news-collector` directory to `/wp-content/plugins/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Go to News Collector > Settings.
4. Add one RSS/Atom source per line.
5. Use Logs & Tools > Run fetch now to test the feeds and inspect errors.

== Source Formats ==

Use one source per line:

* `https://example.com/feed`
* `https://example.com/feed|5`
* `https://example.com/feed|5|example_source`

The second value is the WordPress category ID. The third value is an optional source key used by Source Rules.

== Source Rules ==

Optional source rules use this format:

`source_key|content_xpath|image_xpath`

Leave Source Rules empty unless a feed/article page needs custom extraction.

== Frequently Asked Questions ==

= Why did my feed not import anything? =

Open News Collector > Logs & Tools and run a manual fetch. The logs show invalid URLs, RSS errors, duplicate skips, OpenAI failures, image sideload failures, and publish errors.

= Are API keys displayed in the admin? =

No. Saved OpenAI and Telegram secrets are not rendered back into the form. Leave the field blank to keep the saved value or enter `__delete__` to remove it.

= Does the plugin delete data on uninstall? =

Yes. `uninstall.php` removes plugin options, scheduled events, transients, queue table, and log table.

== Changelog ==

= 1.1.0 =
* Rebuilt fetch pipeline with logging, queue repository, cron lock, and source parsing.
* Added manual fetch, real logs, queue pagination/search, and safer admin rendering.
* Added schema migration fields for feed URL, source key, GUID, post ID, timestamps, and errors.
* Hardened AJAX, frontend shortcode output, image extraction, OpenAI, and Telegram handling.
* Added deactivation cleanup, uninstall cleanup, and privacy policy text.

= 1.0.0 =
* Initial release with RSS fetching, moderation, AI rewrite, Telegram support, and Elementor integration.
