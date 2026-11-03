#!/bin/sh
# Compiles every ```lua block in the players' documents (#380: FORTH was
# retired, and every example is Lua): bin/lua-check < each block
set -e
dir=$(mktemp -d)
trap 'rm -rf "$dir"' EXIT
n=0
for f in "$@"; do
  awk -v d="$dir" -v base="$(basename "$f")" '
    /^```lua[ \t]*$/ { inb = 1; k++; out = d "/" base "." k ".lua"; next }
    /^```[ \t]*$/ && inb { inb = 0; close(out); next }
    inb { print > out }' "$f"
done
for b in "$dir"/*.lua; do
  [ -e "$b" ] || continue
  n=$((n + 1))
  bin/lua-check < "$b" || { echo "$(basename "$b") doesn't compile:"; cat "$b"; exit 1; }
done
echo "lua: all $n code examples in the documents compile"
