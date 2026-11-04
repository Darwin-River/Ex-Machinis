-- The smoke test's first drone (deploy/smoke-test.sh). Each step prints what
-- it gave and reports it, so the test can follow; where the test changes the
-- world between steps, the drone waits for the change.
local function say(text)
  print(text)
  report()
end

-- Waits, a game hour at a time (a real second here), until ready() is true
local function hold_until(ready)
  while not ready() do wait(60) end
end

local function sq(n) return n * n end

-- Whether a drone has a buy order for a resource here
local function bids(drone, resource)
  for _, o in ipairs(ask(600, resource)) do
    if o[4] == drone then return true end
  end
  return false
end

-- The broadcast the released drone sends from Earth, once it is known here
local function heard()
  for _, id in ipairs(events_earth(message_event)) do
    if message(id) == "smoke-hello" then return id end
  end
  return nil
end

function launched()
  -- A clone runs this too, with its parent's variables: it says so, and
  -- its launched function returns
  if parent and parent ~= me() then
    say("launched-word-ran " .. tostring(note))
    return
  end
  say("sum " .. 2 + 3)
  -- The starting sequence: unpack the starter kit, fly to the Moon (the
  -- line after the trip runs once it is over), mine kunspar
  say("kit " .. units(mining_kit))
  say("unpack " .. unpack())
  print("sq " .. sq(7))
  print("moon-trip " .. travel(moon))
  say("after-trip " .. sq(6))
  say("mine " .. mine(kunspar))
  -- Computing burns fuel too
  local s = 0
  for i = 1, 20000 do s = s + i end
  say("computed " .. s)
  -- Sell a unit to the player's other company
  say("sister " .. push(1, kunspar, 101))
  -- Refining needs 20 kunspar (each mining run gives 5 here)
  for _ = 1, 4 do mine(kunspar) end
  say("refine " .. refine(kunspar))
  -- Launch a clone once the test gives it a Mining Cruiser kit
  hold_until(function() return units(cruiser_kit) > 0 end)
  parent, note = me(), "from-parent"
  say("launch " .. launch(me(), cruiser_kit))
  -- Money past 32,767, once the test sets it
  hold_until(function() return credits() >= 70000 end)
  say("doubles " .. credits() .. " " .. 300 * 300)
  -- Sell a unit of metal to the Moon Depot's station drone
  hold_until(function() return bids(2, metals_a1) end)
  say("station " .. push(1, metals_a1, 2))
  -- Mine the deposit the test emptied; mine again once it refills
  local k = units(kunspar)
  local r = mine(kunspar)
  say("mined-dry gained " .. units(kunspar) - k .. " result " .. r)
  hold_until(function() return deposit(kunspar) > 0 end)
  say("mined-again " .. mine(kunspar))
  -- Plunder the wreck the test brought here
  k = units(kunspar)
  r = plunder(100)
  local got = units(kunspar) - k
  say("plunder " .. r .. " gained " .. got .. " half " .. tostring(got >= 5))
  -- Hear the released drone's broadcast, from the Earth database
  local id
  hold_until(function() id = heard() return id ~= nil end)
  say("hear " .. tostring(crowd(earth) > 0) .. " " .. event_drone(id) .. " " .. message(id))
  -- Once the test brings it to Earth: buy 2 fuel from the cheapest seller,
  -- the waiting released drone
  hold_until(function() return location() == earth end)
  local _, seller = best_offer(fuel)
  say("bought " .. pull(2, fuel, seller) .. " from " .. seller)
  -- Back on the Moon: Earth's live orders can't be seen from here
  hold_until(function() return location() == moon end)
  local rows = ask(611, fuel)
  say("far:" .. tostring(rows[1] and rows[1][4] or "none") .. ":end")
end
