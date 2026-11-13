-- A library of useful functions for Public Forge drones, in Lua (#380),
-- gathered from the starters and company designs. Copy the ones you need
-- into your script: a drone can't load another file. The game's own Lua
-- library already gives units, buy_here (buy cheapest first), sell_here
-- (sell to the best bids first), sell_all, board_price, await_kit and the
-- goods alloys_a1..a3, rods and cruiser_kit, so they aren't repeated here.
-- This file compiles as a whole and can be released as it is (it does
-- nothing).

-- --- Goods the game library doesn't name ---------------------------------
polymers, sheets = 2051, 3001

-- --- Inventory ------------------------------------------------------------
-- Total metals aboard
function metals() return units(metals_a1) + units(metals_a2) + units(metals_a3) end

-- --- Mining ---------------------------------------------------------------
-- Mine until n units of a mineral are aboard; wait an hour while its
-- deposit here refills (at Mars's moons the starters move on instead)
function dig(mineral, n)
  while units(mineral) < n do
    if deposit(mineral) < 5 then
      wait(60)
    else
      -- A run that brings nothing (others took it first) still burns fuel:
      -- wait as if the deposit were dry
      local before = units(mineral)
      mine(mineral)
      if units(mineral) == before then wait(60) end
    end
  end
end

-- Of two minerals, the one with more left in the deposit here
function richer(a, b)
  if deposit(a) < deposit(b) then return b end
  return a
end

-- --- Fuel -----------------------------------------------------------------
-- Brew 30 fuel from 1 Organics (A1) and 2 Inorganics (A1) (a Factory Kit's
-- Chemical Reactor; a Mining Kit can't brew). Returns make's result: 0 when
-- it worked
function brew_fuel() return make(fuel) end

-- Below n fuel, buy m here, cheapest first (from any drone selling it)
function top_up(n, m)
  if units(fuel) < n then buy_here(m, fuel) end
end

-- Stranded with little fuel and nobody selling here: post a buy order for
-- up to want fuel at price a unit (well above what fuel sells for, to pay
-- a courier to come), then wait, 2 game hours at a time, until some has
-- come. Waiting runs no instructions, so it burns no fuel
function call_for_fuel(price, want)
  buy_order(price, want, fuel)
  while units(fuel) < 20 do wait(120) end
end

-- --- Selling --------------------------------------------------------------
-- Sell at most 15 of a good this hour: buyers here tend to bid less as
-- they stock up
function portion(resource)
  local n = math.min(units(resource), 15)
  if n > 0 then sell_here(n, resource) end
end

-- Offer a good to other drones here at a price, keeping none; the order
-- stays open as the hold changes
function offer(price, resource) sell_order(price, 0, resource) end

-- Offer a good to other drones halfway between the best buy order here and
-- the cheapest other sell order (nothing where either is missing)
function offer_midway(resource)
  local bid, offer_price = sell_price(resource), buy_price(resource)
  if bid ~= 0 and offer_price ~= 0 then sell_order((bid + offer_price) // 2, 0, resource) end
end

-- --- Reading the market ---------------------------------------------------
-- Holding more than half of what sold at Earth yesterday (query 682)?
-- Sell first, or make something else
function overstocked(product) return units(product) * 2 > sold(product, 1, earth) end

-- Credits above cash_floor (set it in your settings)
cash_floor = 300
function spare() return math.max(credits() - cash_floor, 0) end

-- Profit from making one product: what it sells for here less what its
-- inputs cost here (n units of one input). Example, an alloy from 2 metals:
-- profit(alloys_a1, metals_a1, 2)
function profit(product, input, n) return sell_price(product) - buy_price(input) * n end

-- Adaptive pricing, from the market here: ask a unit under the cheapest
-- other seller while the stock sells out (fewer than 4 aboard), a unit over
-- the best bid while stock piles up (more than 16), and halfway between
-- otherwise; 0 where nobody sells it. Call reoffer every round, so a
-- standing sell order follows its own sales (round 25's miners priced once,
-- too low, and demand outran them). sell_order_if_changed posts only when
-- the price changed: each post costs a fuel
function smart_ask(resource)
  local offer_price = buy_price(resource)
  if offer_price == 0 then return 0 end
  local pay = sell_price(resource)
  if pay == 0 then pay = offer_price // 2 end
  local n = units(resource)
  if n < 4 then return offer_price - 1 end
  if n > 16 then return pay + 1 end
  return (offer_price + pay) // 2
end
function reoffer(resource)
  local price = smart_ask(resource)
  if price ~= 0 then sell_order_if_changed(price, 0, resource) end
end

-- --- State machines -------------------------------------------------------
-- The game functions become, in_mode, tick and ticks keep a drone's mode. A
-- skeleton: number the modes, one function per mode, and a step that picks one.
--   mining, selling = 0, 1
--   function mine_step() ... become(selling) ... end
--   function sell_step() ... become(mining) ... end
--   function step() if in_mode(mining) then mine_step() else sell_step() end end
--   function launched() unpack(); become(mining); while true do step(); tick() end end
-- Give up on a mode that takes too long: ticks counts its rounds
function stuck_for(n) return ticks() > n end

-- --- Buying from other drones ---------------------------------------------
-- Buy up to n of a good from the cheapest other seller here at price or
-- less; returns 0 when it bought, else nonzero
function buy_cheap(n, resource, price)
  local p, drone, avail = best_offer(resource)
  if avail == 0 or p > price then return -1 end
  return pull(math.min(n, avail), resource, drone)
end

-- --- Making ---------------------------------------------------------------
-- Make goods one after another while the inputs last; stop at a failure.
-- Example: rods from Metals (A1), two metals a rod
function forge_rods()
  while units(metals_a1) > 1 do
    if make(alloys_a1) ~= 0 then return end
    if make(rods) ~= 0 then return end
  end
end

-- --- Growing --------------------------------------------------------------
-- Growth (#380): with 7,000 credits or more, at an exchange, buy a Mining
-- Cruiser kit and a kit, launch a clone of this drone (it runs this same
-- program), and hand it the kit and 40 fuel. my_kit is the kit this design
-- needs: mining_kit or factory_kit (#380)
my_kit = mining_kit
-- Buy one of a good here unless one is aboard, and wait (up to 6 game
-- hours) for it, in case nobody here has one for sale yet
function get_one(resource)
  if units(resource) > 0 then return end
  buy_here(1, resource)
  for _ = 1, 6 do
    if units(resource) > 0 then return end
    wait(60)
  end
end
-- Hand the newest clone a kit and 40 fuel
function equip_clone()
  local clones = my_clones()
  local newest = clones[#clones]
  if not newest then return end
  push(1, my_kit, newest.drone)
  push(40, fuel, newest.drone)
end
-- Top the tank up to n here, cheapest first, waiting (up to 6 game hours)
-- (Waits for the fuel, not for n: the pull itself burns a unit, so the
-- tank can end just short of n, and waiting for n then sat out all 6 hours)
function fill_to(n)
  local had = units(fuel)
  local need = n - had
  if need <= 0 then return end
  buy_here(need, fuel)
  for _ = 1, 6 do
    if units(fuel) > had then return end
    wait(60)
  end
end
-- One kit at a time. A hull already bought is launched whenever the fuel
-- allows; after equipping the clone the tank is refilled (round 19 lost 8
-- parents that left with 20 fuel)
function reinvest()
  if units(my_kit) > 0 then equip_clone(); fill_to(60); return end
  if units(cruiser_kit) == 0 then
    if credits() < 7000 then return end
    get_one(cruiser_kit)
    if units(cruiser_kit) == 0 then return end
  end
  fill_to(90)
  if units(fuel) < 80 then return end
  if launch(me(), cruiser_kit) ~= 0 then return end
  get_one(my_kit)
  if units(my_kit) > 0 then equip_clone() end
  fill_to(60)
end

-- Asking about other exchanges (#380, after round 39)
-- The place whose best buyer pays at least margin more for a product than
-- here, or nil (quotes: live here, an hour old elsewhere)
function better_place(product, margin)
  local here, q = location(), quotes(product)
  local best, best_bid = nil, (q[here] and q[here].bid or 0) + margin
  for place, p in pairs(q) do
    if place ~= here and p.bid >= best_bid and p.bid_units > 0 then best, best_bid = place, p.bid end
  end
  return best
end

-- A plan B that looks before it flies: move where the product pays more,
-- if the trip is under three game days, and say so on the drone's page
function plan_b(product)
  local place = better_place(product, 100)
  if place and trip(place) < 72 then
    set_status("moving to " .. place_name(place) .. ": " .. resource_name(product) .. " pays more there")
    go(place)
  end
end

-- Whether the inputs for n of a product are on sale here, in depth, and
-- what they would cost
function can_build(product, n)
  local r = recipe(product)
  if not r then return false, 0 end
  local total = 0
  for input, per in pairs(r.uses) do
    local need = per * n - units(input)
    if need > 0 then
      local cost, got = cost_to_buy(need, input)
      if got < need then return false, 0 end
      total = total + cost
    end
  end
  return true, total
end

-- So that this file can also be released as it is (it does nothing)
function launched() end

-- --- Shares -----------------------------------------------------------------
-- Put credits above `above` into shares companies sell of themselves, if a
-- share's income pays its price back within `days` (#482). Call it once a
-- game day. Each request burns a unit of fuel, so it skips buys under 25
-- credits. Returns the credits it asked to spend.
function invest_spare(above, days)
  local free, spent = credits() - above, 0
  for _, o in ipairs(shares_for_sale()) do
    local a_day = math.max(o.profit, 0) / share_count(o.company)
    if o.seller == o.company and o.company ~= company() and o.price > 0
        and o.price <= a_day * days then
      local n = math.min(o.units, free // o.price)
      if n * o.price >= 25 then
        pull_shares(n, o.company, o.seller)
        free, spent = free - n * o.price, spent + n * o.price
      end
    end
  end
  return spent
end
