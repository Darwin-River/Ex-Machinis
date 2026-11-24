-- Chemist: a company design for Ex Machinis, in Lua. It produces polymers
-- with the Factory Kit: one Organics (A1) and one Inorganics (A1) make a
-- polymer. It brews its own fuel from the same goods and offers what it
-- doesn't need to other drones cheaply, since miners can't brew. It names
-- no place: it works wherever a polymer's margin is best, counting the
-- trip, and drones on this code split the good places by drone number.

polymers = 2051

refuel_below = 40
fuel_kept = 60
fuel_ask = 4                       -- credits a unit, to other drones
goods_per_purchase = 6
credits_kept = 300
hours_per_stay = 120               -- game hours it expects to work a place
copy_at_credits = 7000

local GAME_HOUR = 60
local POLYMERS_SOLD_AN_HOUR = 15
local FUEL_TO_LAUNCH = 90
local LEAST_FUEL_TO_LAUNCH = 80
local FUEL_AFTER_LAUNCH = 60
local FUEL_FOR_COPY = 40
local HOURS_TO_WAIT_FOR_DELIVERY = 6
local UNREACHABLE = 999

-- Globals survive a restart, and a copy inherits them
workplace, started = 0, 0

function hours_to(place)
  if place == location() then return 0 end
  local trip = trip_to(place)
  return trip and trip.hours or UNREACHABLE
end

function house_price(offers, place)
  for _, offer in ipairs(offers) do
    if offer.place == place and offer.price > 0 then return offer.price end
  end
  return nil
end

function polymer_margins()
  local organics, inorganics = house_supply(organics_a1), house_supply(inorganics_a1)
  local margins = {}
  for _, bid in ipairs(house_demand(polymers)) do
    local o, i = house_price(organics, bid.place), house_price(inorganics, bid.place)
    local hours = hours_to(bid.place)
    if o and i and bid.price > o + i and hours ~= UNREACHABLE then
      margins[bid.place] = (bid.price - o - i) * hours_per_stay / (hours_per_stay + hours)
    end
  end
  return margins
end

-- Drones on this code split the good places rather than all taking the best
function choose_workplace()
  local margins, top = polymer_margins(), 0
  for _, margin in pairs(margins) do top = math.max(top, margin) end
  local good = {}
  for place, margin in pairs(margins) do
    if margin * 10 >= top * 9 then table.insert(good, place) end
  end
  if #good == 0 then return location() end
  table.sort(good)
  return good[me() % #good + 1]
end

function stock_up()
  if credits() < credits_kept then return end
  if units(organics_a1) < 2 then buy_here(goods_per_purchase, organics_a1) end
  if units(inorganics_a1) < 3 then buy_here(goods_per_purchase, inorganics_a1) end
  wait(GAME_HOUR)
end

-- Fuel first, keeping enough goods for a brew
function brew_fuel_then_polymers()
  if units(fuel) < refuel_below then make(fuel) end
  while units(organics_a1) > 1 and units(inorganics_a1) > 2 do
    if make(polymers) ~= 0 then return end
  end
end

-- Buyers bid less as they stock up
function sell_polymers()
  local n = math.min(units(polymers), POLYMERS_SOLD_AN_HOUR)
  if n > 0 then sell_here(n, polymers) end
end

function buy_one(good)
  if units(good) > 0 then return end
  buy_here(1, good)
  for _ = 1, HOURS_TO_WAIT_FOR_DELIVERY do
    if units(good) > 0 then return end
    wait(GAME_HOUR)
  end
end

function equip_newest_copy()
  local copies = my_clones()
  local newest = copies[#copies]
  if not newest then return end
  push(1, factory_kit, newest.drone)
  push(FUEL_FOR_COPY, fuel, newest.drone)
end

function fill_tank_to(target)
  local had = units(fuel)
  if target <= had then return end
  buy_here(target - had, fuel)
  for _ = 1, HOURS_TO_WAIT_FOR_DELIVERY do
    if units(fuel) > had then return end
    wait(GAME_HOUR)
  end
end

function have_a_hull()
  if units(cruiser_kit) > 0 then return true end
  if credits() < copy_at_credits or fleet_full() then return false end
  buy_one(cruiser_kit)
  return units(cruiser_kit) > 0
end

function grow()
  if units(factory_kit) > 0 then equip_newest_copy(); fill_tank_to(FUEL_AFTER_LAUNCH); return end
  if not have_a_hull() then return end
  fill_tank_to(FUEL_TO_LAUNCH)
  if units(fuel) < LEAST_FUEL_TO_LAUNCH or launch(me(), cruiser_kit) ~= 0 then return end
  buy_one(factory_kit)
  if units(factory_kit) > 0 then equip_newest_copy() end
  fill_tank_to(FUEL_AFTER_LAUNCH)
end

function work()
  stock_up()
  brew_fuel_then_polymers()
  sell_polymers()
  grow()
end

-- A copy inherits its parent's started, so it chooses a place of its own
function launched()
  fuel_floor(15)
  equip(factory_kit)
  if started ~= me() then workplace, started = choose_workplace(), me() end
  go(workplace)
  sell_order(fuel_ask, fuel_kept, fuel)
  while true do work() end
end
