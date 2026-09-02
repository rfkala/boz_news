# -*- coding: utf-8 -*-
"""Every t('key') the admin JS asks for must exist in BOTH localize maps.
Four status labels and the log table headers were missing from the Persian
map, so they silently fell back to English in Persian mode."""
import io
import re
import sys

js = io.open('assets/admin.js', encoding='utf-8').read()
php = io.open('wp-news-collector.php', encoding='utf-8').read()

# t('key', 'fallback') — but not request('wpnc_action')
used = set(re.findall(r"(?<![\w.])t\(\s*'([a-z0-9_]+)'", js))

# Anchor to the admin block; the frontend one earlier in the file also
# defines an 'i18n' key.
anchor = php.index("'wpnc_ajax'")
start = php.index("'i18n'", anchor)
mid = php.index("'i18n_fa'", anchor)
end = php.index('add_action( ', mid)

# Step past the "'i18n' => array(" header itself so it is not read as a key.
en = set(re.findall(r"'([a-z0-9_]+)'\s*=>", php[php.index('array(', start):mid]))
fa = set(re.findall(r"'([a-z0-9_]+)'\s*=>", php[php.index('array(', mid):end]))

problems = []
for k in sorted(used - en):
    problems.append('missing from i18n (en): ' + k)
for k in sorted(used - fa):
    problems.append('missing from i18n_fa:    ' + k)
for k in sorted(en - used):
    problems.append('unused key in i18n:      ' + k)
for k in sorted(en ^ fa):
    problems.append('key present in only one language: ' + k)

if problems:
    print('\n'.join(problems))
    print('--- %d problem(s)' % len(problems))
    sys.exit(1)

print('i18n: %d keys used by admin.js, all present in both languages' % len(used))
