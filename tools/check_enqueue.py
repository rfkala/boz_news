# -*- coding: utf-8 -*-
"""Every bundled asset must be versioned by file modification time.

This exists because the fix was silently reverted once: the i18n generator
rewrites the whole admin enqueue function, and its template still carried the
old WPNC_VERSION line. The result shipped, and a changed admin.js loaded from
browser cache - a dashboard that rendered its markup and then did nothing,
with no error to explain it.

A check is cheaper than remembering.
"""
import io
import re
import sys

src = io.open('wp-news-collector.php', encoding='utf-8').read()

# wp_enqueue_script( 'handle', URL . 'assets/x.js', deps, VERSION, ... )
CALL = re.compile(
    r"wp_(?:enqueue|register)_(?:script|style)\(\s*'([^']+)'\s*,"
    r"[^;]*?'(assets/[^']+)'\s*,[^;]*?,\s*([^,)]+)",
    re.S,
)

problems = []
seen = 0

for match in CALL.finditer(src):
    handle, asset, version = match.group(1), match.group(2), match.group(3).strip()
    seen += 1
    line = src.count('\n', 0, match.start()) + 1

    if 'wpnc_asset_version' not in version:
        problems.append(
            'wp-news-collector.php:%d  %s (%s) is versioned by %s; use '
            "wpnc_asset_version( '%s' )" % (line, handle, asset, version, asset)
        )

if not seen:
    problems.append('no asset enqueues found - has the enqueue code moved?')

if problems:
    print('\n'.join(problems))
    print('--- %d problem(s)' % len(problems))
    sys.exit(1)

print('enqueue: %d assets, all versioned by file modification time' % seen)
