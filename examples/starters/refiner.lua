-- Refiner: a starter script for Ex Machinis, in Lua. Its objective is the
-- goods factories need to brew fuel and make polymers: it equips itself
-- with the Mining Kit (Engine, Worm and Refinery), mines yalspar and
-- vladnium and refines them into Organics (A1), Inorganics (A1) and (A2),
-- and Metals (A1) and (A2). It keeps standing sell orders for its goods,
-- sells what nobody bought wherever the House pays most, and buys its fuel.
-- It names no place: it asks the game where its ores yield most an hour
-- now, counting the trip (a crowded site yields less). Drones on this code
-- split the nearly-as-good sites by drone number, so they spread out. It
-- moves only for a clear gain, and when something fails it chooses again.

-- Settings: change these numbers to make it your own. It matures as it is;
-- the three marked TRY FIRST are the ones to change first
low_fuel = 40      -- TRY FIRST: fuel units below which it buys more (higher: safer, but more credits sit in its tank)
stay_hours = 120   -- game hours a stay at a site lasts, over which the trip there is shared
move_margin = 25   -- percent more an hour another site must pay before it moves there
spread = 10        -- percent below the best at which a site still counts as good
look_every = 6     -- rounds of work between looks for a better site
move_below = 3     -- units a run of yalspar yields here below which it looks at once (0: only now and then)
dry_tries = 6      -- dry tries in a row before it tries elsewhere
grow_at = 7000     -- credits at which a matured company launches a copy
                   -- (at least 6500: a hull 3000, a kit 2000, 1500 to work with)
work_cash = 1500   -- credits kept for each drone the company will have after a launch
grow_until = 0     -- game day after which it launches no copies (0: no limit);
                   -- a copy takes about 20 game days to pay back
rest = 240         -- game minutes to rest when nowhere else is better
hold_cap = 48      -- TRY FIRST: refined goods it keeps on offer before a selling trip (fewer: sells sooner)
min_ask = 12       -- least asking price (credits) where nobody bids
hour_value = 4     -- TRY FIRST: credits a game hour of its time is worth (lower: sells further away)

-- Fixed numbers the plan uses
local HOUR = 60                    -- game minutes
local FUEL_FLOOR = 15              -- fuel actions leave in the tank, to buy more
local CASH_FOR_FUEL = 150          -- credits raised before buying fuel
local FUEL_TO_BUY = 30             -- units bought when low
local FUEL_BID_PRICE = 7           -- credits a unit, bid where nobody sells fuel
local FUEL_BID_UNITS = 60
local FUEL_TO_MOVE = 60           -- fuel it needs before it moves to another site
local FULL_TANK = 150
local LOW_DEPOSIT = 5              -- mining runs left at which a deposit is dry
local DELIVERY_HOURS = 6           -- game hours it waits for a purchase to arrive
local COPY_COST = 5000             -- a hull and a kit
local FUEL_FOR_COPY = 40
local TANK_TO_LAUNCH, FUEL_NEEDED_TO_LAUNCH = 90, 80
local TANK_AFTER_LAUNCH = 60
local TANK_AFTER_SELLING = 100
local REPORT_EVERY = 4             -- work rounds between reports home
local TRIP_FUEL_CREDITS = 30       -- a flight's fuel, in credits (10 fuel at about 3)
local NO_TRIP = 999                -- game hours for a place it can't find a trip to

-- Its objective: the ores it mines (and how many before refining), and the goods
local ores = {yalspar, vladnium}
local loads = {[yalspar] = 16, [vladnium] = 12}
local goods_sold = {metals_a1, metals_a2, organics_a1, inorganics_a1, inorganics_a2}

-- Where it works (0: not chosen); dry tries in a row; is it a copy
site, dry_count, copy = 0, 0, false

-- Where to work: answers from the game, not a list of places

