-- Salvager: a company that lives on wrecks (#932). It produces modules and
-- Scrap (A1) from other companies' wrecks: with a Salvager (A1) aboard,
-- salvage(wreck) takes a wreck's modules one a run, then up to 10 scrap from
-- its hull. Wrecks decay, so it salvages the freshest one with something
-- left, sells its loot where the House pays most and moves on to wherever
-- the game shows the most wrecks. A startup's drone can't salvage (-16).

max_salvager_price = 4000    -- paid to another drone
min_condition = 300          -- thousandths; 1000 is new
refuel_below = 40
max_trip_hours = 48
days_to_skip_an_emptied_place = 5
credits_per_game_hour = 4

local SALVAGER, SCRAP, WELDER = 5231, 2071, 5511
local FIRST_MODULE, LAST_MODULE = 5000, 5899
local GAME_HOUR = 60
local FUEL_TOP_UP = 60
local FUEL_FLOOR = 10
local LEAST_SPACE_LEFT = 100
local REFUSALS_BEFORE_GIVING_UP = 3
local STARTUP_CANT_SALVAGE = -16
local WRECK_JUST_EMPTIED = -15
local ORES_WHERE_DRONES_WORK = {wolfite, kunspar, yalspar, vladnium}

tools = {}       -- tools[module] = units it keeps; the rest is loot
emptied = {}     -- emptied[place] = the game day it left there with nothing to salvage

function hours_to(place)
  if place == location() then return 0 end
  local hours = trip(place)
  return hours >= 0 and hours or math.huge
end

function exchanges()
  local list = {}
  for _, offer in ipairs(house_supply(fuel)) do list[offer.place] = true end
  return list
end

function is_exchange(place) return exchanges()[place] == true end

-- Wrecks pile up where drones work: the exchanges and the mining sites
function places_to_look()
  local list = exchanges()
  for _, ore in ipairs(ORES_WHERE_DRONES_WORK) do
    for _, s in ipairs(best_sites(ore)) do list[s.place] = true end
  end
  return list
end

function market_paying_most_for_scrap()
  local best, best_net = nil, 0
  for _, bid in ipairs(house_demand(SCRAP)) do
    local net = bid.price * math.max(units(SCRAP), 1) - hours_to(bid.place) * credits_per_game_hour
    if bid.price > 0 and net > best_net then best, best_net = bid.place, net end
  end
  return best
end

function nearest_exchange()
  local nearest, fewest = location(), math.huge
  for place in pairs(exchanges()) do
    if hours_to(place) < fewest then nearest, fewest = place, hours_to(place) end
  end
  return nearest
end

function go_to_cheapest_seller_of(r, n)
  local best = where_to_buy(r, n, credits_per_game_hour)[1]
  if best then go(best.place) end
end

function build(r)
  local rec = recipe(r)
  if not rec then return -1 end
  for input, n in pairs(rec.uses) do
    if units(input) < n then
      go_to_cheapest_seller_of(input, n - units(input))
      buy_here(n - units(input), input); wait(GAME_HOUR)
    end
  end
  return make(r)
end

-- No exchange sells a Salvager: it buys one from a drone, or builds one
-- from a Welder it builds first
function get_salvager()
  if units(SALVAGER) > 0 then return end
  go_to_cheapest_seller_of(SALVAGER, 1)
  buy_here(1, SALVAGER, max_salvager_price)
  wait(GAME_HOUR)
  while units(SALVAGER) == 0 do
    local next_tool = units(WELDER) == 0 and WELDER or SALVAGER
    set_status("building a " .. resource_name(next_tool))
    if build(next_tool) ~= 0 then wait(4 * GAME_HOUR) end
  end
end

-- Only once: after a restart, salvaged modules aboard are loot, not tools
function remember_tools()
  if next(tools) then return end
  for _, item in ipairs(hold()) do
    if item.resource >= FIRST_MODULE and item.resource <= LAST_MODULE then tools[item.resource] = item.units end
  end
end

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

function salvage_here()
  local refused = 0
  while units(fuel) > FUEL_FLOOR and space() > LEAST_SPACE_LEFT and refused < REFUSALS_BEFORE_GIVING_UP do
    local w = best_wreck()
    if not w then return end
    set_status(string.format("salvaging wreck %d (%d%%, %d modules, %d scrap)",
                             w.drone, w.condition // 10, w.modules, w.scrap))
    local result = salvage(w.drone)
    if result == STARTUP_CANT_SALVAGE then return end
    if result == 0 then refused = 0 else refused = refused + 1 end
    if result ~= 0 and result ~= WRECK_JUST_EMPTIED then wait(GAME_HOUR) end
  end
end

-- A module nobody bids for here is kept for the next exchange
function sell_loot()
  for _, item in ipairs(hold()) do
    local r = item.resource
    if r == SCRAP then
      sell_all(SCRAP)
    elseif r >= FIRST_MODULE and r <= LAST_MODULE and item.units > (tools[r] or 0) then
      sell_here(item.units - (tools[r] or 0), r)
    end
  end
end

function refuel()
  if units(fuel) < refuel_below then buy_here(FUEL_TOP_UP, fuel); wait(GAME_HOUR) end
end

-- wrecks_at counts emptied wrecks too, so the most wrecks is a guess
function next_place()
  local best, most = nil, 0
  for place in pairs(places_to_look()) do
    local emptied_lately = emptied[place] and today() - emptied[place] < days_to_skip_an_emptied_place
    if place ~= location() and not emptied_lately and hours_to(place) <= max_trip_hours and wrecks_at(place) > most then
      best, most = place, wrecks_at(place)
    end
  end
  return best
end

function move_on()
  emptied[location()] = today()
  if not is_exchange(location()) then go(market_paying_most_for_scrap() or nearest_exchange()) end
  sell_loot()
  refuel()
  local place = next_place()
  if place then
    set_status("moving on to " .. place_name(place) .. ": " .. wrecks_at(place) .. " wrecks there")
    go(place)
  else
    set_status("no wrecks worth salvaging within reach: waiting")
    wait(4 * GAME_HOUR)
  end
end

function launched()
  fuel_floor(FUEL_FLOOR)
  equip(factory_kit)
  while house_only() do
    set_status("a startup can't salvage (-16): this design needs a matured company")
    wait(12 * GAME_HOUR)
  end
  get_salvager()
  remember_tools()
  while true do
    salvage_here()
    move_on()
    report()
  end
end
