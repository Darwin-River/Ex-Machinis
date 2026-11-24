-- Varied: copies that vary. A miner-refiner (Mining Kit) producing metals,
-- whose three tuning numbers live in one table, `tune`. Each copy starts
-- from the numbers of the drone earning most, then nudges each a step or
-- two by who it is (lineage() and its drone ID), so copies differ and one
-- slump can't stop the whole fleet at once. Each drone counts what its own
-- sales fetched and broadcasts it with its numbers once a game day. It
-- names no place: it mines and sells where the game says pays most.

tune = {
  least_bid_to_sell = 20,
  metals_per_sale = 40,
  refuel_below = 40,
}
step_of = {least_bid_to_sell = 2, metals_per_sale = 5, refuel_below = 5}
lowest = {least_bid_to_sell = 8, metals_per_sale = 20, refuel_below = 25}
highest = {least_bid_to_sell = 60, metals_per_sale = 120, refuel_below = 90}
-- pairs(tune) may visit the names in any order; a nudge's salt must not change
tune_names = {"least_bid_to_sell", "metals_per_sale", "refuel_below"}

copy_at_credits = 7000
credits_kept_per_drone = 1500
hours_per_stay = 120
percent_from_best_still_good = 10
credits_per_game_hour = 4

ore = wolfite
metals_sold = {metals_a1, metals_a2, inorganics_a2}

local GAME_HOUR = 60
local FUEL_FLOOR = 15
local ORE_PER_REFINING = 18
local CREDITS_FOR_FUEL = 150
local FUEL_TOP_UP = 30
local AT_FUEL_FLOOR = -30
local HOURS_WAITING_FOR_A_BID = 6
local HOURS_TO_WAIT_FOR_DELIVERY = 6
local COPY_COST = 5000
local FUEL_TO_LAUNCH = 90
local LEAST_FUEL_TO_LAUNCH = 80
local FUEL_FOR_COPY = 40
local FLEET_MESSAGES_READ = 60
local UNREACHABLE = 999

-- Globals survive a restart, and a copy inherits them
started = 0
copy = false
parent_tune = nil
site = 0
takings = 0
born_day = 0
told_day = -1

-- Varying

-- A whole number from -2 to 2, never 0, that depends only on who this drone
-- is: the same after a restart, different for every copy. salt makes each
-- number move its own way
function nudge_of(salt)
  local l = lineage()
  local k = (me() * 7 + l.parent * 3 + l.generation + salt) % 4   -- 0 to 3
  return ({-2, -1, 1, 2})[k + 1]
end

function vary()
  for salt, name in ipairs(tune_names) do
    local nudged = tune[name] + nudge_of(salt) * step_of[name]
    tune[name] = math.max(lowest[name], math.min(highest[name], nudged))
  end
end

function earned_a_day() return takings // math.max(1, today() - born_day) end

function tell_fleet()
  if today() == told_day then return end
  told_day = today()
  broadcast(string.format("tune %d %d %d earned %d",
    tune.least_bid_to_sell, tune.metals_per_sale, tune.refuel_below, earned_a_day()))
end

function best_tune()
  local best, most = tune, earned_a_day()
  for i, id in ipairs(events_earth(message_event)) do
    if i > FLEET_MESSAGES_READ then break end
    local bid, per_sale, refuel_at, earned = string.match(message(id) or "", "^tune (%d+) (%d+) (%d+) earned (%d+)")
    if bid and tonumber(earned) > most then
      best = {least_bid_to_sell = tonumber(bid), metals_per_sale = tonumber(per_sale), refuel_below = tonumber(refuel_at)}
      most = tonumber(earned)
    end
  end
  return best
end

-- Where to mine and sell

function hours_to(place)
  if place == location() then return 0 end
  local trip = trip_to(place)
  return trip and trip.hours or UNREACHABLE
end

function units_an_hour(place, yield)
  local hours = hours_to(place)
  if hours == UNREACHABLE then return 0 end
  return yield * hours_per_stay / (hours_per_stay + hours)
end

