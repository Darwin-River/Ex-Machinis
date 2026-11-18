-- Maker: a starter script for Ex Machinis, in Lua. It equips itself with the
-- Factory Kit (Engine, Smelter, Chemical Reactor and Spider).
-- It buys Metals (A1) at the Moon wherever they are cheapest (as a seedling,
-- from the House where a batch costs least, counting the trip and its
-- daily cap: a tenth of what is on sale, at least 5), makes them
-- into alloys and the alloys into rods, and sells the rods wherever they
-- pay best: at the Moon if the best bid there is close to what rods fetched
-- at Earth yesterday (the price board), else it flies a load to Earth. As a
-- seedling, where the House pays most for them after the trip.
-- With grow_at credits it launches a copy of itself. Credits above
-- invest_above it puts into other companies' shares (see invest()).

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
invest_above = 3000 -- credits above this go into other companies' shares
invest_days = 30   -- pays up to this many days of a share's income
invest_cap = 1500  -- credits it puts into shares in all (0: never invests)

-- Fuel when low, from whoever sells it cheapest here
function refuel()
  if units(fuel) < low_fuel then
    raise_cash(150); buy_here(30, fuel)
    wait(60)
  end
end

-- Credits above what it keeps: cash_floor when its tank is low, low_floor
-- otherwise (#740)
function spare()
  local keep = units(fuel) < low_fuel and cash_floor or low_floor
  return math.max(credits() - keep, 0)
end

-- Metals for a batch, as many as the spare credits pay for at the price there
-- A seedling buys only from the House, and at most a tenth of what it has on
-- sale at an exchange in a game day (-17). So it asks house_supply() where
-- the House sells metals and picks the place where a unit costs least: the
-- price, plus the round trip (fuel, and 10 credits a game hour) shared over
-- what its cap lets it buy there. It waits for the next day only when none
-- sells any
capped_at = {}
function metals_place()
  local best, best_each, best_price = nil, math.huge, 0
  for _, h in ipairs(house_supply(metals_a1)) do
    local n = math.min(batch, h.on_sale, math.max(5, h.on_sale // 10))   -- what its cap lets it buy there today (a tenth, at least 5)
    if h.price > 0 and n >= 2 and capped_at[h.place] ~= today() then
      local trip = 0
      if h.place ~= location() then
        local t = trip_to(h.place)
        trip = 30 + (t and t.hours * 2 or 0) * 10   -- 10 fuel at about 3, and the hours there and back
      end
      local each = h.price + trip / n
      if each < best_each then best, best_each, best_price = h.place, each, h.price end
    end
  end
  return best, best_each, best_price
end

-- The best House bid for rods anywhere that still buys, less the 5% sales
-- tax: what a rod made now will fetch at least (0 when none bids)
function rod_value()
  local best = 0
  for _, d in ipairs(house_demand(rods)) do
    if d.price > 0 and d.left > 0 then best = math.max(best, d.price * 95 // 100) end
  end
  return best
end
function stock_up()
  if units(metals_a1) >= 2 then return end
  -- Too few credits for two metals: rest a while
  if spare() < 60 then
    set_status("short of credits for metals")
    wait(120)
    return
  end
  local price = buy_price(metals_a1)
  if house_only() then
    local best, each, at = metals_place()
    if not best then
      set_status("no metals on sale from the House: waiting for tomorrow's")
      wait(240)
      return
    end
    -- Rods must pay for their metals
    if each * metals_per_rod >= rod_value() then
      -- In words a player can check on the Market board
      set_status("a rod needs " .. metals_per_rod .. " metals at about " .. math.floor(each) .. " each (with the trip), and pays "
        .. rod_value() .. " at best: waiting for a better rod bid")
      wait(240)
      return
    end
    if best ~= location() then
      set_status("buying metals at " .. place_name(best))
      go(best)
    end
    price = at
  end
  if price <= 0 then price = 30 end
  local n = math.min(batch, spare() // price)
  if n > 0 then
    if buy_here(n, metals_a1) == -17 then capped_at[location()] = today() end
    wait(60)
  end
end

-- Out of metals and of money to buy them, with rods aboard: go and sell
function stuck()
  return units(metals_a1) < 2 and spare() < 60 and units(rods) > 0
end

-- Metals into rods, two at a time; stop at the first failure, or when the
-- tank is down to keep_fuel
-- After 3 failed makes in a row it says so and waits 4 game hours, not one
make_fails = 0
function forge_rods()
  while units(metals_a1) > 1 and units(fuel) > keep_fuel do
    local r = make(alloys_a1)
    if r == 0 then r = make(rods) end
    if r ~= 0 then
      make_fails = make_fails + 1
      if make_fails >= 3 then
        set_status("making failed " .. make_fails .. " times in a row (" .. r .. "): waiting 4 game hours")
        wait(240)
      end
      return
    end
    make_fails = 0
  end
end

-- Sell at most 4 rods an hour, for up to 6 hours: buyers bid less as they
-- stock up, and bid again later
function sell_rods()
  for hour = 1, 6 do
    if units(rods) == 0 then return end
    sell_here(math.min(units(rods), 4), rods)
    wait(60)
  end
end

-- Sell here? Yes if somebody here bids for rods and Earth paid no more than
-- trip_margin over that yesterday
-- Selling here pays when the bid here, plus what the trip would cost,
-- matches what Earth pays: its best bid an hour ago (quotes), or what rods
-- traded for there yesterday if that was more. A seedling asks house_rods()
function sell_here_pays()
  if house_only() then return house_rods() == location() end
  local bid = sell_price(rods)
  local at_earth = quotes(rods)[earth]
  local earth_pays = math.max(at_earth and at_earth.bid or 0, board_price(rods, earth))
  return bid > 0 and bid + trip_margin >= earth_pays
end

-- Where the House pays most for the rods aboard, for a seedling, which
-- sells only to the House. At each exchange: the House's bid now for as many as it still takes today
-- (house_demand), less the 5% sales tax and, elsewhere, the round trip (10
-- fuel at about 3, and 10 credits a game hour); nil when none comes out ahead
function house_rods()
  local n, best, best_net = units(rods), nil, 0
  for _, d in ipairs(house_demand(rods)) do
    local take = math.min(n, d.left)
    if d.price > 0 and take > 0 then
      local net = d.price * take * 95 // 100
      if d.place ~= location() then
        local t = trip_to(d.place)
        net = net - 30 - (t and t.hours * 2 or 0) * 10
      end
      if net > best_net then best, best_net = d.place, net end
    end
  end
  return best
end

-- Where to sell the rods aboard: Earth, unless another place pays more for
-- them after the round trip (where_to_sell). A seedling: house_rods(), nil when no House bid pays the trip
function market_for_rods()
  if house_only() then return house_rods() end
  local best = where_to_sell(rods, math.max(units(rods), 1))[1]
  if best and best.place ~= moon and best.net > 0 then return best.place end
  return earth
end

function haul()
  local market = market_for_rods()
  if not market then   -- a seedling, and no House bid pays the trip: wait for one (#713)
    set_status("no House bid for " .. units(rods) .. " rods pays the trip: waiting")
    wait(120)
    return
  end
  set_status("hauling " .. units(rods) .. " rods to " .. place_name(market))
  local r = go(market)
  if r ~= 0 then   -- #748: drones that couldn't fly sat on 114 rods
    set_status("couldn't fly to " .. place_name(market) .. " (" .. r .. "): selling here if anyone buys, then waiting")
    sell_rods()
    wait(120)
    return
  end
  if units(fuel) < 60 then raise_cash(150); buy_here(30, fuel); wait(120) end
  sell_rods()
  if not house_only() then go(moon) end   -- a seedling picks its next exchange in stock_up
end

-- Growth: with grow_at credits, buy a Mining Cruiser kit and a Factory Kit
-- here (every exchange sells both), launch a copy of this drone (it runs
-- this same program, with these variables), and hand it the kit and 40 fuel
copy = false
function get_one(resource)
  if units(resource) > 0 then return end
  buy_here(1, resource)
  for _ = 1, 6 do
    if units(resource) > 0 then return end
    wait(60)
  end
end
-- Credits the company needs before launching a copy: about 5,000 for the
-- copy (a hull and a kit), and work_cash for each drone it will then have
function grow_line()
  local h = company_health()
  local drones = h and h.working or 1
  return math.max(grow_at, 5000 + work_cash * (drones + 1))
end

-- The fleet is full: kits bought for copies that can't launch are credits
-- tied up for good, so sell them, once a game day
kits_tried = -1
function sell_spare_kits(kits)
  if today() == kits_tried then return end
  kits_tried = today()
  for _, k in ipairs(kits) do
    if units(k) > 0 then
      set_status("the fleet is full: selling " .. units(k) .. " spare kits")
      sell_here(units(k), k)
    end
  end
end

function reinvest()
  if house_only() then return end   -- a seedling keeps its credits for its debt: it grows once it has matured (#658)
  if fleet_full() then sell_spare_kits({cruiser_kit, factory_kit}); return end   -- the company has all the drones it may have (#682)
  if grow_until > 0 and today() > grow_until and units(mining_kit) == 0 and units(factory_kit) == 0 then return end
  if credits() < grow_line() then return end   -- keeps work_cash for every drone
  get_one(cruiser_kit)
  get_one(factory_kit)
  if units(cruiser_kit) == 0 or units(factory_kit) == 0 then return end
  if units(fuel) < 80 then raise_cash(150); buy_here(90 - units(fuel), fuel); wait(60) end
  copy = true   -- the copy gets this too: it knows it is a copy
  if launch(me(), cruiser_kit) ~= 0 then return end
  local clones = my_clones()
  local newest = clones[#clones]
  if newest then
    push(1, factory_kit, newest.drone)
    push(40, fuel, newest.drone)
  end
end

-- Investing: once a game day, credits above invest_above buy shares that
-- companies sell of themselves at the Earth exchange (shares_for_sale()),
-- or offer new (share_offerings(), #485),
-- if a share's income pays its price back within invest_days. Each share
-- earns 0.1% of the company's sales after tax, paid as it sells, wherever
-- this drone is. A request reaches Earth at the speed of light and burns a
-- unit of fuel, so it skips buys worth less than 25 credits
invest_day, put_in = -1, 0
function invest()
  if today() == invest_day or put_in >= invest_cap then return end
  invest_day = today()
  local free = credits() - invest_above
  for _, o in ipairs(shares_for_sale()) do
    -- days to pay its price back: losses made up first, then 30-day profit (#607)
    local back = payback_days(o.price, share_count(o.company), o.profit30, o.carried)
    if o.seller == o.company and o.company ~= company() and o.price > 0
        and back <= invest_days then
      local n = math.min(o.units, free // o.price, (invest_cap - put_in) // o.price)
      if n * o.price >= 25 then
        pull_shares(n, o.company, o.seller)
        put_in, free = put_in + n * o.price, free - n * o.price
      end
    end
  end
  -- and offerings of new shares (#485) worth it, with what's left (a
  -- refund comes back as credits, but stays counted)
  local budget = math.min(free, invest_cap - put_in)
  if budget >= 25 then put_in = put_in + back_offerings(budget, invest_days) end
end

-- Offerings of new shares (#485): what a new share is worth over `days`
-- days, on the company's profit a day (30-day average, #607) spread over
-- its shares once the offering fills, counting the profit of one more drone
-- (what a raise is usually for), less the losses it must make up first
function offering_worth(o, days)
  local n = math.max(#company_drones(o.company), 1)
  return math.max(days * math.max(o.profit30, 0) * (n + 1) / n - o.carried, 0) / (o.count + o.units)
end
-- Commit up to `budget` credits to one offering at a time (#488): the one
-- it has already backed while it is open, else the open offering worth its
-- price that is closest to filling. Backers that pile into the same one
-- fill it (an offering short of its minimum at the deadline refunds them).
-- Skips commitments under 25 credits (each burns a unit of fuel), unless
-- one completes the offering. Returns the credits it committed
function back_offerings(budget, days)
  local pick
  for _, o in ipairs(share_offerings()) do
    if o.company ~= company() and o.left > 0 then
      if o.mine > 0 then pick = o; break end
      if o.price <= offering_worth(o, days) and (not pick or o.left < pick.left) then pick = o end
    end
  end
  if not pick then return 0 end
  local n = math.min(pick.left, budget // pick.price)
  -- (but never skips the commitment that completes it)
  if n * pick.price < 25 and n < pick.left then return 0 end
  commit_shares(n, pick.company)
  return n * pick.price
end

-- Every 4th round, send a line home
rounds = 0
function tell()
  rounds = rounds + 1
  if rounds % 4 ~= 0 then return end
  print("credits " .. credits() .. " rods " .. units(rods) .. " fuel " .. units(fuel))
  report()
end

function work()
  refuel()
  stock_up()
  forge_rods()
  if units(rods) > 0 and sell_here_pays() then
    sell_rods()
  elseif units(rods) >= rod_load or stuck() then
    haul()
  end
  reinvest()
  invest()
  tell()
end

-- launched() may run again (after a crash, or if the engine restarts), so
-- it only does what still needs doing: equip passes at once
-- once the tools are unpacked, and go stays put if already there
function launched()
  fuel_floor(15)   -- make, mine, refine and push stop at 15 fuel: it keeps enough to buy more (#496)
  equip(factory_kit)   -- buys a kit at Earth and unpacks it (a copy waits for the one it is handed; #506)
  -- A seedling looks where the House sells metals first (stock_up does);
  -- a matured company buys from anyone, and the Moon's miners sell cheapest
  if not house_only() then go(moon) end
  while true do work() end
end
