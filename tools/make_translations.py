# -*- coding: utf-8 -*-
"""Generate languages/wp-news-collector.pot and the fa_IR .po/.mo pair.

The plugin calls load_plugin_textdomain() and there was no languages/
directory, so every __() string was untranslated no matter what. The admin
panel does not depend on this - it uses the wpnc__() pair layer - but the
front end and the cron schedule names do.

Written in Python because msgfmt is not always installed on a Windows dev box,
and a translation that only compiles on the maintainer's machine is the same
as no translation. Run from the plugin root:

    python tools/make_translations.py
"""
import io
import os
import re
import struct
import sys

DOMAIN = 'wp-news-collector'
LOCALE = 'fa_IR'
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'languages')

# Only strings that legitimately follow the site locale live here. Admin-panel
# text goes through wpnc__() instead and is not part of this catalogue.
TRANSLATIONS = {
    # Front end.
    'Loading...': 'در حال بارگذاری...',
    'Load More News': 'اخبار بیشتر',
    'No more news': 'خبر دیگری نیست',
    'Could not load more news. Please try again.': 'دریافت اخبار بیشتر ممکن نشد. لطفاً دوباره تلاش کنید.',
    'No news found.': 'خبری یافت نشد.',
    'No more posts available.': 'پست دیگری موجود نیست.',
    'Source:': 'منبع:',

    # Cron schedule names, shown by WordPress and other plugins.
    'Every 15 Minutes': 'هر ۱۵ دقیقه',
    'Every 3 Hours': 'هر ۳ ساعت',

    # Privacy policy text.
    'Boz News': 'بُز نیوز',
    'Boz News stores RSS feed items in a moderation queue and may send article text to OpenAI for rewriting and published post links to Telegram when those integrations are enabled. Review your configured feeds and API keys to ensure they match your site privacy policy.':
        'بُز نیوز آیتم‌های فید RSS را در یک صف تأیید ذخیره می‌کند و در صورت فعال بودن این یکپارچگی‌ها، ممکن است متن مقاله را برای بازنویسی به OpenAI و لینک پست‌های منتشرشده را به تلگرام ارسال کند. فیدها و کلیدهای API تنظیم‌شده را بررسی کنید تا با سیاست حریم خصوصی سایت شما هم‌خوان باشند.',
}

STRING = re.compile(
    r"""\b(?:esc_html__|esc_attr__|__|_e|esc_html_e)\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'"""
    + DOMAIN + r"""'\s*\)"""
)


def sources():
    files = [os.path.join(ROOT, 'wp-news-collector.php')]
    inc = os.path.join(ROOT, 'includes')
    files += [os.path.join(inc, f) for f in sorted(os.listdir(inc)) if f.endswith('.php')]
    return files


def collect():
    """Every translatable literal, with the file:line it came from."""
    found = {}
    for path in sources():
        text = io.open(path, encoding='utf-8').read()
        rel = os.path.relpath(path, ROOT).replace(os.sep, '/')
        for m in STRING.finditer(text):
            msgid = m.group(1).replace("\\'", "'").replace('\\\\', '\\')
            line = text.count('\n', 0, m.start()) + 1
            found.setdefault(msgid, []).append('%s:%d' % (rel, line))
    return found


def po_escape(v):
    return v.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')


HEADER_POT = '''msgid ""
msgstr ""
"Project-Id-Version: Boz News 1.3.0\\n"
"Report-Msgid-Bugs-To: \\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"X-Generator: tools/make_translations.py\\n"
'''

HEADER_PO = '''msgid ""
msgstr ""
"Project-Id-Version: Boz News 1.3.0\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Language: fa_IR\\n"
"Plural-Forms: nplurals=2; plural=(n > 1);\\n"
"X-Generator: tools/make_translations.py\\n"
'''


def write_catalog(path, header, entries, translate):
    out = [header]
    for msgid in sorted(entries):
        for ref in entries[msgid]:
            out.append('\n#: %s' % ref)
        out.append('\nmsgid "%s"' % po_escape(msgid))
        out.append('\nmsgstr "%s"\n' % po_escape(translate(msgid)))
    io.open(path, 'w', encoding='utf-8', newline='\n').write(''.join(out))


def write_mo(path, pairs):
    """Minimal GNU .mo writer (little endian, no hashing table)."""
    items = sorted(pairs.items())
    ids = b'\x00'.join(k.encode('utf-8') for k, _ in items) + b'\x00'
    strs = b'\x00'.join(v.encode('utf-8') for _, v in items) + b'\x00'

    keystart = 7 * 4 + 16 * len(items)
    valuestart = keystart + len(ids)

    koffsets, voffsets = [], []
    offset = 0
    for k, _ in items:
        length = len(k.encode('utf-8'))
        koffsets.append((length, offset + keystart))
        offset += length + 1
    offset = 0
    for _, v in items:
        length = len(v.encode('utf-8'))
        voffsets.append((length, offset + valuestart))
        offset += length + 1

    output = struct.pack(
        '<7I',
        0x950412de,          # magic
        0,                   # revision
        len(items),          # number of strings
        7 * 4,               # offset of key table
        7 * 4 + len(items) * 8,  # offset of value table
        0, 0,                # hash table size and offset
    )
    for length, off in koffsets:
        output += struct.pack('<2I', length, off)
    for length, off in voffsets:
        output += struct.pack('<2I', length, off)
    output += ids + strs

    with open(path, 'wb') as handle:
        handle.write(output)


def main():
    if not os.path.isdir(OUT):
        os.makedirs(OUT)

    entries = collect()

    write_catalog(os.path.join(OUT, DOMAIN + '.pot'), HEADER_POT, entries, lambda _: '')
    write_catalog(
        os.path.join(OUT, '%s-%s.po' % (DOMAIN, LOCALE)),
        HEADER_PO,
        entries,
        lambda msgid: TRANSLATIONS.get(msgid, ''),
    )

    # The .mo carries the header plus every string that actually has a
    # translation; untranslated entries must be absent, not empty.
    pairs = {'': HEADER_PO.split('msgstr ""\n', 1)[1].replace('"', '').replace('\\n', '\n')}
    for msgid in entries:
        if TRANSLATIONS.get(msgid):
            pairs[msgid] = TRANSLATIONS[msgid]
    write_mo(os.path.join(OUT, '%s-%s.mo' % (DOMAIN, LOCALE)), pairs)

    missing = sorted(m for m in entries if not TRANSLATIONS.get(m))
    print('%d translatable strings found' % len(entries))
    print('%d translated into %s' % (len(pairs) - 1, LOCALE))
    if missing:
        print('untranslated (left empty in the .po for a translator):')
        for m in missing:
            print('  ' + m)

    unused = sorted(set(TRANSLATIONS) - set(entries))
    if unused:
        print('WARNING: translations with no matching string in the source:')
        for m in unused:
            print('  ' + m)
        return 1

    return 0


if __name__ == '__main__':
    sys.exit(main())
