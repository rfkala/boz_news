# -*- coding: utf-8 -*-
"""Static checks for Boz News. Run from the plugin root:

    python tools/verify.py

These exist because the interesting failure modes in a WordPress plugin are
not syntax errors. They are a method that was renamed in one file and still
called in another, a translation key added to the English map and forgotten in
the Persian one, and a sprintf placeholder that only matches in one language.
All three are fatal or visibly broken at runtime and invisible in review.

`php -l` and PHPUnit cover what they cover; these cover the seams between
files. Both run in CI.
"""
import os
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)

CHECKS = [
    ('PHP structure',
     ['check_php_structure.py'],
     'braces, strings and comments balance in every PHP file'),
    ('References',
     ['check_references.py'],
     'every class, method and function call resolves to a declaration'),
    ('i18n parity',
     ['check_i18n_parity.py'],
     'every key the admin JS asks for exists in both languages'),
    ('sprintf parity',
     ['check_sprintf_parity.py'],
     'both halves of each bilingual string carry the same placeholders'),
    ('Asset versions',
     ['check_enqueue.py'],
     'every bundled script and style busts cache on change'),
    ('Translations',
     ['check_translations.py'],
     'the generated .mo parses and returns what the .po promises'),
]


def php_files():
    files = ['wp-news-collector.php', 'uninstall.php']
    inc = os.path.join(ROOT, 'includes')
    files += [os.path.join('includes', f) for f in sorted(os.listdir(inc)) if f.endswith('.php')]
    tests = os.path.join(ROOT, 'tests')
    if os.path.isdir(tests):
        files += [os.path.join('tests', f) for f in sorted(os.listdir(tests)) if f.endswith('.php')]
    return files


def run(name, script, args, description):
    print('\n== %s: %s' % (name, description))
    sys.stdout.flush()
    result = subprocess.run(
        [sys.executable, os.path.join(HERE, script)] + args,
        cwd=ROOT,
    )
    return result.returncode == 0


def main():
    os.chdir(ROOT)
    failed = []

    for name, (script,), description in [(n, s, d) for n, s, d in CHECKS]:
        args = php_files() if script == 'check_php_structure.py' else []
        if not run(name, script, args, description):
            failed.append(name)

    print('')
    if failed:
        print('FAILED: ' + ', '.join(failed))
        return 1

    print('All static checks passed.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
