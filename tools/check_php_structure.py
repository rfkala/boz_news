"""Structural sanity check for PHP files: balanced braces/parens/brackets and
terminated strings/comments, PHP mode only (HTML between ?> and <?php is
skipped). Not a parser, but it catches the mistakes an editor makes."""
import sys, io
BS = chr(92)

def check(path):
    s = io.open(path, encoding='utf-8').read()
    i, n, line = 0, len(s), 1
    stack, in_php = [], False
    pairs = {'}': '{', ')': '(', ']': '['}

    def adv(j):
        nonlocal line, i
        line += s.count('\n', i, j)
        i = j

    while i < n:
        if not in_php:
            j = s.find('<?', i)
            if j < 0:
                adv(n); break
            adv(j)
            i += 5 if s.startswith('<?php', i) else (3 if s.startswith('<?=', i) else 2)
            in_php = True
            continue
        c = s[i]
        if c == '\n':
            line += 1; i += 1; continue
        if s.startswith('?>', i):
            in_php = False; i += 2; continue
        if s.startswith('//', i) or c == '#':
            while i < n and s[i] != '\n' and not s.startswith('?>', i): i += 1
            continue
        if s.startswith('/*', i):
            j = s.find('*/', i + 2)
            if j < 0: return "%s: unterminated /* comment opened at line %d" % (path, line)
            adv(j); i += 2; continue
        if s.startswith('<<<', i):
            j = s.find('\n', i)
            if j < 0: return "%s: malformed heredoc at line %d" % (path, line)
            tag = s[i+3:j].strip().strip('"' + "'")
            k = s.find('\n' + tag, j)
            if k < 0: return "%s: unterminated heredoc %s at line %d" % (path, tag, line)
            adv(k); i += len(tag) + 1; continue
        if c == '"' or c == "'":
            q, j = c, i + 1
            while j < n:
                if s[j] == BS: j += 2; continue
                if s[j] == q: break
                j += 1
            if j >= n: return "%s: unterminated %s string opened at line %d" % (path, q, line)
            adv(j); i += 1; continue
        if c in '{([':
            stack.append((c, line)); i += 1; continue
        if c in '})]':
            if not stack: return "%s: stray '%s' at line %d" % (path, c, line)
            op, ol = stack.pop()
            if op != pairs[c]:
                return "%s: '%s' at line %d closes '%s' opened at line %d" % (path, c, line, op, ol)
            i += 1; continue
        i += 1

    if stack:
        op, ol = stack[-1]
        return "%s: unclosed '%s' opened at line %d" % (path, op, ol)
    return None

bad = 0
for p in sys.argv[1:]:
    r = check(p)
    if r:
        print('FAIL ' + r); bad += 1
    else:
        print('ok   ' + p)
print('--- %d file(s), %d problem(s)' % (len(sys.argv) - 1, bad))
sys.exit(1 if bad else 0)