function hours_to(place)
  if place == location() then return 0 end
  local trip = trip_to(place)
  return trip and trip.hours or NO_TRIP
end

-- Units an hour over a stay: what its ores yield a run, less the trip there
function site_rate(place, yield)
  local hours = hours_to(place)
  if hours == NO_TRIP then return 0 end   -- no way there: not a site
  return yield * stay_hours / (stay_hours + hours)
end

-- What a run of each of its ores yields at a place now, added up
function yield_at(place)
  local total = 0
  for _, ore in ipairs(ores) do total = total + mine_yield(ore, place) end
  return total
end

-- Where its ores are found, rated by what they yield together. Of those
-- within spread percent of the best, the one its drone number picks (drones
-- on this code spread out), and its rate; nil when none. except: a place to
-- leave out
function choose_site(except)
  local found, rated, top = {}, {}, 0
  for _, ore in ipairs(ores) do
    for _, s in ipairs(best_sites(ore)) do found[s.place] = (found[s.place] or 0) + s.yield end
  end
  for place, yield in pairs(found) do
    if place ~= except then
      rated[place] = site_rate(place, yield)
      top = math.max(top, rated[place])
    end
  end
  local good = {}
  for place, rate in pairs(rated) do
    if rate * 100 >= top * (100 - spread) then table.insert(good, place) end
  end
  if #good == 0 or top == 0 then return nil, 0 end
  table.sort(good)
  local pick = good[me() % #good + 1]
  return pick, rated[pick]
end

function settle(place)
  if not place or place == site then return end
  site, dry_count = place, 0
  set_status("moving to " .. place_name(place) .. ": it pays most there now")
  if go(place) ~= 0 then site = location() end   -- couldn't fly: work here and look again later
end

-- Moves only when another site pays move_margin percent more an hour
function look_around()
  if units(fuel) < FUEL_TO_MOVE then return end
  local place, rate = choose_site()
  if place and rate * 100 > site_rate(site, yield_at(site)) * (100 + move_margin) then settle(place) end
end

-- Where the House sells a tankful for least, counting the trip; nil if nowhere
function fuel_stop()
  local best, least = nil, math.huge
  for _, offer in ipairs(house_supply(fuel)) do
    if offer.price > 0 and offer.on_sale >= FUEL_TO_BUY then
      local cost = offer.price * FULL_TANK + hours_to(offer.place) * hour_value
      if cost < least then best, least = offer.place, cost end
    end
  end
  return best
end

-- Halfway between the best bid and the cheapest other seller. With no bid,
-- the cheapest seller's price, never under min_ask (undercutting a unit
-- each drove prices down to 2); with no seller, half as much again as the
-- best bid; 0 where nobody trades it
function asking_price(good)
  local best_bid, cheapest_offer = sell_price(good), buy_price(good)
  if best_bid == 0 then
    return cheapest_offer > 0 and math.max(cheapest_offer, min_ask) or 0
  end
  if cheapest_offer == 0 then return best_bid + best_bid // 2 end
  return (best_bid + cheapest_offer) // 2
end

-- An unchanged order costs no fuel to post again
function post_sell_orders()
  if house_only() then return end   -- a startup sells straight to the House
  for _, good in ipairs(goods_sold) do
    local price = asking_price(good)
    if price > 0 then sell_order_if_changed(price, 0, good) end
  end
end

function goods_aboard()
  local total = 0
  for _, good in ipairs(goods_sold) do total = total + units(good) end
  return total
end

function sell_out(good)
  local aboard = units(good)
  if aboard > 0 then sell_here(aboard, good) end
end

