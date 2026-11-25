-- Hauler: it produces Metals (A3), which come only from minerals far from
-- the inner planets and which every exchange buys but none sells (#932).
-- Nobody sells fuel out there, so it fills its tank for the whole voyage to
-- this season's best kunspar site before it sets out.

loads = 25                 -- Metals (A3) a voyage, one a refining run
max_trip_hours = 600       -- one way
least_bid_for_a3 = 50      -- it waits up to a game day for one
spare_fuel = 30
hour_value = 2             -- credits a game hour of its time is worth, near against rich

local ORE_PER_RUN = 20
local TRIP_FUEL = 5
local FUEL_KEPT_FOR_HOME = TRIP_FUEL + 10
local REFINING_FUEL = 2
local GAME_HOUR = 60
local HOURS_TO_WAIT_FOR_A_BID = 24
local goods = {metals_a3, metals_a2, metals_a1}

target = 0
site = 0

-- Planning

-- Fuel for a whole voyage: two trips, and per refining run the mining runs
-- it takes (1 fuel each) and the refining (2)
function fuel_for_voyage(yield)
  local runs = (ORE_PER_RUN + yield - 1) // yield
  return 2 * TRIP_FUEL + loads * (runs + 2) + spare_fuel
end

function voyage_hours(s)
  local runs = (ORE_PER_RUN + s.yield - 1) // s.yield
  return 2 * trip(s.place) + loads * (runs + REFINING_FUEL)
end

function choose_site()
  local best, fewest = nil, math.huge
  for _, s in ipairs(best_sites(kunspar)) do
    local hours = trip(s.place)
    if s.yield > 0 and hours >= 0 and hours <= max_trip_hours and voyage_hours(s) < fewest then
      best, fewest = s, voyage_hours(s)
    end
  end
  return best
end

function best_market()
  local best, most = nil, 0
  for _, bid in ipairs(house_demand(metals_a3)) do
    local hours = trip(bid.place)
    local worth = bid.price * math.min(bid.left, units(metals_a3)) - math.max(hours, 0) * hour_value
    if bid.price > 0 and bid.left > 0 and hours >= 0 and worth > most then best, most = bid.place, worth end
  end
  return best
end

function nearest_market()
  local nearest, fewest = location(), math.huge
  for _, bid in ipairs(house_demand(metals_a3)) do
    local hours = trip(bid.place)
    if hours >= 0 and hours < fewest then nearest, fewest = bid.place, hours end
  end
  return nearest
end

-- The voyage

function fill_tank_to(n)
  if units(fuel) >= n then return end
  buy_here(n - units(fuel), fuel)
  wait(GAME_HOUR)
end

function set_out()
  local s = choose_site()
  if not s then set_status("no kunspar within " .. max_trip_hours .. " game hours: waiting"); wait(12 * GAME_HOUR); return end
  local need = fuel_for_voyage(s.yield)
  fill_tank_to(need)
  if units(fuel) < need then set_status("can't buy " .. need .. " fuel here yet"); wait(4 * GAME_HOUR); return end
  site, target = s.place, units(metals_a3) + loads
  set_status("off to " .. place_name(site) .. " for kunspar (" .. s.yield .. " a run, " .. trip(site) .. " hours away)")
  go(site)
end

function mine_out_there()
  while units(metals_a3) < target and units(fuel) > FUEL_KEPT_FOR_HOME do
    if units(kunspar) >= ORE_PER_RUN then
      refine(kunspar)
    elseif mine(kunspar) ~= 0 then
      wait(GAME_HOUR)
    end
  end
end

function wait_for_a_good_bid()
  for _ = 1, HOURS_TO_WAIT_FOR_A_BID do
    if best_bid(metals_a3) >= least_bid_for_a3 then return end
    wait(GAME_HOUR)
  end
end

function sell_home()
  local market = best_market() or nearest_market()
  set_status("hauling " .. units(metals_a3) .. " Metals (A3) to " .. place_name(market))
  go(market)
  wait_for_a_good_bid()
  for _, r in ipairs(goods) do sell_all(r) end
  print("sold at " .. place_name(market) .. "; credits " .. credits()); report()
end

-- launched() runs again after a restart: out there with room to fill, it
-- goes on mining; with metals aboard it heads home; else it sets out
function launched()
  fuel_floor(FUEL_KEPT_FOR_HOME - 5)
  equip(mining_kit)
  while true do
    if site ~= 0 and location() == site and units(metals_a3) < target then
      mine_out_there()
    elseif units(metals_a3) > 0 then
      sell_home()
      site = 0
    else
      set_out()
    end
  end
end