-- Copies split the good sites by drone number rather than all taking the best
function choose_site()
  local rates, top = {}, 0
  for _, s in ipairs(best_sites(ore)) do
    rates[s.place] = units_an_hour(s.place, s.yield)
    top = math.max(top, rates[s.place])
  end
  local good = {}
  for place, rate in pairs(rates) do
    if top > 0 and rate * 100 >= top * (100 - percent_from_best_still_good) then table.insert(good, place) end
  end
  if #good == 0 then return location() end
  table.sort(good)
  return good[me() % #good + 1]
end

function best_market()
  local value = {}
  for _, metal in ipairs(metals_sold) do
    local aboard = units(metal)
    for _, bid in ipairs(house_demand(metal)) do
      if aboard > 0 and bid.price > 0 and bid.left > 0 then
        value[bid.place] = (value[bid.place] or 0) + bid.price * math.min(aboard, bid.left)
      end
    end
  end
  local best, best_net = location(), -math.huge
  for place, credits_there in pairs(value) do
    local hours = hours_to(place)
    local net = credits_there - hours * credits_per_game_hour
    if hours < UNREACHABLE and net > best_net then best, best_net = place, net end
  end
  return best
end

-- Work

function refuel()
  if units(fuel) >= tune.refuel_below then return end
  raise_cash(CREDITS_FOR_FUEL)
  buy_here(FUEL_TOP_UP, fuel)
  wait(GAME_HOUR)
end

function metals_aboard()
  local n = 0
  for _, metal in ipairs(metals_sold) do n = n + units(metal) end
  return n
end

-- sale_value just before selling stands in for a per-drone earnings figure, which the game doesn't give
function sell_counted(metal)
  local n = units(metal)
  if n == 0 then return end
  local value = sale_value(n, metal)
  if sell_here(n, metal) == 0 then takings = takings + value end
end

function sell_trip()
  local market = best_market()
  set_status("selling " .. metals_aboard() .. " metals at " .. place_name(market))
  go(market)
  for _ = 1, HOURS_WAITING_FOR_A_BID do
    if sell_price(metals_a1) >= tune.least_bid_to_sell then break end
    wait(GAME_HOUR)
  end
  for _, metal in ipairs(metals_sold) do sell_counted(metal) end
  go(site)
end

function mine_a_load()
  while units(ore) < ORE_PER_REFINING do
    local result = mine(ore)
    if result == AT_FUEL_FLOOR then refuel()
    elseif result ~= 0 then wait(GAME_HOUR) end
  end
end

function work()
  refuel()
  mine_a_load()
  refine(ore)
  if metals_aboard() >= tune.metals_per_sale then
    sell_trip()
    grow()
  end
  tell_fleet()
end

-- Growing

function get_one(resource)
  if units(resource) > 0 then return end
  buy_here(1, resource)
  for _ = 1, HOURS_TO_WAIT_FOR_DELIVERY do
    if units(resource) > 0 then return end
    wait(GAME_HOUR)
  end
end

function credits_needed_to_grow()
  local health = company_health()
  local drones = health and health.working or 1
  return math.max(copy_at_credits, COPY_COST + credits_kept_per_drone * (drones + 1))
end

function hand_newest_copy_its_kit()
  local clones = my_clones()
  local newest = clones[#clones]
  if newest and units(mining_kit) > 0 then
    push(1, mining_kit, newest.drone)
    push(FUEL_FOR_COPY, fuel, newest.drone)
  end
end

-- A startup saves for its debt. copy and parent_tune are set just before
-- launching, so the copy starts from the best numbers
function grow()
  if house_only() or fleet_full() then return end
  if credits() < credits_needed_to_grow() and units(cruiser_kit) == 0 then return end
  get_one(cruiser_kit)
  if units(cruiser_kit) == 0 then return end
  buy_here(FUEL_TO_LAUNCH - math.min(units(fuel), FUEL_TO_LAUNCH), fuel)
  if units(fuel) < LEAST_FUEL_TO_LAUNCH then return end
  copy, parent_tune = true, best_tune()
  if launch(me(), cruiser_kit) ~= 0 then return end
  get_one(mining_kit)
  hand_newest_copy_its_kit()
end

-- Only a new drone takes its parent's numbers and varies them: one that
-- varied them at every restart would wander
function launched()
  fuel_floor(FUEL_FLOOR)
  equip(mining_kit)
  if started ~= me() then
    if copy and parent_tune then
      tune = {least_bid_to_sell = parent_tune.least_bid_to_sell, metals_per_sale = parent_tune.metals_per_sale,
              refuel_below = parent_tune.refuel_below}
      vary()
    end
    started, takings, born_day = me(), 0, today()
    site = choose_site()
    set_status(string.format("sells at %d, hauls %d, refuels below %d",
      tune.least_bid_to_sell, tune.metals_per_sale, tune.refuel_below))
  end
  go(site)
  while true do work() end
end