-- Buy here; failing that, bid for fuel (a courier may fill the bid); failing
-- that, fetch it from where it's cheapest. The bid is withdrawn either way
function refuel()
  if units(fuel) >= low_fuel then return end
  set_status("low on fuel (" .. units(fuel) .. "): buying some here")
  raise_cash(CASH_FOR_FUEL); buy_here(FUEL_TO_BUY, fuel)
  wait(HOUR)
  if units(fuel) >= low_fuel then return end
  set_status("no fuel on sale here: bidding for some")
  buy_order(FUEL_BID_PRICE, FUEL_BID_UNITS, fuel)
  wait(2 * HOUR)
  local stop = units(fuel) < low_fuel and fuel_stop()
  if stop then
    set_status("still low on fuel: going to " .. place_name(stop) .. " for some")
    go(stop)
    raise_cash(CASH_FOR_FUEL); buy_here(FUEL_TO_BUY, fuel)
    wait(2 * HOUR)
    go(site)
  end
  buy_order(1, 0, fuel)   -- wants 0: withdraws the bid
end

-- Waiting is free and a trip costs fuel: it waits a while, then tries the
-- next best site, or rests when there is none. Refuel first, or it could
-- rest at a dry site with too little fuel to leave
function move_on_from_dry_site()
  if units(fuel) < low_fuel then refuel(); return end
  dry_count = dry_count + 1
  if dry_count < dry_tries or units(fuel) < FUEL_TO_MOVE then
    set_status("dry here: waiting for it to refill (" .. dry_count .. " of " .. dry_tries .. " tries)")
    wait(HOUR)
    return
  end
  local other = choose_site(site)
  if other then settle(other); return end
  set_status("dry here and nowhere better: resting " .. rest .. " game minutes")
  dry_count = 0
  wait(rest)
end

-- A run that brings nothing means others took the ore first
function dig(mineral, wanted)
  while units(mineral) < wanted do
    if deposit(mineral) < LOW_DEPOSIT then
      move_on_from_dry_site()
    else
      local before = units(mineral)
      mine(mineral)
      if units(mineral) == before then move_on_from_dry_site() else dry_count = 0 end
    end
  end
end

-- Waits for the fuel to arrive, not to reach the target: computing burns a
-- unit or two after delivery
function fill_to(target)
  local had = units(fuel)
  local missing = target - had
  if missing <= 0 then return end
  raise_cash(CASH_FOR_FUEL); buy_here(missing, fuel)
  for _ = 1, DELIVERY_HOURS do
    if units(fuel) > had then return end
    wait(HOUR)
  end
end

function get_one(good)
  if units(good) > 0 then return end
  buy_here(1, good)
  for _ = 1, DELIVERY_HOURS do
    if units(good) > 0 then return end
    wait(HOUR)
  end
end

