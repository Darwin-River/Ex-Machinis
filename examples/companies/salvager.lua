-- Salvager: a company that lives on wrecks (#932). With a Salvager (A1)
-- module aboard (resource 5231), salvage(wreck) takes a wreck's modules,
-- one a run, and once they are gone up to 10 Scrap (A1) (resource 2071)
-- from its hull. Every exchange buys scrap; modules sell where someone bids
-- for them, or work in your own drones. Wrecks decay (2% of what is left a
-- game day), so the best wreck is the freshest one with something left.
-- Round after round it:
--   1. lists the wrecks where it is (wrecks(): drone, plundered, condition,
--      modules, scrap) and salvages the one in the best condition;
--   2. when nothing worth having is left, sells its modules and scrap at an
--      exchange (sell_here), keeping the tools it works with;
--   3. moves on to the place with the most wrecks (wrecks_at), refuelling
--      at an exchange on the way.
--
-- What it assumes:
--   * It runs in a matured company. A startup's drone salvages nothing (-16)
--     and a startup's wrecks can't be salvaged, so as a startup it only
--     waits and says why.
--   * It equips itself with the Factory Kit (Engine, Smelter, Chemical
--     Reactor, Spider). No exchange's planets sell a Salvager, so it first
--     tries to buy one from another company's drone (buy_here, at most
--     max_salvager_price); failing that it builds one: the Spider makes a
--     Welder (A1) from Arc Welders (A1), and the Welder makes a Salvager from
--     Heat Sinks (A1) and Torches (A1), a game day each. It buys those
--     inputs at Earth (recipe() says what each needs).

-- Settings
max_salvager_price = 4000   -- most it pays another drone for a Salvager
min_condition = 300         -- worst wreck worth salvaging, in thousandths (1000 is new)
low_fuel = 40               -- fuel below which it buys more at an exchange
max_trip_hours = 48         -- farthest it flies to the next wrecks (game hours)
skip_days = 5               -- game days it stays away from a place it emptied

-- Goods the library doesn't name
local SALVAGER, SCRAP, WELDER = 5231, 2071, 5511
local exchanges = {earth, moon, mars}
local places = {earth, moon, mars, phobos, deimos}

-- Saved state
tools = {}       -- modules it keeps: tools[resource] = units (the rest it sells)
emptied = {}     -- emptied[place] = the game day it left that place with nothing to salvage

-- --- Equipping -------------------------------------------------------------

-- Buys what a recipe uses (less what is aboard), then makes it
function build(r)
  local rec = recipe(r)
  if not rec then return -1 end
  for input, n in pairs(rec.uses) do
    if units(input) < n then buy_here(n - units(input), input); wait(60) end
  end
  return make(r)
end

function get_salvager()
  if units(SALVAGER) > 0 then return end
  go(earth)
  buy_here(1, SALVAGER, max_salvager_price)
  wait(60)
  while units(SALVAGER) == 0 do
    if units(WELDER) == 0 then
      set_status("building a Welder (A1), to build a Salvager")
      if build(WELDER) ~= 0 then wait(240) end
    else
      set_status("building a Salvager (A1)")
      if build(SALVAGER) ~= 0 then wait(240) end
    end
  end
end

-- The modules aboard now are its tools: remember how many of each. Only
-- once: after a restart, salvaged modules aboard are loot, not tools
function remember_tools()
  if next(tools) then return end
  for _, item in ipairs(hold()) do
    if item.resource >= 5000 and item.resource < 5900 then tools[item.resource] = item.units end
  end
end

-- --- Salvaging -------------------------------------------------------------

-- The wreck here in the best condition that still has modules or scrap
function best_wreck()
  local best = nil
  for _, w in ipairs(wrecks()) do
    if (w.modules > 0 or w.scrap > 0) and w.condition >= min_condition then
      if not best or w.condition > best.condition then best = w end
    end
  end
  return best
end

-- Salvages here until nothing worth having is left (or the hold is full)
function salvage_here()
  local refused = 0
  while units(fuel) > 10 and space() > 100 and refused < 3 do
    local w = best_wreck()
    if not w then return end
    set_status(string.format("salvaging wreck %d (%d%%, %d modules, %d scrap)",
                             w.drone, w.condition // 10, w.modules, w.scrap))
    local result = salvage(w.drone)
    if result == -16 then return end   -- a startup: it can't
    if result == 0 then refused = 0 else refused = refused + 1 end   -- -15: just emptied (look again)
    if result ~= 0 and result ~= -15 then wait(60) end
  end
end

-- --- Selling and moving ----------------------------------------------------

function is_exchange(p)
  for _, e in ipairs(exchanges) do if e == p then return true end end
  return false
end

-- Sells the scrap, and every module beyond the tools it keeps
function sell_loot()
  for _, item in ipairs(hold()) do
    local r = item.resource
    if r == SCRAP then
      sell_all(SCRAP)
    elseif r >= 5000 and r < 5900 and item.units > (tools[r] or 0) then
      sell_here(item.units - (tools[r] or 0), r)   -- nobody bids here? kept for the next exchange
    end
  end
end

function refuel()
  if units(fuel) < low_fuel then buy_here(60, fuel); wait(60) end
end

-- The place within reach with the most wrecks (not one it just emptied);
-- wrecks_at counts emptied wrecks too, so it is a guess
function next_place()
  local best, most = nil, 0
  for _, p in ipairs(places) do
    local recently = emptied[p] and today() - emptied[p] < skip_days
    local hours = trip(p)
    if p ~= location() and not recently and hours >= 0 and hours <= max_trip_hours and wrecks_at(p) > most then
      best, most = p, wrecks_at(p)
    end
  end
  return best
end

function move_on()
  emptied[location()] = today()
  if not is_exchange(location()) then go(moon) end   -- the nearest exchange to most places
  sell_loot()
  refuel()
  local p = next_place()
  if p then
    set_status("moving on to " .. place_name(p) .. ": " .. wrecks_at(p) .. " wrecks there")
    go(p)
  else
    set_status("no wrecks worth salvaging within reach: waiting")
    wait(240)
  end
end

function launched()
  fuel_floor(10)
  equip(factory_kit)
  while house_only() do
    set_status("a startup can't salvage (-16): this design needs a matured company")
    wait(720)
  end
  get_salvager()
  remember_tools()
  while true do
    salvage_here()
    move_on()
    report()
  end
end
