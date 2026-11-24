-- Smith: a company design for Ex Machinis, in Lua. Release it with the
-- Factory Kit. It produces rods: it buys Metals (A1) wherever they cost
-- least after the trip, makes them into alloys and the alloys into rods,
-- and sells the rods wherever they pay most after the trip. It can't mine:
-- the mining gear is too big to carry with the factory tools.
-- It names no place: every "where" is a question to the game.

rods_per_selling_trip = 12
refuel_below = 40
metals_per_purchase = 20
cash_floor = 300
credits_per_game_hour = 10

local GAME_HOUR = 60
local FUEL_FLOOR = 15
local FUEL_TOP_UP = 30
local LEAST_FUEL_TO_MAKE = 10
local HIGHEST_METAL_PRICE = 30
local CREDITS_FOR_TWO_METALS = 60
local METALS_PER_ROD = 2
local RODS_SOLD_AN_HOUR = 4
local HOURS_SELLING_AT_ONE_PLACE = 6
local REFUEL_AT_MARKET_BELOW = 60
local HOURS_TO_WAIT_FOR_DELIVERY = 6
local COPY_AT_CREDITS = 7000
local FUEL_TO_LAUNCH = 90
local LEAST_FUEL_TO_LAUNCH = 80
local FUEL_AFTER_LAUNCH = 60
local FUEL_FOR_COPY = 40

function refuel()
  if units(fuel) < refuel_below then buy_here(FUEL_TOP_UP, fuel); wait(GAME_HOUR) end
end

function spare() return math.max(credits() - cash_floor, 0) end

function cheapest_metals_after_trip()
  local best = where_to_buy(metals_a1, metals_per_purchase, credits_per_game_hour)[1]
  return best and best.place
end

function best_rod_market()
  local best = where_to_sell(rods, math.max(units(rods), 1), credits_per_game_hour)[1]
  if best and best.net > 0 then return best.place end
  local house_best, most = nil, 0
  for _, bid in ipairs(house_demand(rods)) do
    if bid.price > most and bid.left > 0 then house_best, most = bid.place, bid.price end
  end
  return house_best
end

function stock_up()
  if units(metals_a1) >= METALS_PER_ROD then return end
  if buy_price(metals_a1) == 0 then
    local place = cheapest_metals_after_trip()
    if place then go(place) end
  end
  local amount = math.min(metals_per_purchase, spare() // HIGHEST_METAL_PRICE)
  if amount > 0 then buy_here(amount, metals_a1); wait(GAME_HOUR) end
end

-- Out of metals and money, it sells whatever rods it has rather than wait for a full load for ever
function stuck()
  return units(metals_a1) < METALS_PER_ROD and spare() < CREDITS_FOR_TWO_METALS and units(rods) > 0
end

function forge_rods()
  while units(metals_a1) >= METALS_PER_ROD and units(fuel) > LEAST_FUEL_TO_MAKE do
    if make(alloys_a1) ~= 0 then return end
    if make(rods) ~= 0 then return end
  end
end

-- Buyers bid less as they stock up, and bid again later
function sell_rods()
  for _ = 1, HOURS_SELLING_AT_ONE_PLACE do
    if units(rods) == 0 then return end
    sell_here(math.min(units(rods), RODS_SOLD_AN_HOUR), rods)
    wait(GAME_HOUR)
  end
end

function get_one(resource)
  if units(resource) > 0 then return end
  buy_here(1, resource)
  for _ = 1, HOURS_TO_WAIT_FOR_DELIVERY do
    if units(resource) > 0 then return end
    wait(GAME_HOUR)
  end
end

function equip_clone()
  local clones = my_clones()
  local newest = clones[#clones]
  if not newest then return end
  push(1, factory_kit, newest.drone)
  push(FUEL_FOR_COPY, fuel, newest.drone)
end

-- Waits for the fuel to arrive, not for n: the pull burns a unit, so the tank can end just short
function fill_to(n)
  local had = units(fuel)
  if n <= had then return end
  buy_here(n - had, fuel)
  for _ = 1, HOURS_TO_WAIT_FOR_DELIVERY do
    if units(fuel) > had then return end
    wait(GAME_HOUR)
  end
end

-- Refills after equipping a copy: parents that left with 20 fuel were lost
function reinvest()
  if units(factory_kit) > 0 then equip_clone(); fill_to(FUEL_AFTER_LAUNCH); return end
  if units(cruiser_kit) == 0 then
    if credits() < COPY_AT_CREDITS or fleet_full() then return end
    get_one(cruiser_kit)
    if units(cruiser_kit) == 0 then return end
  end
  fill_to(FUEL_TO_LAUNCH)
  if units(fuel) < LEAST_FUEL_TO_LAUNCH then return end
  if launch(me(), cruiser_kit) ~= 0 then return end
  get_one(factory_kit)
  if units(factory_kit) > 0 then equip_clone() end
  fill_to(FUEL_AFTER_LAUNCH)
end

function haul()
  local market = best_rod_market()
  if not market then wait(2 * GAME_HOUR); return end
  go(market)
  if units(fuel) < REFUEL_AT_MARKET_BELOW then buy_here(FUEL_TOP_UP, fuel); wait(2 * GAME_HOUR) end
  sell_rods()
  reinvest()
  local metals_place = cheapest_metals_after_trip()
  if metals_place then go(metals_place) end
end

function work()
  refuel()
  stock_up()
  forge_rods()
  if units(rods) >= rods_per_selling_trip or stuck() then haul() end
end

function launched()
  fuel_floor(FUEL_FLOOR)
  equip(factory_kit)
  local metals_place = cheapest_metals_after_trip()
  if metals_place then go(metals_place) end
  while true do work() end
end
