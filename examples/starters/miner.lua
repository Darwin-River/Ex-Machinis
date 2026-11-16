-- Miner: a starter script for Ex Machinis, in Lua. It equips itself
-- with the Mining Kit (Engine, Worm and Refinery).
-- It mines the richer of kunspar and wolfite at the Moon, refines it, and
-- keeps standing sell orders for its metals, priced halfway between the
-- best bid and the cheapest other seller here; once hold_cap are aboard it
-- sells what nobody bought where the House pays most for them, counting the
-- trip (the Moon, Mars or Earth: house_demand()). When more than crowd_limit
-- drones are at the Moon, the drone you release starts at the quieter of
-- Phobos and Deimos instead. When the Moon has been dry
-- dry_tries times in a row and Phobos is less crowded, it moves to Phobos
-- and Deimos (its status says so). Its kit can't brew fuel: it buys it
-- wherever it is cheapest, and flies to the market for it when it runs
-- low where nobody sells it. With grow_at
-- credits it launches a copy; a
-- third of its copies stay at the Moon, the rest work Phobos and Deimos.

-- Settings: change these numbers to make it your own
low_fuel = 40      -- get fuel when below this
dry_tries = 12     -- dry tries at the Moon before trying Phobos (a large
                   -- number keeps it at the Moon)
grow_at = 7000     -- credits at which it buys and launches a copy (not while
                   -- it is a seedling: it saves for its debt, then grows once matured)
                   -- (6500 at least: a hull is 3000, a kit 2000,
                   -- and 1500 left to work with)
work_cash = 1500   -- credits each drone keeps to work with: a copy launches
                   -- only when the company keeps this much for every drone
                   -- it will have (#542: copies left companies with 8 to
                   -- 322 credits, and every drone sat "short of credits")
grow_until = 0     -- game day after which it launches no more copies (0: no
                   -- limit). A copy takes about 20 game days to earn back
                   -- its cost, so in a contest set this ~20 days before the
                   -- end (#512: a copy launched on day 47 of 60 lost 2,100)
crowd_limit = 30   -- drones at the Moon past which the drone you release
                   -- starts at Phobos or Deimos instead, whichever is quieter
                   -- (round 55: 98 drones at the Moon on day 1, the deposits
                   -- mined out; a large number keeps it at the Moon)
rest = 240         -- game minutes to rest when both Mars moons are dry
hold_cap = 16      -- metals it keeps on offer to other drones
min_ask = 12       -- the least it asks where nobody bids (metals sold for 18 to 20 in round 64)
hour_value = 4     -- what a game hour of its time is worth, in credits: it
                   -- flies further to sell when this is lower (#740: at 10,
                   -- a miner at Phobos or Deimos sold at Mars for 2 to 5 a
                   -- metal; at 4 it flies to Earth and matured in 55 to 69
                   -- game days in round 71)
haul_at = 60       -- metals still aboard after selling at which it flies
                   -- them to the best bidder elsewhere (#713)

-- The site it works: 0 the Moon, 1 Phobos, 2 Deimos. The drone you release
-- works the Moon unless it is crowded; copies spread out by drone number
site_no, misses, copy = 0, 0, false

function site()
  if site_no == 0 then return moon elseif site_no == 1 then return phobos else return deimos end
end
-- Its nearest market: the Moon Depot at the Moon, else the Mars Exchange
function market() if site() == moon then return moon else return mars end end

-- Where the House pays most for the metals aboard (#713: round 70's
-- seedlings at Phobos and Deimos hoarded 70 to 110 metals, since Mars often
-- bid nothing for them, while the Moon and Earth did). For the Moon, Mars
-- and Earth: what the House there bids for them now, for as many as it
-- still takes today (house_demand), less the trip there and back to the
-- site (10 fuel at about 3, and hour_value a game hour). The best of those
-- that come out ahead, not counting `except`; nil if none does
markets = {moon, mars, earth}
function best_market(except)
  local value = {}
  for _, metal in ipairs(metals_sold) do
    local n = units(metal)
    if n > 0 then
      for _, d in ipairs(house_demand(metal)) do
        if d.price > 0 and d.left > 0 then
          value[d.place] = (value[d.place] or 0) + d.price * math.min(n, d.left)
        end
      end
    end
  end
  local best, best_net = nil, 0
  for _, p in ipairs(markets) do
    if p ~= except and value[p] then
      local net = value[p]
      if p ~= location() then
        local there = trip_to(p)
        net = net - 30 - (there and there.hours or 0) * hour_value
      end
      if p ~= site() then
        local back = trip_between(p, site())
        net = net - (back and back.hours or 0) * hour_value
      end
      if net > best_net then best, best_net = p, net end
    end
  end
  return best
end

-- Its asking price, from the market here: halfway between the best bid and
-- the cheapest other seller; with no bid, the cheapest seller's price (not a
-- unit under it: round 64's miners undercut one another down to 2 credits
-- where nobody bid), and never under min_ask; with no seller, half as much
-- again as the best bid; 0 where nobody trades it
function ask_price(metal)
  local bid, offer = sell_price(metal), buy_price(metal)
  if bid == 0 then return offer > 0 and math.max(offer, min_ask) or 0 end
  if offer == 0 then return bid + bid // 2 end
  return (bid + offer) // 2
end

-- Standing sell orders for its metals, set where the market has prices;
-- a post that would change nothing costs no fuel
metals_sold = {metals_a1, metals_a2, metals_a3, inorganics_a2}
function offer()
  if house_only() then return end   -- a seedling's orders: only the House trades with it, and sells straight to the House (#658)
  for _, metal in ipairs(metals_sold) do
    local price = ask_price(metal)
    if price > 0 then sell_order_if_changed(price, 0, metal) end
  end
end

function metals()
  local n = 0
  for _, metal in ipairs(metals_sold) do n = n + units(metal) end
  return n
end

function sell_out(resource)
  local n = units(resource)
  if n > 0 then sell_here(n, resource) end
end

-- Where nobody sells fuel, bid for it a little over the exchanges' price: a
-- fuel courier finds the bid in the market; the bid stops once the tank is
-- filled at the market. A seedling's bid brings no courier (only the House
-- trades with it), so it flies to the market at once
function refuel()
  if units(fuel) >= low_fuel then return end
  raise_cash(150); buy_here(30, fuel)
  wait(60)
  if units(fuel) >= low_fuel then return end
  local seedling = house_only()
  if not seedling then
    buy_order(7, 60, fuel)
    wait(120)
  end
  if units(fuel) < low_fuel then
    set_status("short of fuel: filling up at " .. place_name(market()))
    go(market())
    raise_cash(150); fill_to(80)
    go(site())
  end
  if not seedling then buy_order(1, 0, fuel) end
