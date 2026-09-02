# -*- coding: utf-8 -*-
"""Both halves of every wpnc__() pair must carry the same sprintf placeholders.
A mismatch is a PHP warning plus a mangled message, and only in one language,
so it is exactly the kind of thing that ships unnoticed."""
import io
import os
import re
import sys

FILES = ['wp-news-collector.php'] + [
    os.path.join('includes', f) for f in sorted(os.listdir('includes')) if f.endswith('.php')
]

# wpnc__( 'en', 'fa' ) / wpnc_e( 'en', 'fa' ) with single-quoted literals
CALL = re.compile(
    r"wpnc_(?:_|e)\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*\)")
PLACE = re.compile(r'%(?:\d+\$)?[bcdeEfFgGosuxX%]')

problems = []
checked = 0

for p in FILES:
    s = io.open(p, encoding='utf-8').read()
    for m in CALL.finditer(s):
        checked += 1
        en, fa = m.group(1), m.group(2)
        a = sorted(PLACE.findall(en))
        b = sorted(PLACE.findall(fa))
        if a != b:
            line = s.count('\n', 0, m.start()) + 1
            problems.append('%s:%d  placeholders differ: %s vs %s'
                            % (p, line, a or '[]', b or '[]'))

if problems:
    print('\n'.join(problems))
    print('--- %d problem(s)' % len(problems))
    sys.exit(1)

print('sprintf: %d bilingual pairs, placeholders match in both languages' % checked)
