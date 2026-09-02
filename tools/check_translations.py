# -*- coding: utf-8 -*-
"""The .mo is written by tools/make_translations.py rather than msgfmt, so it
gets read back with a real gettext parser. A malformed catalogue fails open -
WordPress just shows English - which is exactly the kind of breakage nobody
notices."""
import gettext
import io
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LANG = os.path.join(ROOT, 'languages')
DOMAIN = 'wp-news-collector'
LOCALE = 'fa_IR'

problems = []

mo_path = os.path.join(LANG, '%s-%s.mo' % (DOMAIN, LOCALE))
po_path = os.path.join(LANG, '%s-%s.po' % (DOMAIN, LOCALE))
pot_path = os.path.join(LANG, '%s.pot' % DOMAIN)

for path in (mo_path, po_path, pot_path):
    if not os.path.isfile(path):
        problems.append('missing: ' + os.path.relpath(path, ROOT))

if problems:
    print('\n'.join(problems))
    sys.exit(1)

with open(mo_path, 'rb') as handle:
    try:
        catalog = gettext.GNUTranslations(handle)
    except Exception as exc:  # noqa: BLE001 - any parse failure is a failure
        print('the .mo does not parse: %s' % exc)
        sys.exit(1)

info = catalog.info()
if 'utf-8' not in (info.get('content-type') or '').lower():
    problems.append('.mo header does not declare UTF-8: %r' % info.get('content-type'))

# Every msgid with a non-empty msgstr in the .po must resolve in the .mo.
po = io.open(po_path, encoding='utf-8').read()
entries = re.findall(r'msgid "((?:[^"\\]|\\.)*)"\nmsgstr "((?:[^"\\]|\\.)*)"', po)

translated = 0
for msgid, msgstr in entries:
    if not msgid or not msgstr:
        continue
    translated += 1
    key = msgid.replace('\\"', '"').replace('\\n', '\n')
    want = msgstr.replace('\\"', '"').replace('\\n', '\n')
    got = catalog.gettext(key)
    if got != want:
        problems.append('.mo does not return the .po translation for %r' % key[:60])

if not translated:
    problems.append('the .po contains no translations at all')

# An unknown string must come back unchanged rather than empty.
probe = 'a string that is deliberately not in the catalogue'
if catalog.gettext(probe) != probe:
    problems.append('untranslated strings do not pass through unchanged')

if problems:
    print('\n'.join(problems))
    print('--- %d problem(s)' % len(problems))
    sys.exit(1)

print('translations: %s parses, %d strings resolve, UTF-8 declared'
      % (os.path.basename(mo_path), translated))
