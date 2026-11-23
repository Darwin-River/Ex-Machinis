-- Hauler: a company that fetches a far-away good (#932). Metals (A3) come
-- only from minerals found far from Earth, the Moon and Mars, and no
-- exchange sells them, but every exchange buys them (about 80 a unit, Mars
-- paying more). So a drone that goes out, mines, and hauls them back has a
-- market nearly to itself. Round after round it:
--   1. finds this season's best site for kunspar (best_sites: the homes move
--      every season), weighing what a run yields there against the trip;
--   2. fills its tank for the whole voyage: nobody sells fuel out there, so
--      it carries fuel for the trip out, the mining, refining and the trip
--      back, with some to spare;
--   3. mines and refines kunspar until it holds `loads` more Metals (A3)
--      (each refining run makes 1 Metals (A3), 2 Metals (A2) and 1 Metals
--      (A1) from 20 kunspar);
--   4. flies back to whichever of Earth, the Moon and Mars bids most for
--      Metals (A3) (house_demand), and sells everything there into the best
--      bids (best_bid, sell_here).
-- It uses the Mining Kit, whose Worm mines moons, so kunspar (found on the
-- moons of the outer planets). Voxite and zenolite need a Slug, halosite
-- and neptine a Crawler: a hauler for those buys that module first.

-- Settings
loads = 25          -- Metals (A3) it brings back each voyage (one per refining run)
max_trip_hours = 600   -- farthest site it will fly to (game hours, one way)
min_a3_bid = 50     -- least bid for Metals (A3) it sells into; it waits up to a day for one
spare_fuel = 30     -- fuel it keeps above what the voyage needs
hour_value = 2      -- credits an hour of its time is worth, to weigh near against rich

local ORE_PER_RUN = 20         -- kunspar for one refining run
local TRIP_FUEL = 5            -- fuel a trip, however far
local markets = {earth, moon, mars}
local goods = {metals_a3, metals_a2, metals_a1}

-- Saved state
target = 0         -- Metals (A3) aboard at which it heads home
site = 0           -- where it mines this voyage

-- --- Planning --------------------------------------------------------------

-- Fuel for a whole voyage: two trips, and per refining run the mining runs
-- it takes (1 fuel each) and the refining (2)
function fuel_for_voyage(yield)
  local runs = (ORE_PER_RUN + yield - 1) // yield
  return 2 * TRIP_FUEL + loads * (runs + 2) + spare_fuel
end

-- The game hours a voyage to a site takes, there and back included
function voyage_hours(s)
  local runs = (ORE_PER_RUN + s.yield - 1) // s.yield
  return 2 * trip(s.place) + loads * (runs + 2)
end

-- Of this season's best kunspar sites within reach, the one where a voyage
-- takes the fewest hours (so brings in most an hour)
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

-- The exchange near Earth whose planets bid most for Metals (A3), less the
-- trip there (an hour old elsewhere; nil when none bids)
function best_market()
  local best, most = nil, 0
  for _, d in ipairs(house_demand(metals_a3)) do
    for _, m in ipairs(markets) do
      local hours = trip(m)
      local worth = d.price * math.min(d.left, units(metals_a3)) - math.max(hours, 0) * hour_value
      if d.place == m and d.price > 0 and d.left > 0 and worth > most then best, most = m, worth end
    end
  end
  return best
end

-- --- The voyage ------------------------------------------------------------

-- Fills the tank here to n, waiting for the fuel to come aboard
function fill_to(n)
  if units(fuel) >= n then return end
  buy_here(n - units(fuel), fuel)
  wait(60)
end

function set_out()
  local s = choose_site()
  if not s then set_status("no kunspar within " .. max_trip_hours .. " game hours: waiting"); wait(720); return end
  local need = fuel_for_voyage(s.yield)
  fill_to(need)
  if units(fuel) < need then set_status("can't buy " .. need .. " fuel here yet"); wait(240); return end
  site, target = s.place, units(metals_a3) + loads
  set_status("off to " .. place_name(site) .. " for kunspar (" .. s.yield .. " a run, " .. trip(site) .. " hours away)")
  go(site)
end

-- Mines and refines until target, keeping fuel for the trip home
function mine_out_there()
  while units(metals_a3) < target and units(fuel) > TRIP_FUEL + 10 do
    if units(kunspar) >= ORE_PER_RUN then
      refine(kunspar)
    elseif mine(kunspar) ~= 0 then
      wait(60)   -- refused (crowded, or at the fuel floor): give it an hour
    end
  end
end

function sell_home()
  local m = best_market() or mars
  set_status("hauling " .. units(metals_a3) .. " Metals (A3) to " .. place_name(m))
  go(m)
  for _ = 1, 24 do   -- up to a game day for a good bid
    local price = best_bid(metals_a3)
    if price >= min_a3_bid then break end
    wait(60)
  end
  for _, r in ipairs(goods) do sell_all(r) end
  print("sold at " .. place_name(m) .. "; credits " .. credits()); report()
end

-- launched() runs again after a restart: out there with room to fill, it
-- goes on mining; with metals aboard it heads home; else it sets out
function launched()
  fuel_floor(TRIP_FUEL + 5)   -- mining and refining leave fuel for the trip home
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
