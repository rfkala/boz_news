# Running Boz News locally

This repository holds **only the plugin source**. There is no WordPress, no
PHP and no MySQL in it, which is why `mysqldump`, `php` and `composer` are not
on this machine and should not be.

Three separate things, often confused:

| | Needs | Purpose |
|---|---|---|
| Static checks | Python | `python tools/verify.py` — runs today, no setup |
| Unit tests | PHP only | `tools/run-tests.ps1` — no WordPress, no database |
| The plugin itself | WordPress (PHP + MySQL) | Clicking through the real admin |

---

## Recommended: Laragon (one download solves both)

Laragon bundles PHP, MySQL and a web server in a single folder. The portable
edition needs no installer and no administrator rights, and the PHP it ships
also runs the unit tests — so this one download covers the second and third
rows above.

1. Download the **portable** edition: <https://laragon.org/download/>
2. Extract it to `C:\laragon` (any folder works; the test runner looks in
   `C:\laragon`, `D:\laragon` and your home folder).
3. Run `laragon.exe`, press **Start All**. Apache and MySQL come up.
4. Right-click the Laragon window → **Quick app → WordPress**. It downloads
   WordPress, creates the database, and gives you a local URL.
5. Link this repo into that site — nothing is copied, so editing here is live
   there immediately:

   ```
   .\tools\link-to-wp.ps1 -WordPress C:\laragon\www\<sitename>
   ```

6. Activate **Boz News** on the Plugins screen.
7. Run the unit tests with the same PHP:

   ```
   .\tools\run-tests.ps1
   ```

   It finds Laragon's PHP on its own. You still need `phpunit.phar`; run
   `.\tools\run-tests.ps1 -Setup` for that one link.

To undo the link later: `.\tools\link-to-wp.ps1 -WordPress <path> -Unlink`.
That removes the link only — it never touches this repository.

---

## Lighter alternative: WordPress Playground

`npx @wp-playground/cli@latest server` runs WordPress inside Node using
PHP-WASM. No PHP, no MySQL, no installer. You already have Node 20.

**Read this before relying on it:** Playground uses **SQLite**, not MySQL.
Several queries in this plugin are MySQL-specific — `DATE_SUB(... INTERVAL ...
SECOND)` in the retention and upgrade paths, `SHOW TABLES LIKE` in the table
check, and `TRUNCATE TABLE` in the logger. Those may behave differently or
fail under SQLite while working perfectly on your host.

So Playground is good for walking through the admin UI, and not a safe way to
test the fetch, retention or upgrade paths. Laragon matches your host; this
does not.

I have not run the Playground command myself — this machine has no network
access — so treat the exact flags as unverified.

---

## Shipping to the live site

Never zip the repository folder by hand: it would put `tests/`, `tools/`,
`.github/` and the Composer config on your server. Build the release instead:

```
python tools/build-zip.py
```

That writes `dist/boz-news-<version>.zip` containing only the 24 files
WordPress runs, under a `wp-news-collector/` folder. Upload it through
**Plugins → Add New → Upload Plugin**.