end

-- A low deposit: at the Moon, wait for it to refill (after dry_tries dry
-- tries in a row, try Phobos, if fewer drones work there); at Mars, try the
-- other moon, and rest after both are dry. Short of fuel, get some first
-- (#713: it rested at a Mars moon with under 15 fuel for good, waiting for
-- a deposit while nobody there sells fuel; a drone with 10 reached Mars)
function move_on()
  if units(fuel) < low_fuel then refuel(); return end
  if site_no == 0 then
    misses = misses + 1
    if misses < dry_tries or units(fuel) < 60 or crowd(phobos) >= crowd(moon) then
      wait(60)
      return
    end
    site_no, misses = 1, 0
    -- Say why it leaves (#496): its page shows the status
    set_status("the Moon was dry " .. dry_tries .. " times: trying Phobos")
    go(site())
    return
  end
  site_no = 3 - site_no   -- Phobos <-> Deimos
  set_status("dry here: trying " .. place_name(site()))
  misses = misses + 1
  if misses % 2 == 0 then wait(rest) end
  go(site())
end

-- Mine until n units of a mineral are aboard, moving on when its deposit
-- here runs low, or a run brings nothing (others took it first)
function dig(mineral, n)
  while units(mineral) < n do
    if deposit(mineral) < 5 then
      move_on()
    else
      local before = units(mineral)
      mine(mineral)
      if units(mineral) == before then move_on() else misses = 0 end
    end
  end
end

-- Of two minerals, the one with more left in the deposit here
function richer(a, b)
  if deposit(a) < deposit(b) then return b end
  return a
end

-- Top the tank up to n here, cheapest first, waiting up to 6 game hours
function fill_to(n)
  local had = units(fuel)
  local need = n - had
  if need <= 0 then return end
  raise_cash(150); buy_here(need, fuel)
  for _ = 1, 6 do
    if units(fuel) > had then return end
    wait(60)
  end
end

-- Buy one of a good here unless one is aboard, and wait up to 6 game hours
function get_one(resource)
  if units(resource) > 0 then return end
  buy_here(1, resource)
  for _ = 1, 6 do
    if units(resource) > 0 then return end
    wait(60)
  end
end

-- Hand the newest copy a Mining Kit and 40 fuel
function equip_clone()
  local clones = my_clones()
  local newest = clones[#clones]
  if not newest then return end
  push(1, mining_kit, newest.drone)
  push(40, fuel, newest.drone)
end

-- Growth: with grow_at credits or more, at an exchange, buy a Mining
-- Cruiser kit and a Mining Kit, launch a copy of this drone (it runs this
-- same program, with these variables) and hand it the kit and 40 fuel. A
-- copy costs about 5,000: growing pays early, not in a contest's last weeks
-- Credits the company needs before launching a copy: about 5,000 for the
-- copy (a hull and a kit), and work_cash for each drone it will then have
function grow_line()
  local h = company_health()
  local drones = h and h.working or 1
  return math.max(grow_at, 5000 + work_cash * (drones + 1))
end

function reinvest()
  if house_only() then return end   -- a seedling keeps its credits for its debt: it grows once it has matured (#658)
  if grow_until > 0 and today() > grow_until and units(mining_kit) == 0 and units(factory_kit) == 0 then return end
  if units(mining_kit) > 0 then equip_clone(); fill_to(60); return end
  if units(cruiser_kit) == 0 then
    if credits() < grow_line() then return end
    if fleet_full() then return end   -- the company has all the drones it may have (#682)
    get_one(cruiser_kit)
    if units(cruiser_kit) == 0 then return end
  end
  fill_to(90)
  if units(fuel) < 80 then return end
  copy = true   -- the copy gets this too: it knows it is a copy
  if launch(me(), cruiser_kit) ~= 0 then return end
  get_one(mining_kit)
  if units(mining_kit) > 0 then equip_clone() end
  fill_to(60)
end

-- Sell the metals where the House pays most for them (best_market; its
-- nearest market if none pays), top up the tank there, then go back to the
-- site, once hold_cap are aboard: until then other drones may buy them.
-- With haul_at or more still unsold, it flies them to the best bidder
-- elsewhere (#713)
function sell_metals()
  if metals() < hold_cap then return end
  local where = best_market() or market()
  if where ~= location() then set_status("selling " .. metals() .. " metals at " .. place_name(where)) end
  go(where)
  offer()
  for _, metal in ipairs(metals_sold) do sell_out(metal) end
  if metals() >= haul_at then
    local next_best = best_market(location())
    if next_best then
      if units(fuel) < 50 then fill_to(80) end
      set_status("hauling " .. metals() .. " unsold metals to " .. place_name(next_best))
      go(next_best)
      for _, metal in ipairs(metals_sold) do sell_out(metal) end
    end
  end
  if units(fuel) < 50 then fill_to(80) end
  reinvest()
  go(site())
end

-- Every 4th round, send a line home
rounds = 0
function tell()
  rounds = rounds + 1
  if rounds % 4 ~= 0 then return end
  print("credits " .. credits() .. " fuel " .. units(fuel))
  report()
end

function work()
  refuel()
  local mineral = richer(kunspar, wolfite)
  dig(mineral, 20)
  refine(mineral)
  sell_metals()
  tell()
end

-- Where the drone you release starts: the Moon, unless more than
-- crowd_limit drones are there; then the quieter of Phobos and Deimos
function first_site()
  local at_moon = crowd(moon)
  if at_moon <= crowd_limit then return 0 end
  local n = crowd(phobos) <= crowd(deimos) and 1 or 2
  set_status(at_moon .. " drones at the Moon: starting at " .. place_name(n == 1 and phobos or deimos))
  return n
end

-- launched() may run again (after a crash, or if the engine restarts):
-- equip passes once the tools are unpacked, and a drone that
-- has already chosen its site keeps it
-- started: the drone that chose its site. A copy inherits its parent's
-- number, so it chooses afresh (#380 round 64: copies kept their parent's site)
started = 0
function launched()
  fuel_floor(15)   -- make, mine, refine and push stop at 15 fuel: it keeps enough to buy more (#496)
  equip(mining_kit)   -- buys a kit at Earth and unpacks it (a copy waits for the one it is handed; #506)
  if started ~= me() then
    if copy then site_no = me() % 3 else site_no = first_site() end
    copy, started = true, me()
  end
  go(site())
  offer()
  while true do work() end
end
