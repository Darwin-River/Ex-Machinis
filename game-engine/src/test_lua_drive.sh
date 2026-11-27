#!/bin/sh
# lua-drive (#1007) against a hand-written snapshot: go, then mine, then
# print, then a query the snapshot doesn't answer. No engine, no database.
#   test_lua_drive.sh <lua-drive> <lua_library.lua>
set -e
drive=$1
library=$2
dir=$(mktemp -d)
trap 'rm -rf "$dir"' EXIT

cat > "$dir/snapshot.lua" <<'EOF'
return {
  drone = {id = 7, company = 3, location = 3, credits = 1000, holdings = {[2061] = 50, [5001] = 1, [5131] = 1}},
  now = 864000, epoch = 0,
  masses = {[2061] = 1, [1100] = 2},
  here = {[640] = true},
  protocols = {
    [3] = {seconds = 0, wait = true},
    [5001] = {seconds = 11, inputs = {[2061] = 5}, tools = {[5001] = true}, travel = true},
    [11100] = {seconds = 3600, inputs = {[2061] = 1}, outputs = {[1100] = 1}, mined = {[1100] = true}, tools = {[5131] = true}},
    [11110] = {seconds = 3600, inputs = {[2061] = 1}, outputs = {[1110] = 1}, mined = {[1110] = true}, tools = {[5999] = true}},
  },
  answers = {
    ["705|3|10"] = {{1000, 7200, 2, thousand_km = 1000, seconds = 7200, hours = 2}},
    ["411|10|1100"] = {{4, 0, 4, abundance = 4, miners = 0, yield = 4}},
    ["@10|640"] = {{1100, 6, 100, 10, 0, 4, resource = 1100, units = 6}},
  },
}
EOF

cat > "$dir/script.lua" <<'EOF'
function launched()
  go(moon)
  mine(kunspar)
  mine(kunspar)
  mine(yalspar)
  print("kunspar " .. units(kunspar) .. " fuel " .. units(fuel) .. " at " .. location())
  set_status("mined")
  wait(30)
  print("minute " .. now().minute)
  print(crowd(mars))
end
EOF

# The memory line (#1077) varies with the library: checked on its own below
out=$("$drive" "$library" "$dir/script.lua" "$dir/snapshot.lua" | grep -v '^memory	')
expected=$(printf '%s\n' \
  'action	0	5001	10	0	7200	0	2061:-5' \
  'action	7200	11100		0	3600	0	1100:+4 2061:-1' \
  'action	10800	11100		0	3600	0	1100:+2 2061:-1' \
  'action	14400	11110		-2	0	0	' \
  'say	kunspar 6 fuel 43 at 10' \
  'status	mined' \
  'action	14400	3	30	0	1800	0	' \
  'say	minute 270' \
  'need	721	4')
if [ "$out" != "$expected" ]; then
  echo "test_lua_drive: FAIL"
  echo "--- got:"; echo "$out"
  echo "--- expected:"; echo "$expected"
  exit 1
fi
echo "ok   go, mine (a deposit running out, a tool missing), print, set_status, wait, and a need"

# Answered, it runs to the end; a second run is the same (math.random is seeded)
sed -i 's/^  answers = {/  answers = {["721|4"] = {{2, 0}},/' "$dir/snapshot.lua"
echo 'function launched() print(math.random(1000000)) print(crowd(mars)) end' > "$dir/random.lua"
a=$("$drive" "$library" "$dir/random.lua" "$dir/snapshot.lua")
b=$("$drive" "$library" "$dir/random.lua" "$dir/snapshot.lua")
[ "$a" = "$b" ] || { echo "test_lua_drive: FAIL: two runs differ"; exit 1; }
echo "$a" | grep -q '^say	2$' && echo "$a" | tail -1 | grep -q '^end	finished$' || { echo "test_lua_drive: FAIL: $a"; exit 1; }
echo "ok   an answered need, finished, and the same on a rerun"

# The limits: 50 actions, a game day, instructions; an error says its line
echo 'function launched() while true do wait(1) end end' > "$dir/busy.lua"
"$drive" "$library" "$dir/busy.lua" "$dir/snapshot.lua" | tail -1 | grep -q '^end	actions$' || { echo "test_lua_drive: FAIL: actions"; exit 1; }
echo 'function launched() while true do wait(600) end end' > "$dir/long.lua"
"$drive" "$library" "$dir/long.lua" "$dir/snapshot.lua" | tail -1 | grep -q '^end	day$' || { echo "test_lua_drive: FAIL: day"; exit 1; }
echo 'function launched() while true do end end' > "$dir/spin.lua"
"$drive" "$library" "$dir/spin.lua" "$dir/snapshot.lua" | tail -1 | grep -q '^end	instructions$' || { echo "test_lua_drive: FAIL: instructions"; exit 1; }
printf 'function launched()\n  local x = nil\n  x.y = 1\nend\n' > "$dir/bad.lua"
"$drive" "$library" "$dir/bad.lua" "$dir/snapshot.lua" | grep -q '^error	script:3: ' || { echo "test_lua_drive: FAIL: error line"; exit 1; }
echo 'function launched() print(io, os, load, math.randomseed) end' > "$dir/sandbox.lua"
"$drive" "$library" "$dir/sandbox.lua" "$dir/snapshot.lua" | grep -q '^say	nil nil nil nil$' || { echo "test_lua_drive: FAIL: sandbox"; exit 1; }
echo "ok   the limits, an error's line, and the sandbox"

# A drone's memory (#1077): the library and script fit, and say how much they
# held; data past what a drone has left fails as on a drone, not at 32 MB
"$drive" "$library" "$dir/random.lua" "$dir/snapshot.lua" | grep -q '^memory	[0-9]*	262144$' || { echo "test_lua_drive: FAIL: memory line"; exit 1; }
printf 'function launched()\n  local t = {}\n  for i = 1, 20000 do t[i] = {i} end\n  print(#t)\nend\n' > "$dir/hog.lua"
hog=$("$drive" "$library" "$dir/hog.lua" "$dir/snapshot.lua" || true)   # exits 1: the script failed
echo "$hog" | grep -q '^error	not enough memory' && ! echo "$hog" | grep -q '^say' || { echo "test_lua_drive: FAIL: memory cap: $hog"; exit 1; }
echo "ok   a drone's memory: reported, and its cap enforced"
echo "test_lua_drive: all passed"
