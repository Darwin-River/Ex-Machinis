-- The smoke test's released drone (deploy/smoke-test.sh), at Earth: each
-- step prints what it gave and reports it
local function hello() return "forge-release-" end
-- padding line 1: a released script may be as long as it likes
-- padding line 2: a released script may be as long as it likes
-- padding line 3: a released script may be as long as it likes
-- padding line 4: a released script may be as long as it likes
-- padding line 5: a released script may be as long as it likes
-- padding line 6: a released script may be as long as it likes
-- padding line 7: a released script may be as long as it likes
-- padding line 8: a released script may be as long as it likes
-- padding line 9: a released script may be as long as it likes
-- padding line 10: a released script may be as long as it likes
-- padding line 11: a released script may be as long as it likes
-- padding line 12: a released script may be as long as it likes
-- padding line 13: a released script may be as long as it likes
-- padding line 14: a released script may be as long as it likes
-- padding line 15: a released script may be as long as it likes
-- padding line 16: a released script may be as long as it likes
-- padding line 17: a released script may be as long as it likes
-- padding line 18: a released script may be as long as it likes
-- padding line 19: a released script may be as long as it likes
-- padding line 20: a released script may be as long as it likes
-- padding line 21: a released script may be as long as it likes
-- padding line 22: a released script may be as long as it likes
-- padding line 23: a released script may be as long as it likes
-- padding line 24: a released script may be as long as it likes
-- padding line 25: a released script may be as long as it likes
-- padding line 26: a released script may be as long as it likes
-- padding line 27: a released script may be as long as it likes
-- padding line 28: a released script may be as long as it likes
-- padding line 29: a released script may be as long as it likes
-- padding line 30: a released script may be as long as it likes
-- padding line 31: a released script may be as long as it likes
-- padding line 32: a released script may be as long as it likes
-- padding line 33: a released script may be as long as it likes
-- padding line 34: a released script may be as long as it likes
-- padding line 35: a released script may be as long as it likes
-- padding line 36: a released script may be as long as it likes
-- padding line 37: a released script may be as long as it likes
-- padding line 38: a released script may be as long as it likes
-- padding line 39: a released script may be as long as it likes
-- padding line 40: a released script may be as long as it likes
local function say(text)
  print(text)
  report()
end

-- Units of a resource the Earth Exchange's station drone offers (0 until
-- it posts its sell order)
local function station_offer(resource)
  for _, o in ipairs(ask(610, resource)) do
    if o[4] == 1 then return math.max(o[3] - o[2], 0) end
  end
  return 0
end

function launched()
  say(hello() .. "ok7")
  -- The game's names
  say("game-words " .. stdlib() .. " " .. moon .. " " .. kunspar .. " "
      .. tostring(units(fuel) > 0) .. " " .. tostring(trip(earth) > 0))
  -- A message for the drones that listen
  say("broadcast " .. broadcast("smoke-hello"))
  -- Buy 10 fuel from the Earth Exchange's station drone
  while station_offer(fuel) < 10 do wait(60) end
  say("fuel " .. pull(10, fuel, 1))
  -- Ask it for one more unit of a good than it offers: refused (-5), and
  -- the fuel the try burned comes back
  for _, good in ipairs({inorganics_a1, organics_a1, metals_a1, metals_a2, alloys_a1}) do
    local n = station_offer(good)
    if n > 0 then
      local before = units(fuel)
      local r = pull(n + 1, good, 1)
      say("too-much " .. r .. " fuel " .. before .. " " .. units(fuel) .. " got " .. units(good))
      break
    end
  end
  -- Once the test says (its balance past 90,000): sell fuel, see the order
  -- here, and wait 1,200 game minutes, selling all the while
  while credits() < 90000 do wait(60) end
  local r = sell_order(1, 1, fuel)
  local rows = ask(610, fuel)
  local seen = rows[1] and rows[1][4] or 0
  say("sell-and-wait " .. r .. " " .. seen .. " " .. wait(1200))
end
