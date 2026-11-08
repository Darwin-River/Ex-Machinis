-- Maker: a starter script for Public Forge, in Lua (#380). Release it with
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
batch = 20         -- metals it buys at a time
cash_floor = 300   -- credits it keeps (for fuel)
invest_above = 3000 -- credits above this go into other companies' shares
invest_days = 30   -- pays up to this many days of a share's income
invest_cap = 1500  -- credits it puts into shares in all (0: never invests)

-- Fuel when low, from whoever sells it cheapest here
function refuel()
  if units(fuel) < low_fuel then
    buy_here(30, fuel)
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
function sell_here_pays()
  local bid = sell_price(rods)
  return bid > 0 and bid + trip_margin >= board_price(rods, earth)
end

function haul()
  go(earth)
  if units(fuel) < 60 then buy_here(30, fuel); wait(120) end
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
function reinvest()
  if credits() < math.max(grow_at, 6500) then return end   -- keeps 1,500 to work with
  get_one(cruiser_kit)
  get_one(factory_kit)
  if units(cruiser_kit) == 0 or units(factory_kit) == 0 then return end
  if units(fuel) < 80 then buy_here(90 - units(fuel), fuel); wait(60) end
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
-- Commit up to `budget` credits to offerings worth their price, those
-- closest to filling first: an offering pays only if it fills. Skips
-- commitments under 25 credits (each burns a unit of fuel). Returns the
-- credits it committed
function back_offerings(budget, days)
  local spent = 0
  local list = share_offerings()
  table.sort(list, function(a, b) return a.left < b.left end)
  for _, o in ipairs(list) do
    if o.company ~= company() and o.left > 0 and o.price <= offering_worth(o, days) then
      local n = math.min(o.left, budget // o.price)
      if n * o.price >= 25 then
        commit_shares(n, o.company)
        budget, spent = budget - n * o.price, spent + n * o.price
      end
    end
  end
  return spent
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
-- it only does what still needs doing: await_kit and unpack pass at once
-- once the tools are unpacked, and go stays put if already there
function launched()
  await_kit()   -- a copy starts with no equipment: wait for its kit
  unpack()
  go(moon)
  while true do work() end
end
