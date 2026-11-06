#!/usr/bin/env python3
"""Add and refresh hidden link anchors on numbered SOP items.

GitHub gives link targets only to headings, so a link cannot otherwise land on
a numbered item such as AD-01 5.5. This script puts a hidden HTML anchor, for
example <a id="5.5"></a>, at the start of each numbered item's text, so a link
ending in #5.5 opens the document at that item.

Every numbered list item in Procedures/SOPs and Procedures/Attachments gets an
anchor named by its full section number, built from the numbers of the items
that contain it (for example "5.13.2"). Nesting is read from indentation. A
heading inside an item, such as "5. # Procedures", gets its anchor after the
"# ", which leaves GitHub's own heading anchor (#procedures) unchanged.

The script is idempotent: it replaces any existing anchor with the one that
matches the item's current number, so run it after renumbering. Lines inside
fenced code blocks are left alone.

Usage:
    python3 Procedures/Tools/update_anchors.py           # update every file
    python3 Procedures/Tools/update_anchors.py --check   # report, change nothing
    python3 Procedures/Tools/update_anchors.py FILE ...  # update these files

With --check the exit code is 1 if any file would change.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ITEM = re.compile(r'^( *)(\d+)\. (# )?(?:<a id="[0-9.]*"></a>)?(.*)$')
FENCE = re.compile(r'^\s{0,3}(```|~~~)')


def update(text):
    """Return the text with fresh anchors, and the number of lines changed."""
    stack = []  # open items, outermost first: (indent, content column, number)
    in_fence = False
    changed = 0
    out = []
    for line in text.splitlines(keepends=True):
        body = line.rstrip('\r\n')
        eol = line[len(body):]
        new = body
        if FENCE.match(body):
            in_fence = not in_fence
        elif in_fence:
            pass
        elif (m := ITEM.match(body)):
            indent, number, heading, rest = len(m[1]), m[2], m[3] or '', m[4]
            while stack and stack[-1][0] >= indent:
                stack.pop()
            stack.append((indent, indent + len(number) + 2, number))
            anchor = '.'.join(n for _, _, n in stack)
            new = f'{m[1]}{number}. {heading}<a id="{anchor}"></a>{rest}'
        elif body.strip():
            # Other text closes every item whose content it is not indented into.
            indent = len(body) - len(body.lstrip(' '))
            while stack and stack[-1][1] > indent:
                stack.pop()
        if new != body:
            changed += 1
        out.append(new + eol)
    return ''.join(out), changed


def main(args):
    check = '--check' in args
    files = [Path(a) for a in args if a != '--check']
    if not files:
        files = sorted((ROOT / 'SOPs').glob('*.md')) + sorted((ROOT / 'Attachments').glob('*.md'))
    stale = 0
    for path in files:
        text = path.read_text(encoding='utf-8')
        new, changed = update(text)
        if not changed:
            continue
        stale += 1
        if check:
            print(f'Anchors out of date ({changed} lines): {path}')
        else:
            path.write_text(new, encoding='utf-8')
            print(f'Updated {changed} anchors: {path}')
    return 1 if check and stale else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
