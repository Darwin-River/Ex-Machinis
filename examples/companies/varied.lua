-- Varied: copies that vary (#932). A miner-refiner company (Mining Kit)
-- whose three tuning numbers live in one table, `tune`. It mines wolfite at
-- the Moon, refines it, and sells the metals at the Moon Depot. Each copy it
-- launches starts from the numbers of the drone that is earning most, then
-- nudges each one a step or two, up or down. The nudge comes from who the
-- copy is (`lineage()`: its parent and generation, and its own drone ID), so
-- it is the same every time the copy restarts, and no two copies are alike.
-- Why? Copies that are all the same fail at the same moment: one price
-- slump and the whole fleet waits, or sells cheap, together. Copies that
-- differ spread the risk, and the fleet keeps what works: the best earner's
-- numbers are the ones the next copy starts from.
--
-- How a drone knows which copy earns most: there is no per-drone earnings
-- word (`earnings(hours)` is the whole company's), so each drone adds up what
-- its own sales fetched (`sale_value` just before it sells: close enough) and
-- broadcasts it once a game day with its numbers. Messages are private to
-- the company, so a parent reads its fleet's messages before it launches.

-- Settings: the numbers each copy varies
tune = {
  sell_at = 20,    -- least bid for Metals (A1) it sells into (it waits up to 6 game hours for one)
  hold_cap = 40,   -- metals aboard at which it flies to the Moon Depot to sell them
  low_fuel = 40,   -- fuel below which it buys more
}
steps = {sell_at = 2, hold_cap = 5, low_fuel = 5}         -- one nudge, for each number
lowest = {sell_at = 8, hold_cap = 20, low_fuel = 25}      -- nudges never go below these
highest = {sell_at = 60, hold_cap = 120, low_fuel = 90}   -- or above these

-- Settings that stay the same in every copy
grow_at = 7000     -- credits at which a matured company launches a copy
work_cash = 1500   -- credits it keeps per drone, so copies don't starve it

local LOAD = 18                 -- wolfite for one refining run
local COPY_FUEL = 40            -- fuel handed to each new copy
local metals = {metals_a1, metals_a2, inorganics_a2}   -- what refining wolfite makes

-- Saved state: a copy inherits its parent's globals, so it can tell it's new
started = 0        -- the drone this state belongs to
copy = false       -- set just before launching: the copy starts as a copy
parent_tune = nil  -- the numbers the parent hands its copy
takings = 0        -- what this drone's own sales fetched
born_day = 0       -- the game day it started
told_day = -1      -- the game day it last broadcast its numbers

-- --- Varying ---------------------------------------------------------------

-- A whole number from -2 to 2, never 0, that depends only on who this drone
-- is: the same after a restart, different for every copy. salt makes each
-- number move its own way
function nudge_of(salt)
  local l = lineage()
  local k = (me() * 7 + l.parent * 3 + l.generation + salt) % 4   -- 0 to 3
  return ({-2, -1, 1, 2})[k + 1]
end

-- (A list, not pairs(tune): pairs may visit the names in any order)
local NAMES = {"sell_at", "hold_cap", "low_fuel"}
function vary()
  for salt, name in ipairs(NAMES) do
    local v = tune[name] + nudge_of(salt) * steps[name]
    tune[name] = math.max(lowest[name], math.min(highest[name], v))
  end
end

-- What this drone has earned a game day, on average
function earned_a_day() return takings // math.max(1, today() - born_day) end

-- Once a game day, tell the fleet its numbers and what they earn (1 fuel)
function tell_fleet()
  if today() == told_day then return end
  told_day = today()
  broadcast(string.format("tune %d %d %d earned %d", tune.sell_at, tune.hold_cap, tune.low_fuel, earned_a_day()))
end

-- The numbers of the drone that earns most a day, from the fleet's
-- messages (newest first; its own included), or its own if none says more
function best_tune()
  local best, most = tune, earned_a_day()
  for i, id in ipairs(events_earth(message_event)) do
    if i > 60 then break end
    local s, h, f, e = string.match(message(id) or "", "^tune (%d+) (%d+) (%d+) earned (%d+)")
    if s and tonumber(e) > most then
      best, most = {sell_at = tonumber(s), hold_cap = tonumber(h), low_fuel = tonumber(f)}, tonumber(e)
    end
  end
  return best
end

-- --- Work ------------------------------------------------------------------

function refuel()
  if units(fuel) >= tune.low_fuel then return end
  raise_cash(150)
  buy_here(30, fuel)
  wait(60)
end

function metals_aboard()
  local n = 0
  for _, r in ipairs(metals) do n = n + units(r) end
  return n
end

-- Sells all of a good here, and counts what it fetched as this drone's
function sell_counted(r)
  local n = units(r)
  if n == 0 then return end
  local value = sale_value(n, r)
  if sell_here(n, r) == 0 then takings = takings + value end
end

-- At the Moon Depot: wait up to 6 game hours for a bid of at least sell_at
-- for Metals (A1), then sell everything
function sell_trip()
  set_status("selling " .. metals_aboard() .. " metals at the Moon Depot")
  go(moon)
  for _ = 1, 6 do
    if sell_price(metals_a1) >= tune.sell_at then break end
    wait(60)
  end
  for _, r in ipairs(metals) do sell_counted(r) end
end

function work()
  refuel()
  while units(wolfite) < LOAD do
    local result = mine(wolfite)
    if result == -30 then refuel()               -- -30: at the fuel floor
    elseif result ~= 0 then wait(60) end         -- anything else: wait and try again
  end
  refine(wolfite)
  if metals_aboard() >= tune.hold_cap then
    sell_trip()
    grow()
  end
  tell_fleet()
end

-- --- Growing ---------------------------------------------------------------

function get_one(r)
  if units(r) > 0 then return end
  buy_here(1, r)
  for _ = 1, 6 do
    if units(r) > 0 then return end
    wait(60)
  end
end

-- A copy runs this program with its parent's globals: copy and parent_tune
-- are set just before launch, so it starts from the best numbers
function grow()
  if house_only() or fleet_full() then return end   -- a startup saves for its debt
  local health = company_health()
  local need = math.max(grow_at, 5000 + work_cash * ((health and health.working or 1) + 1))
  if credits() < need and units(cruiser_kit) == 0 then return end
  get_one(cruiser_kit)
  if units(cruiser_kit) == 0 then return end
  buy_here(90 - math.min(units(fuel), 90), fuel)
  if units(fuel) < 80 then return end
  copy, parent_tune = true, best_tune()
  if launch(me(), cruiser_kit) ~= 0 then return end
  get_one(mining_kit)
  local clones = my_clones()
  local newest = clones[#clones]
  if newest and units(mining_kit) > 0 then
    push(1, mining_kit, newest.drone)
    push(COPY_FUEL, fuel, newest.drone)
  end
end

-- launched() runs again after a restart: only a new drone takes its
-- parent's numbers and varies them
function launched()
  fuel_floor(15)
  equip(mining_kit)   -- a copy waits for the kit it is handed
  if started ~= me() then
    if copy and parent_tune then
      tune = {sell_at = parent_tune.sell_at, hold_cap = parent_tune.hold_cap, low_fuel = parent_tune.low_fuel}
      vary()
    end
    started, takings, born_day = me(), 0, today()
    set_status(string.format("sells at %d, hauls %d, refuels below %d", tune.sell_at, tune.hold_cap, tune.low_fuel))
  end
  go(moon)
  while true do work() end
end
