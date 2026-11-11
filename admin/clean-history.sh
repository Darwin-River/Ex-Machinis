#!/usr/bin/env bash
# Make a cleaned copy of the repository's history for the new project repo
# (#527). It doesn't touch the original: it works on a fresh mirror clone,
# and you push the result to the new, empty repository yourself.
#
#   admin/clean-history.sh <source-url-or-path> <work-dir>          # clean
#   admin/clean-history.sh <source-url-or-path> <work-dir> --review # list, change nothing
#
# What it removes from every commit:
#   - old passwords and keys (database, APP_KEY, CRON_KEY, mail, wiki),
#     found by their setting names, replaced with REMOVED; none were live
#   - the 2018 users dump, and the old .env files that held the passwords
#   - the commercial web template (#524): its scripts, styles, fonts and
#     stock images, which can't be distributed under MIT
#   - a 2021 engine log, a real player's row in the migration notes, and
#     the old server's address
#
# Needs git-filter-repo (pip install git-filter-repo). Rewriting history
# gives every commit a new hash: clones of the old repository can't push
# to the new one, and should be cloned again.
set -euo pipefail

src=${1:?source repository URL or path}
work=${2:?work directory}
mode=${3:-}

rm -rf "$work" && mkdir -p "$work"
git clone --quiet --mirror "$src" "$work/repo.git"
cd "$work/repo.git"

# 1. Old secrets, by setting name. Placeholders and short values are skipped,
#    since every match is replaced everywhere in history.
git log -p --all --no-color --no-textconv | grep -E '^\+' | python3 -c '
import re, sys
placeholders = re.compile(r"^(CHANGE_?ME|null|secret|password\w*|pass\w*|test[-_].*|<.*>|\$.*|getenv.*|your.*|xxx+|example.*)$", re.I)
patterns = [  # (pattern, shortest value taken)
    (re.compile(r"^\+\s*(?:export\s+)?[A-Z][A-Z_]*(?:PASSWORD|_KEY)\s*=\s*[\x27\"]?([^\x27\"\s;]+)"), 8),
    (re.compile(r"^\+\s*[A-Z][A-Z_]*(?:PASSWORD|_KEY)\s*=\s*\"([^\"]+)\"\s*;"), 8),
    (re.compile(r"^\+\s*MAIL_[A-Z_]*PASSWORD\s*=\s*\"?([^\"\s]+)\"?\s*$"), 6),
    (re.compile(r"^\+\s*\$wg(?:DBpassword|SecretKey|UpgradeKey)\s*=\s*\"([^\"]+)\""), 8),
    (re.compile(r"new Mailbox\([^,]+,\s*\x27[^\x27]+\x27,\s*\x27([^\x27]+)\x27"), 6),  # 2018 app/Agent.php
]
found = set()
for line in sys.stdin:
    for p, shortest in patterns:
        m = p.search(line.rstrip("\n"))
        v = m.group(1) if m else ""
        if len(v) >= shortest and not placeholders.match(v) and "$(" not in v:
            found.add(v)
for v in sorted(found):
    print(v)
' > "$work/secrets.txt"

{
  while IFS= read -r v; do printf 'literal:%s==>REMOVED\n' "$v"; done < "$work/secrets.txt"
  # Personal data and the old server, found by pattern so this script
  # doesn't repeat them: users rows quoted in docs or dumps (#526), and the
  # old server's address in SFTP instructions
  git log -p --all --no-color --no-textconv | grep -E '^\+' | python3 -c '
import re, sys
seen = set()
for line in sys.stdin:
    for m in re.finditer(r"INSERT INTO `users` VALUES \((\d+),\x27([^\x27]+)\x27,\x27([^\x27]+@[^\x27]+)\x27,\x27(\$2y\$[^\x27]+)\x27", line):
        for value, stand_in in ((m.group(2), "example"), (m.group(3), "player@example.com"), (m.group(4), "$2y$10$" + "EXAMPLEHASH" * 5)):
            if value not in seen and value != stand_in:
                seen.add(value); print("literal:%s==>%s" % (value, stand_in))
    for m in re.finditer(r"@(\d{1,3}(?:\.\d{1,3}){3})\b", line):
        if m.group(1) not in seen:
            seen.add(m.group(1)); print("literal:%s==>OLD_SERVER_IP" % m.group(1))
'
} > "$work/replacements.txt"

# 2. Whole files to drop from every commit
git log --all --name-only --format= | sort -u > "$work/all-paths.txt"
python3 - "$work/all-paths.txt" > "$work/drop-paths.txt" <<'PY'
import re, sys
exact = {
    'game-engine/sql/exmachinis_02082018.sql',          # the 2018 users dump
    '.env', 'model of .env', 'game-engine/scripts/.env',  # old settings with passwords
    'game-engine/doc/migration_steps/migration_steps.txt',
    'resources/views/layouts/game-engine.log',           # a 2021 engine log
    'ms-access/ms_access_client.laccdb',
    'resources/assets/js/core.min.js', 'resources/assets/js/script.js',
    'resources/assets/js/html5shiv.min.js', 'resources/assets/js/pointer-events.min.js',
    'resources/assets/sass/materialdesignicons.css.map',
}
prefixes = ('html/js/', 'html/fonts/', 'resources/assets/sass/custom-styles/',
            'resources/assets/sass/bootstrap/', 'resources/assets/sass/fonts/', 'public/js/',
            'public/fonts/', 'public/css/')
keep_images = re.compile(r'(^|/)(logo\.(png|psd)|favicon\.(ico|svg))$')
for p in (l.strip() for l in open(sys.argv[1])):
    if not p:
        continue
    template_image = (p.startswith(('html/images/', 'public/images/')) and not keep_images.search(p))
    if p in exact or p.startswith(prefixes) or template_image:
        print(p)
PY

echo "Secrets to replace: $(wc -l < "$work/secrets.txt") (lengths: $(awk '{print length}' "$work/secrets.txt" | tr '\n' ' '))"
echo "Paths to drop: $(wc -l < "$work/drop-paths.txt")"
if [ "$mode" = "--review" ]; then
  sed -E 's/^(.{4}).*/\1***/' "$work/secrets.txt"
  echo "Review mode: nothing changed. Path list: $work/drop-paths.txt"
  exit 0
fi

git filter-repo --force --quiet \
  --replace-text "$work/replacements.txt" \
  --invert-paths --paths-from-file "$work/drop-paths.txt"

# 3. Check: none of the old values, or dropped files, are left anywhere
left=0
while IFS= read -r v; do
  if git log --all -p --no-color -S"$v" --format=%h | grep -q .; then echo "STILL PRESENT: ${v:0:4}***"; left=1; fi
done < "$work/secrets.txt"
if git log --all --name-only --format= | grep -qxF 'game-engine/sql/exmachinis_02082018.sql'; then echo "STILL PRESENT: the 2018 dump"; left=1; fi
rm -f "$work/secrets.txt" "$work/replacements.txt"
[ $left -eq 0 ] && echo "Clean. Commits: $(git rev-list --all | wc -l). Size: $(du -sh . | cut -f1)."
echo "Push the live branch and tags to the new, empty repository:"
echo "  git -C $work/repo.git push <new-repository-url> public-forge"
echo "  git -C $work/repo.git push <new-repository-url> --tags"
exit $left
