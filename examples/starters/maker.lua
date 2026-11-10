-- Maker: a starter script for Public Forge, in Lua (#380). It equips itself with
-- the Factory Kit (Engine, Smelter, Chemical Reactor and Spider).
-- It buys Metals (A1) at the Moon wherever they are cheapest, makes them
-- into alloys and the alloys into rods, and sells the rods wherever they
-- pay best: at the Moon if the best bid there is close to what rods fetched
-- at Earth yesterday (the price board), else it flies a load to Earth.
-- With grow_at credits it launches a copy of itself. Credits above
-- invest_above it puts into other companies' shares (see invest()).

-- Settings: change these numbers to make it your own
rod_load = 12      -- rods it takes to Earth (4 cargo each)
low_fuel = 40      -- buy fuel below this
keep_fuel = 25     -- it stops making rods below this much fuel
trip_margin = 20   -- flies to Earth only if rods sold there for this much more
grow_at = 7000     -- credits at which it buys and launches a copy
work_cash = 1500   -- credits each drone keeps to work with: a copy launches
                   -- only when the company keeps this much for every drone
                   -- it will have (#542: copies left companies with 8 to
                   -- 322 credits, and every drone sat "short of credits")
grow_until = 0     -- game day after which it launches no more copies (0: no
                   -- limit). A copy takes about 20 game days to earn back
                   -- its cost, so in a contest set this ~20 days before the
                   -- end (#512: a copy launched on day 47 of 60 lost 2,100)
batch = 20         -- metals it buys at a time
cash_floor = 300   -- credits it keeps (for fuel)
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

-- Credits above cash_floor
function spare() return math.max(credits() - cash_floor, 0) end

-- Metals for a batch, as many as the spare credits pay for (at most 30 a unit)
function stock_up()
  if units(metals_a1) >= 2 then return end
  local n = math.min(batch, spare() // 30)
  if n > 0 then
    buy_here(n, metals_a1)
    wait(60)
  end
end

-- Out of metals and of money to buy them, with rods aboard: go and sell
function stuck()
  return units(metals_a1) < 2 and spare() < 60 and units(rods) > 0
end

-- Metals into rods, two at a time; stop at the first failure, or when the
-- tank is down to keep_fuel
function forge_rods()
  while units(metals_a1) > 1 and units(fuel) > keep_fuel do
    if make(alloys_a1) ~= 0 then return end
    if make(rods) ~= 0 then return end
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
-- traded for there yesterday if that was more. (#503: the board price alone
-- was 0 for rods that hadn't traded at Earth yet, so it sold at the Moon's
-- low bid)
function sell_here_pays()
  local bid = sell_price(rods)
  local at_earth = quotes(rods)[earth]
  local earth_pays = math.max(at_earth and at_earth.bid or 0, board_price(rods, earth))
  return bid > 0 and bid + trip_margin >= earth_pays
end

-- Where to sell the rods aboard: Earth, unless another place pays more for
-- them after the round trip (where_to_sell; #542: round 57's Makers carried
-- rods nobody bid for at Earth or the Moon for up to 18 days, while Mars
-- bid 155)
function market_for_rods()
  local best = where_to_sell(rods, math.max(units(rods), 1))[1]
  if best and best.place ~= moon and best.net > 0 then return best.place end
  return earth
end

function haul()
  local market = market_for_rods()
  set_status("hauling " .. units(rods) .. " rods to " .. place_name(market))
  go(market)
  if units(fuel) < 60 then raise_cash(150); buy_here(30, fuel); wait(120) end
  sell_rods()
  go(moon)
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

function reinvest()
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
    local a_day = math.max(o.sales, 0) / share_count(o.company)
    if o.seller == o.company and o.company ~= company() and o.price > 0
        and o.price <= a_day * invest_days then
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
-- days, on the company's last day of sales spread over its shares once the
-- offering fills, and counting the sales of one more drone (what a raise is
-- usually for)
function offering_worth(o, days)
  local n = math.max(#company_drones(o.company), 1)
  return days * math.max(o.sales, 0) * (n + 1) / n / (o.count + o.units)
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
  -- (but never skips the commitment that completes it: #509, an offering
  -- sat at 277 of 278 shares for days because the last one cost 9)
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
  go(moon)
  while true do work() end
end
