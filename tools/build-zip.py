# -*- coding: utf-8 -*-
"""Build an installable plugin zip.

The repository root is a development checkout: it also holds the test suite,
the Python checkers, the CI workflow and Composer config. None of that belongs
on a live site, so zipping the folder by hand ships all of it. This packs only
what WordPress actually runs, under a top-level wp-news-collector/ folder.

    python tools/build-zip.py

Output: dist/boz-news-<version>.zip
"""
import io
import os
import re
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SLUG = 'wp-news-collector'
DIST = os.path.join(ROOT, 'dist')

# Everything WordPress loads at runtime, and nothing else.
INCLUDE_FILES = [
    'wp-news-collector.php',
    'uninstall.php',
    'readme.txt',
]
INCLUDE_DIRS = [
    'includes',
    'assets',
    'languages',
]

# Belt and braces: even inside the directories above, never ship these.
EXCLUDE_SUFFIXES = ('.py', '.pot', '.map', '.log')
EXCLUDE_NAMES = {'.DS_Store', 'Thumbs.db'}


def plugin_version():
    header = io.open(os.path.join(ROOT, 'wp-news-collector.php'), encoding='utf-8').read(4096)
    match = re.search(r'^\s*\*\s*Version:\s*([0-9][^\s]*)', header, re.M)
    if not match:
        raise SystemExit('Could not read Version from the plugin header.')
    return match.group(1)


def collect():
    files = []

    for name in INCLUDE_FILES:
        path = os.path.join(ROOT, name)
        if not os.path.isfile(path):
            raise SystemExit('Missing required file: ' + name)
        files.append(name)

    for directory in INCLUDE_DIRS:
        base = os.path.join(ROOT, directory)
        if not os.path.isdir(base):
            continue
        for dirpath, dirnames, filenames in os.walk(base):
            dirnames[:] = [d for d in dirnames if not d.startswith('.')]
            for filename in sorted(filenames):
                if filename in EXCLUDE_NAMES or filename.endswith(EXCLUDE_SUFFIXES):
                    continue
                full = os.path.join(dirpath, filename)
                files.append(os.path.relpath(full, ROOT).replace(os.sep, '/'))

    return sorted(files)


def main():
    version = plugin_version()
    files = collect()

    if not os.path.isdir(DIST):
        os.makedirs(DIST)

    out = os.path.join(DIST, 'boz-news-%s.zip' % version)
    if os.path.exists(out):
        os.remove(out)

    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as archive:
        for rel in files:
            # WordPress expects the plugin inside its own folder.
            archive.write(os.path.join(ROOT, rel), SLUG + '/' + rel)

    size = os.path.getsize(out)

    print('built %s' % os.path.relpath(out, ROOT).replace(os.sep, '/'))
    print('%d files, %.1f KB' % (len(files), size / 1024.0))
    print('')
    print('contents:')
    for rel in files:
        print('  ' + SLUG + '/' + rel)

    # A missing .mo means the front end silently falls back to English.
    if not any(f.endswith('.mo') for f in files):
        print('')
        print('WARNING: no .mo in languages/ - run: python tools/make_translations.py')
        return 1

    return 0


if __name__ == '__main__':
    sys.exit(main())