function equip_clone()
  local clones = my_clones()
  local newest = clones[#clones]
  if not newest then return end
  push(1, mining_kit, newest.drone)
  push(FUEL_FOR_COPY, fuel, newest.drone)
end

-- Credits the company needs before launching a copy
function grow_line()
  local health = company_health()
  local drones = health and health.working or 1
  return math.max(grow_at, COPY_COST + work_cash * (drones + 1))
end

-- Kits that can never launch are credits tied up for good, so a full
-- fleet sells them, once a game day
kits_sold_on_day = -1
function sell_spare_kits(kits)
  if today() == kits_sold_on_day then return end
  kits_sold_on_day = today()
  for _, kit in ipairs(kits) do
    if units(kit) > 0 then
      set_status("the fleet is full: selling " .. units(kit) .. " spare kits")
      sell_here(units(kit), kit)
    end
  end
end

function past_grow_until_with_no_kit()
  return grow_until > 0 and today() > grow_until and units(cruiser_kit) == 0 and units(mining_kit) == 0
end

-- A kit still aboard from a failed hand-over goes to the copy first; a
-- hull already bought launches whenever the fuel allows
function reinvest()
  if house_only() then return end   -- a startup keeps its credits for its debt
  if fleet_full() then sell_spare_kits({cruiser_kit, mining_kit}); return end
  if past_grow_until_with_no_kit() then return end
  if units(mining_kit) > 0 then equip_clone(); fill_to(TANK_AFTER_LAUNCH); return end
  if units(cruiser_kit) == 0 then
    if credits() < grow_line() then return end
    if fleet_full() then return end
    get_one(cruiser_kit)
    if units(cruiser_kit) == 0 then return end
  end
  fill_to(TANK_TO_LAUNCH)
  if units(fuel) < FUEL_NEEDED_TO_LAUNCH then return end
  copy = true   -- the copy inherits this, so it knows it is one
  set_status("launching a copy")
  if launch(me(), cruiser_kit) ~= 0 then return end
  get_one(mining_kit)
  if units(mining_kit) > 0 then equip_clone() end
  fill_to(TANK_AFTER_LAUNCH)
end

-- Credits the House at each place bids for the goods aboard, as many as it still takes today
function house_value_of_goods()
  local value = {}
  for _, good in ipairs(goods_sold) do
    local aboard = units(good)
    if aboard > 0 then
      for _, bid in ipairs(house_demand(good)) do
        if bid.price > 0 and bid.left > 0 then
          value[bid.place] = (value[bid.place] or 0) + bid.price * math.min(aboard, bid.left)
        end
      end
    end
  end
  return value
end

-- A sale's value less the flight there and back to the work site
function value_after_round_trip(place, value)
  if place ~= location() then value = value - TRIP_FUEL_CREDITS - hours_to(place) * hour_value end
  if place ~= site then
    local back = trip_between(place, site)
    value = value - (back and back.hours or 0) * hour_value
  end
  return value
end

-- The exchange where the goods aboard fetch most after the trip, of all
-- the House bids at (a new one counts too); nil if none comes out ahead
function best_market()
  local best, best_net = nil, 0
  for place, value in pairs(house_value_of_goods()) do
    local net = value_after_round_trip(place, value)
    if net > best_net then best, best_net = place, net end
  end
  return best
end

-- The nearest exchange that bids for its goods
function nearest_market()
  local best, least = location(), math.huge
  for place in pairs(house_value_of_goods()) do
    if hours_to(place) < least then best, least = place, hours_to(place) end
  end
  return best
end

-- Until hold_cap goods are aboard, other drones may buy them here
function sell_when_hold_is_full()
  if goods_aboard() < hold_cap then return end
  local where = best_market() or nearest_market()
  set_status("selling " .. goods_aboard() .. " refined goods at " .. place_name(where))
  go(where)
  post_sell_orders()
  for _, good in ipairs(goods_sold) do sell_out(good) end
  fill_to(TANK_AFTER_SELLING)
  reinvest()
  go(site)
end

rounds_worked = 0
function report_home_now_and_then()
  rounds_worked = rounds_worked + 1
  if rounds_worked % REPORT_EVERY ~= 0 then return end
  print("credits " .. credits() .. " fuel " .. units(fuel))
  report()
end

-- Each of its ores found here, a load at a time
function mine_and_refine()
  for _, ore in ipairs(ores) do
    if mine_yield(ore) > 0 then
      set_status("mining " .. resource_name(ore) .. " at " .. place_name(site))
      dig(ore, loads[ore])
      set_status("refining " .. resource_name(ore))
      refine(ore)
    end
  end
end

function work()
  refuel()
  if rounds_worked % look_every == 0 or mine_yield(yalspar) < move_below then look_around() end
  mine_and_refine()
  sell_when_hold_is_full()
  report_home_now_and_then()
end

-- launched() may run again after a crash or restart: a drone that has
-- chosen its site keeps it. A new drone, or a copy (which inherits started
-- holding its parent's number), chooses its own
started = 0
function launched()
  fuel_floor(FUEL_FLOOR)
  equip(mining_kit)   -- a copy waits for the kit its parent hands it
  if started ~= me() then
    site = choose_site() or location()
    copy, started = true, me()
  end
  go(site)
  post_sell_orders()
  while true do work() end
end
