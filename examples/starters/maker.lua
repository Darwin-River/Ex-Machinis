-- Maker: a starter script for Ex Machinis, in Lua. It equips itself with the
-- Factory Kit, buys Metals (A1) where they cost least, makes them into alloys
-- and the alloys into rods, and sells the rods where they pay best. With
-- enough credits it launches a copy of itself and buys other companies'
-- shares. A seedling deals only with the House and launches no copies.

-- Settings: change these numbers to make it your own
rod_load = 12      -- rods it takes to Earth (4 cargo each)
low_fuel = 40      -- buy fuel below this
keep_fuel = 25     -- it stops making rods below this much fuel
trip_margin = 20   -- flies to Earth only if rods sold there for this much more
grow_at = 7000     -- credits at which it launches a copy (once matured)
work_cash = 1500   -- credits kept for each drone before a copy launches
grow_until = 0     -- game day after which no more copies (0: no limit)
batch = 20         -- metals it buys at a time
cash_floor = 300   -- credits it keeps for fuel when its tank is low
low_floor = 60     -- credits it keeps when its tank is full enough
metals_per_rod = 2 -- Metals (A1) in a rod (two make an alloy, an alloy a rod)
invest_above = 3000-- credits above this go into other companies' shares
invest_days = 30   -- pays up to this many days of a share's income
invest_cap = 1500  -- credits it puts into shares in all (0: never invests)

-- Facts of the game it relies on
local HOUR <const> = 60 -- game minutes
local FUEL_FLOOR <const> = 15 -- fuel make, mine, refine and push leave
local FUEL_TO_BUY <const> = 30 -- fuel bought at a time
local CASH_FOR_FUEL <const> = 150 -- credits raised before buying fuel
local REFUEL_AFTER_HAUL <const> = 60 -- fuel: below this it buys where it sold
local CREDITS_FOR_METALS <const> = 60 -- spare credits that buy two metals
local GUESSED_METALS_PRICE <const> = 30 -- credits a metal when no price is known
local HOUSE_CAP_SHARE <const> = 10 -- a seedling buys 1/10 of the House's stock a day,
local HOUSE_CAP_MIN <const> = 5 -- but at least this many
local HOUSE_CAP_REACHED <const> = -17 -- buy_here's result at that cap
local TRIP_FUEL_COST <const> = 30 -- credits: 10 fuel at about 3, there and back
local HOUR_AWAY_COST <const> = 10 -- credits a game hour of travel costs
local KEPT_AFTER_TAX <const> = 95 -- percent of a sale (5% sales tax)
local RODS_PER_HOUR <const> = 4 -- sold an hour: buyers bid less as they stock up
local SELLING_HOURS <const> = 6 -- hours it keeps selling at one place
local FAILS_BEFORE_PAUSE <const> = 3 -- failed makes in a row
local COPY_COST <const> = 5000 -- credits: a hull and a kit
local COPY_FUEL_BELOW <const> = 80 -- fuel: tops up below this before a launch
local COPY_FUEL_TARGET <const> = 90 -- fuel it tops up to
local FUEL_FOR_COPY <const> = 40 -- fuel handed to a new copy
local KIT_WAIT_HOURS <const> = 6 -- hours it waits for a bought kit
local MIN_SHARE_ORDER <const> = 25 -- credits: each order burns a unit of fuel
local REPORT_EVERY <const> = 4 -- rounds between reports home

