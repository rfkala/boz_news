"""Static reference check across the plugin: every WPNC_Class::member and
$this->member() call must resolve to something declared in the codebase.
Catches the class of mistake `php -l` would never see and that only shows up
as a fatal error at runtime."""
import io
import os
import re
import sys

FILES = sorted(
    ['wp-news-collector.php', 'uninstall.php']
    + [os.path.join('includes', f) for f in os.listdir('includes') if f.endswith('.php')]
)

src = {f: io.open(f, encoding='utf-8').read() for f in FILES}
blob = '\n'.join(src.values())

# --- declarations ---------------------------------------------------------
classes = set(re.findall(r'\bclass\s+(WPNC_\w+)', blob))
methods = {}          # class -> set(method)
consts = {}           # class -> set(const)
props = {}            # class -> set(prop)

for f, s in src.items():
    for m in re.finditer(r'\bclass\s+(WPNC_\w+)[^{]*\{', s):
        cls = m.group(1)
        # crude body slice: to the next top-level "class " or EOF
        start = m.end()
        nxt = re.search(r'\nclass\s+WPNC_\w+', s[start:])
        body = s[start:start + nxt.start()] if nxt else s[start:]
        methods.setdefault(cls, set()).update(
            re.findall(r'\bfunction\s+(\w+)\s*\(', body))
        consts.setdefault(cls, set()).update(
            re.findall(r'\bconst\s+(\w+)', body))
        props.setdefault(cls, set()).update(
            re.findall(r'\bp(?:rivate|ublic|rotected)\s+\$(\w+)', body))

funcs = set(re.findall(r'^function\s+(\w+)\s*\(', blob, re.M))

problems = []

# --- static references ----------------------------------------------------
for f, s in src.items():
    for m in re.finditer(r'\b(WPNC_\w+)::(\w+)', s):
        cls, member = m.group(1), m.group(2)
        line = s.count('\n', 0, m.start()) + 1
        if cls not in classes:
            problems.append('%s:%d  unknown class %s' % (f, line, cls))
            continue
        if member in methods.get(cls, set()) or member in consts.get(cls, set()):
            continue
        if member == 'class':
            continue
        problems.append('%s:%d  %s::%s is not declared' % (f, line, cls, member))

# --- $this-> references, resolved within the owning class -----------------
for f, s in src.items():
    for cm in re.finditer(r'\bclass\s+(WPNC_\w+)[^{]*\{', s):
        cls = cm.group(1)
        start = cm.end()
        nxt = re.search(r'\nclass\s+WPNC_\w+', s[start:])
        body = s[start:start + nxt.start()] if nxt else s[start:]
        base = s.count('\n', 0, start) + 1
        known_m = methods.get(cls, set())
        known_p = props.get(cls, set())
        # Elementor subclass inherits a large parent API; skip it.
        if 'Elementor' in cls:
            continue
        for m in re.finditer(r'\$this->(\w+)', body):
            name = m.group(1)
            line = base + body.count('\n', 0, m.start())
            is_call = body[m.end():].lstrip().startswith('(')
            if is_call:
                if name not in known_m:
                    problems.append('%s:%d  $this->%s() undefined in %s'
                                    % (f, line, name, cls))
            elif name not in known_p and name not in known_m:
                problems.append('%s:%d  $this->%s undefined in %s'
                                % (f, line, name, cls))

# --- plugin-level function calls -----------------------------------------
for f, s in src.items():
    for m in re.finditer(r'(?<![\w$>:])(wpnc_[a-z_]+)\s*\(', s):
        name = m.group(1)
        if name in funcs:
            continue
        line = s.count('\n', 0, m.start()) + 1
        problems.append('%s:%d  call to undefined function %s()' % (f, line, name))

if problems:
    print('\n'.join(sorted(set(problems))))
    print('--- %d problem(s)' % len(set(problems)))
    sys.exit(1)

print('refcheck: %d files, %d classes, %d methods - all references resolve'
      % (len(FILES), len(classes), sum(len(v) for v in methods.values())))