-- What it remembers (a copy starts with its parent's)
house_cap_reached_on = {} -- place -> day its House cap for metals was reached
failed_makes_in_a_row = 0
spare_kits_sold_on = -1 -- game day
invested_on = -1 -- game day
invested_total = 0 -- credits put into shares
rounds_worked = 0
copy = false -- true once it launches a copy, and in the copy

-- Fuel and credits

function buy_fuel(then_wait)
  raise_cash(CASH_FOR_FUEL); buy_here(FUEL_TO_BUY, fuel)
  wait(then_wait)
end

function refuel()
  if units(fuel) < low_fuel then buy_fuel(HOUR) end
end

-- Credits it may spend: it keeps more back when its tank is low
function spare()
  local keep = units(fuel) < low_fuel and cash_floor or low_floor
  return math.max(credits() - keep, 0)
end

function after_sales_tax(amount)
  return amount * KEPT_AFTER_TAX // 100
end

-- A round trip's fuel and hours in credits (0 here)
function round_trip_cost(place)
  if place == location() then return 0 end
  local trip = trip_to(place)
  local hours_there_and_back = trip and trip.hours * 2 or 0
  return TRIP_FUEL_COST + hours_there_and_back * HOUR_AWAY_COST
end

-- What a rod made now will fetch at least: the best House bid, after tax
function best_rod_bid()
  local best = 0
  for _, bid in ipairs(house_demand(rods)) do
    if bid.price > 0 and bid.left > 0 then best = math.max(best, after_sales_tax(bid.price)) end
  end
  return best
end

-- Buying metals

function house_lets_me_buy(offer)
  local daily_cap = math.max(HOUSE_CAP_MIN, offer.on_sale // HOUSE_CAP_SHARE)
  return math.min(batch, offer.on_sale, daily_cap)
end

-- The House exchange where a metal costs least, with the trip shared over
-- what it may buy there: place, cost each, price; nil when none sells any
function cheapest_house_metals()
  local best_place, best_cost, best_price = nil, math.huge, 0
  for _, offer in ipairs(house_supply(metals_a1)) do
    local can_buy = house_lets_me_buy(offer)
    if offer.price > 0 and can_buy >= metals_per_rod and house_cap_reached_on[offer.place] ~= today() then
      local cost = offer.price + round_trip_cost(offer.place) / can_buy
      if cost < best_cost then best_place, best_cost, best_price = offer.place, cost, offer.price end
    end
  end
  return best_place, best_cost, best_price
end

-- A seedling goes where metals are cheapest: their price there, or nil
-- after waiting when none are on sale or a rod wouldn't pay for them
function go_to_cheapest_house_metals()
  local place, cost, price = cheapest_house_metals()
  if not place then
    set_status("no metals on sale from the House: waiting for tomorrow's")
    wait(4 * HOUR)
    return nil
  end
  if cost * metals_per_rod >= best_rod_bid() then
    set_status("a rod needs " .. metals_per_rod .. " metals at about " .. math.floor(cost) .. " each (with the trip), and pays "
      .. best_rod_bid() .. " at best: waiting for a better rod bid")
    wait(4 * HOUR)
    return nil
  end
  if place ~= location() then
    set_status("buying metals at " .. place_name(place))
    go(place)
  end
  return price
end

function buy_metals_here(price)
  if price <= 0 then price = GUESSED_METALS_PRICE end
  local amount = math.min(batch, spare() // price)
  if amount > 0 then
    if buy_here(amount, metals_a1) == HOUSE_CAP_REACHED then house_cap_reached_on[location()] = today() end
    wait(HOUR)
  end
end

function buy_metals()
  if units(metals_a1) >= metals_per_rod then return end
  if spare() < CREDITS_FOR_METALS then
    set_status("short of credits for metals")
    wait(2 * HOUR)
    return
  end
  local price = buy_price(metals_a1)
  if house_only() then
    price = go_to_cheapest_house_metals()
    if not price then return end
  end
  buy_metals_here(price)
end

-- Making rods

-- Metals into an alloy, the alloy into a rod: 0, or the failure code
function make_rod()
  local result = make(alloys_a1)
  if result == 0 then result = make(rods) end
  return result
end

function note_failed_make(code)
  failed_makes_in_a_row = failed_makes_in_a_row + 1
  if failed_makes_in_a_row >= FAILS_BEFORE_PAUSE then
    set_status("making failed " .. failed_makes_in_a_row .. " times in a row (" .. code .. "): waiting 4 game hours")
    wait(4 * HOUR)
  end
end

function make_rods()
  while units(metals_a1) >= metals_per_rod and units(fuel) > keep_fuel do
    local result = make_rod()
    if result ~= 0 then note_failed_make(result); return end
    failed_makes_in_a_row = 0
  end
end

-- Selling rods

-- A few an hour: buyers bid less as they stock up, and bid again later
function sell_rods_here()
  for _ = 1, SELLING_HOURS do
    if units(rods) == 0 then return end
    sell_here(math.min(units(rods), RODS_PER_HOUR), rods)
    wait(HOUR)
  end
end

-- Earth's best rod bid an hour ago, or yesterday's trade price if higher
function earth_rod_price()
  local quote = quotes(rods)[earth]
  return math.max(quote and quote.bid or 0, board_price(rods, earth))
end

-- Yes when the bid here, plus trip_margin, matches what Earth pays
function rods_sell_best_here()
  if house_only() then return house_rods() == location() end
  local bid_here = sell_price(rods)
  local earth_pays = earth_rod_price()
  return bid_here > 0 and bid_here + trip_margin >= earth_pays
end

-- For a seedling: where the House pays most for the rods aboard, after tax
-- and the trip; nil when no bid comes out ahead
function house_rods()
  local aboard, best_place, best_net = units(rods), nil, 0
  for _, bid in ipairs(house_demand(rods)) do
    local sold = math.min(aboard, bid.left)
    if bid.price > 0 and sold > 0 then
      local net = after_sales_tax(bid.price * sold) - round_trip_cost(bid.place)
      if net > best_net then best_place, best_net = bid.place, net end
    end
  end
  return best_place
end

-- Earth, unless another place pays more after the trip (nil: a seedling
-- whose rods no House bid pays the trip for)
function market_for_rods()
  if house_only() then return house_rods() end
  local best = where_to_sell(rods, math.max(units(rods), 1))[1]
  if best and best.place ~= moon and best.net > 0 then return best.place end
  return earth
end

function stuck_with_rods()
  return units(metals_a1) < metals_per_rod and spare() < CREDITS_FOR_METALS and units(rods) > 0
end

function haul_rods()
  local market = market_for_rods()
  if not market then
    set_status("no House bid for " .. units(rods) .. " rods pays the trip: waiting")
    wait(2 * HOUR)
    return
  end
  set_status("hauling " .. units(rods) .. " rods to " .. place_name(market))
  local result = go(market)
  if result ~= 0 then
    set_status("couldn't fly to " .. place_name(market) .. " (" .. result .. "): selling here if anyone buys, then waiting")
    sell_rods_here()
    wait(2 * HOUR)
    return
  end
  if units(fuel) < REFUEL_AFTER_HAUL then buy_fuel(2 * HOUR) end
  sell_rods_here()
  if not house_only() then go(moon) end   -- a seedling picks its next exchange in buy_metals
end

function sell_or_haul_rods()
  if units(rods) > 0 and rods_sell_best_here() then
    sell_rods_here()
  elseif units(rods) >= rod_load or stuck_with_rods() then
    haul_rods()
  end
end

-- Growing: a copy runs this same program, with these variables

function buy_one_and_wait(kit)
  if units(kit) > 0 then return end
  buy_here(1, kit)
  for _ = 1, KIT_WAIT_HOURS do
    if units(kit) > 0 then return end
    wait(HOUR)
  end
end

-- A copy's cost, plus work_cash for each drone the company will then have
function credits_needed_to_grow()
  local health = company_health()
  local drones = health and health.working or 1
  return math.max(grow_at, COPY_COST + work_cash * (drones + 1))
end

-- Kits for copies that can't launch tie up credits for good
function sell_spare_kits(kits)
  if today() == spare_kits_sold_on then return end
  spare_kits_sold_on = today()
  for _, kit in ipairs(kits) do
    if units(kit) > 0 then
      set_status("the fleet is full: selling " .. units(kit) .. " spare kits")
      sell_here(units(kit), kit)
    end
  end
end

function past_grow_until()
  return grow_until > 0 and today() > grow_until and units(mining_kit) == 0 and units(factory_kit) == 0
end

function buy_copy_kits()
  buy_one_and_wait(cruiser_kit)
  buy_one_and_wait(factory_kit)
  return units(cruiser_kit) > 0 and units(factory_kit) > 0
end

function fuel_up_for_copy()
  if units(fuel) < COPY_FUEL_BELOW then
    raise_cash(CASH_FOR_FUEL); buy_here(COPY_FUEL_TARGET - units(fuel), fuel); wait(HOUR)
  end
end

function launch_copy()
  copy = true   -- before the launch, so the copy gets it too
  if launch(me(), cruiser_kit) ~= 0 then return end
  local clones = my_clones()
  local newest = clones[#clones]
  if newest then
    push(1, factory_kit, newest.drone)
    push(FUEL_FOR_COPY, fuel, newest.drone)
  end
end

function grow()
  if house_only() then return end   -- a seedling keeps its credits for its debt
  if fleet_full() then sell_spare_kits({cruiser_kit, factory_kit}); return end
  if past_grow_until() then return end
  if credits() < credits_needed_to_grow() then return end
  if not buy_copy_kits() then return end
  fuel_up_for_copy()
  launch_copy()
end

-- Investing: once a game day, credits above invest_above buy shares whose
-- income pays their price back within invest_days, up to invest_cap in all

-- Sold by the company itself (not resold), not ours, and priced
function is_new_share_of_another_company(share)
  return share.seller == share.company and share.company ~= company() and share.price > 0
end

-- Shares companies sell of themselves at Earth: returns the credits left
function buy_listed_shares(free)
  for _, share in ipairs(shares_for_sale()) do
    local payback = payback_days(share.price, share_count(share.company), share.profit30, share.carried)
    if is_new_share_of_another_company(share) and payback <= invest_days then
      local amount = math.min(share.units, free // share.price, (invest_cap - invested_total) // share.price)
      local cost = amount * share.price
      if cost >= MIN_SHARE_ORDER then
        pull_shares(amount, share.company, share.seller)
        invested_total, free = invested_total + cost, free - cost
      end
    end
  end
  return free
end

-- What a new share earns over `days`: the company's profit with one more
-- drone (what a raise is usually for), less the losses it must make up
function new_share_value(offer, days)
  local drones = math.max(#company_drones(offer.company), 1)
  local earnings = days * math.max(offer.profit30, 0) * (drones + 1) / drones - offer.carried
  return math.max(earnings, 0) / (offer.count + offer.units)
end

-- The open offering it already backs, else the one worth its price that is
-- closest to filling: backers who pile into one fill it
function choose_offering(days)
  local pick
  for _, offer in ipairs(share_offerings()) do
    if offer.company ~= company() and offer.left > 0 then
      if offer.mine > 0 then return offer end
      if offer.price <= new_share_value(offer, days) and (not pick or offer.left < pick.left) then pick = offer end
    end
  end
  return pick
end

-- Returns the credits committed (a refund comes back as credits)
function back_an_offering(budget, days)
  local offer = choose_offering(days)
  if not offer then return 0 end
  local amount = math.min(offer.left, budget // offer.price)
  local completes_it = amount >= offer.left
  if amount * offer.price < MIN_SHARE_ORDER and not completes_it then return 0 end
  commit_shares(amount, offer.company)
  return amount * offer.price
end

function invest()
  if today() == invested_on or invested_total >= invest_cap then return end
  invested_on = today()
  local free = buy_listed_shares(credits() - invest_above)
  local budget = math.min(free, invest_cap - invested_total)
  if budget >= MIN_SHARE_ORDER then invested_total = invested_total + back_an_offering(budget, invest_days) end
end

function report_now_and_then()
  rounds_worked = rounds_worked + 1
  if rounds_worked % REPORT_EVERY ~= 0 then return end
  print("credits " .. credits() .. " rods " .. units(rods) .. " fuel " .. units(fuel))
  report()
end

function work()
  refuel()
  buy_metals()
  make_rods()
  sell_or_haul_rods()
  grow()
  invest()
  report_now_and_then()
end

-- launched() may run again (after a crash or an engine restart), so each
-- step does only what still needs doing
function launched()
  fuel_floor(FUEL_FLOOR)   -- keeps enough fuel to buy more
  equip(factory_kit)       -- a copy waits for the kit it is handed
  if not house_only() then go(moon) end   -- the Moon's miners sell metals cheapest
  while true do work() end
end
