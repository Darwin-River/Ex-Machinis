-- The standard library of a Lua drone (#380): the game words of the FORTH
-- library (vm_library.c) with the same meanings, names with _ for -, on top
-- of the engine's perform(protocol, ...) and query(id, ...).
-- Action words return perform's result: 0 when the action worked, else a
-- negative code. Money and big numbers are plain numbers (no doubles).
-- Every name here is a local, and the library's functions call each other
-- through those locals; the globals of the same names (the export at the
-- end) are a script's to use or replace. So a script that reuses a name
-- (best_bid = 30) changes its own code only, as a FORTH redefinition does
-- (round 35: a script's best_bid variable crashed the library's sell_price)

-- Places
local earth, moon, mars, phobos, deimos = 3, 10, 4, 11, 12

-- Resources
local fuel, magmite, kunspar, yalspar, vladnium, wolfite = 2061, 1000, 1100, 1110, 1120, 1130
local mining_kit, factory_kit, cruiser_kit = 5901, 5902, 6001
local metals_a1, metals_a2, metals_a3 = 2011, 2012, 2013
local inorganics_a1, organics_a1 = 2031, 2041
local inorganics_a2, organics_a2 = 2032, 2042
local alloys_a1, alloys_a2, alloys_a3 = 2021, 2022, 2023
local rods = 3011

-- A fuel floor (#380 round 36: Sven 2 sold its last fuel away in a deep
-- build): once set, at or below it make (but fuel), mine, refine and push
-- refuse with -30, so the fuel left is for buying fuel
local floor_fuel = 0
local function fuel_floor(n) floor_fuel = n or 0 end
local function floored()
  if floor_fuel <= 0 then return false end
  local rows = query(212, fuel)
  return (rows and rows[1] and rows[1][1] or 0) <= floor_fuel
end

-- Actions
local function travel(place) return perform(5001, place) end
local function mine(mineral) if floored() then return -30 end return perform(mineral + 10000) end
local function refine(mineral) if floored() then return -30 end return perform(mineral + 20000) end
local function make(resource)
  if resource ~= fuel and floored() then return -30 end
  return perform(resource + 10000)
end
local function wait(minutes) return perform(3, minutes) end
local function push(n, resource, drone)
  if floored() then return -30 end
  return perform(10, n, resource, drone)
end
local function pull(n, resource, drone) return perform(11, n, resource, drone) end
local function launch(drone, kit) return perform(12, drone, kit) end
local function plunder(wreck) return perform(34, wreck) end
local function jettison(n, resource) return perform(1, n, resource) end
local function broadcast(text) return perform(40, text) end
-- The share exchange at Earth (#469): shares trade the way goods do. Post
-- the company's standing orders (price 0 withdraws one), or answer another
-- company's: pull from its sell order, push into its buy order
local function sell_shares(c, keep, price) return perform(41, c, keep, price) end
local function buy_shares(c, want, price) return perform(42, c, want, price) end
local function pull_shares(n, c, seller) return perform(43, n, c, seller) end
local function push_shares(n, c, buyer) return perform(44, n, c, buyer) end
-- New shares (#485): offer up to n new shares of your company at price
-- each, open for days game days, and at least min (#516; all n if left out):
-- fully committed it issues them at once, and at the deadline it issues what
-- was committed if that is at least min (n 0 withdraws the offering). Or
-- commit to n of company c's offered shares (paid now, refunded if the
-- offering lapses short of its minimum)
local function offer_shares(n, price, days, min) return perform(45, min or n, n, price or 0, days or 0) end
local function commit_shares(n, c) return perform(46, n, c) end

-- Queries: ask returns the rows (a table of rows, each a table of columns)
local function ask(id, ...) return query(id, ...) or {} end
local function first(id, ...)
  local rows = query(id, ...)
  return rows and rows[1] and rows[1][1] or 0
end
local function me() return first(205) end
local function location() return first(202, me()) end
local function units(resource) return first(212, resource) end
local function company() return first(201, me()) end
local function credits() return first(101, company()) end
local function today() return first(207) end
local function crowd(place) return first(721, place) end
-- Unpacks the kit aboard; 0 when there is none (already unpacked: a drone
-- whose engine restarted runs launched() again, so this must be harmless)
local engine_module = 5001
local function unpack_kit()
  if units(factory_kit) > 0 then return perform(25902) end
  if units(mining_kit) > 0 then return perform(25901) end
  return 0
end
local unpack = unpack_kit

-- Waits, an hour at a time, until the drone has a kit or its unpacked
-- tools (a clone starts with neither, until its parent hands it a kit)
local function await_kit()
  while units(factory_kit) == 0 and units(mining_kit) == 0 and units(engine_module) == 0 do
    wait(60)
  end
end

-- Units left in a deposit here (0 if none)
local function deposit(mineral)
  for _, row in ipairs(ask(640)) do
    if row[1] == mineral then return row[2] end
  end
  return 0
end

-- Travel unless already there
local function go(place)
  if place == location() then return 0 end
  return travel(place)
end

-- Game hours to fly to a place (-1 if unknown)
local function trip(place)
  local rows = ask(704, place)
  if rows[1] then return rows[1][3] end
  return -1
end

-- The cheapest other drone's sell order here: price, drone, units it will sell
local function best_offer(resource)
  local mine = me()
  for _, o in ipairs(ask(610, resource)) do
    if o[4] ~= mine then return o[1], o[4], math.max(o[3] - o[2], 0) end
  end
  return 0, 0, 0
end

-- The best other drone's buy order here: price, drone, units it will buy
local function best_bid(resource)
  local mine = me()
  for _, o in ipairs(ask(600, resource)) do
    if o[4] ~= mine then return o[1], o[4], math.max(o[2] - o[3], 0) end
  end
  return 0, 0, 0
end

local function sell_price(resource)
  local price, _, n = best_bid(resource)
  if n == 0 then return 0 end
  return price
end

local function buy_price(resource)
  local price, _, n = best_offer(resource)
  if n == 0 then return 0 end
  return price
end

-- Units of a resource sold at a place on the game day days_ago days ago
local function sold(resource, days_ago, place)
  for _, row in ipairs(ask(682, resource, days_ago)) do
    if row[1] == place then return row[2] end
  end
  return 0
end

-- What a good traded for, a unit, at a place over the last game day (the
-- price board, query 680); 0 if none traded there
local function board_price(resource, place)
  for _, row in ipairs(ask(680, resource)) do
    if row[1] == place and row[2] > 0 then return row[3] // row[2] end
  end
  return 0
end

-- Buy up to n here, cheapest first, from whoever sells; 0 when it bought
-- any. It reads the sell orders afresh before each pull (#502: each pull
-- waits for the drone's next turn, so by its fourth pull a list read once
-- was hours old, and other buyers had taken what it listed). A seller that
-- refuses is skipped for the next (#380 round 35: buying from the same
-- small miner lot again and again stalled factories for days); at most 6
-- tries, each an action
local function buy_here(n, resource)
  local result, tries, mine, refused = -12, 0, me(), {}
  while n > 0 and tries < 6 do
    local seller, avail
    for _, o in ipairs(ask(610, resource)) do
      if o[4] ~= mine and not refused[o[4]] and o[3] > o[2] then seller, avail = o[4], o[3] - o[2]; break end
    end
    if not seller then break end
    local take = math.min(n, avail)
    tries = tries + 1
    if pull(take, resource, seller) == 0 then n = n - take; result = 0 else refused[seller] = true end
  end
  return result
end

-- Sell up to n here, best price first, to whoever buys; 0 when it sold
-- any. A buyer that refuses is skipped for the next; at most 6 tries
local function sell_here(n, resource)
  local result, tries, mine = -12, 0, me()
  for _, o in ipairs(ask(600, resource)) do
    if n <= 0 or tries >= 6 then break end
    local want = math.max(o[2] - o[3], 0)
    local take = math.min(n, want, units(resource))
    if o[4] ~= mine and take > 0 then
      tries = tries + 1
      if push(take, resource, o[4]) == 0 then n = n - take; result = 0 end
    end
  end
  return result
end

local buy_from_station, sell_to_station = buy_here, sell_here

local function sell_all(resource)
  local n = units(resource)
  if n > 0 then sell_here(n, resource) end
end

-- Orders stay up until the drone posts another for the same resource and
-- side; each post costs a fuel. The _if_changed forms skip a post that
-- would change nothing. A clone starts with no orders of its own.
my_orders, my_orders_of = {}, 0
local function orders_mine()
  local id = me()
  if my_orders_of ~= id then my_orders, my_orders_of = {}, id end
end
local function sell_order(price, keep, resource)
  local r = perform(7, price, keep, resource)
  if r == 0 then orders_mine(); my_orders["s" .. resource] = {price, keep} end
  return r
end
local function buy_order(price, want, resource)
  local r = perform(8, price, want, resource)
  if r == 0 then orders_mine(); my_orders["b" .. resource] = {price, want} end
  return r
end
local function sell_order_if_changed(price, keep, resource)
  orders_mine()
  local o = my_orders["s" .. resource]
  if o and o[1] == price and o[2] == keep then return 0 end
  return sell_order(price, keep, resource)
end
local function buy_order_if_changed(price, want, resource)
  orders_mine()
  local o = my_orders["b" .. resource]
  if o and o[1] == price and o[2] == want then return 0 end
  return buy_order(price, want, resource)
end

-- A state machine: mode, and how many rounds in it
mode, ticks_in_mode = 0, 0
local function become(m) mode, ticks_in_mode = m, 0 end
local function in_mode(m) return mode == m end
local function tick() ticks_in_mode = ticks_in_mode + 1 end
local function ticks() return ticks_in_mode end

-- Messages: event IDs of broadcasts seen here or known at Earth, newest first
local message_event = 13
local function events(id, type)
  local ids = {}
  for i, row in ipairs(ask(id, -1, -1, type, -1, -1, -1, -1, -1, -1)) do ids[i] = row[1] end
  return ids
end
local function events_here(type) return events(803, type) end
local function events_earth(type) return events(804, type) end
local function event_drone(event) return first(820, event) end
local function message(event)
  local rows = ask(844, event)
  return rows[1] and rows[1][1] or nil
end

-- The drones this one launched: a list of {drone, wrecked}
local function my_clones()
  local list = {}
  for i, row in ipairs(ask(670)) do list[i] = {drone = row[1], wrecked = row[2] == 1} end
  return list
end

-- The first recipe that makes a resource (#380 round 36), or nil:
-- {protocol, makes (units of it), uses = {[resource] = units}, fuel, tool,
-- hours}
local function recipe(resource)
  local rows = ask(695, resource)
  if not rows[1] then return nil end
  local r = {protocol = rows[1][1], makes = 0, uses = {}, fuel = 0, hours = rows[1][4] / 60}
  for _, row in ipairs(rows) do
    local res, n = row[2], row[3]
    if res == resource and n > 0 then r.makes = n
    elseif n == 0 then r.tool = res
    elseif res == fuel and n < 0 then r.fuel = -n
    elseif n < 0 then r.uses[res] = -n end
  end
  return r
end

-- Asking about the world by name (#380, after round 39). Each function
-- asks one query (its number in the comment; the Queries page lists them)
-- and returns plain values, or records with named fields. A list of
-- records is a Lua list: for _, o in ipairs(sell_orders(fuel)) do ... end

-- rows -> a list of records with these field names, by column
local function records(rows, names)
  local list = {}
  for i, row in ipairs(rows) do
    local r = {}
    for k, name in ipairs(names) do r[name] = row[k] end
    list[i] = r
  end
  return list
end
-- rows -> a list of their first columns
local function column(rows)
  local list = {}
  for i, row in ipairs(rows) do list[i] = row[1] end
  return list
end
-- A query's first row as one record (nil if none)
local function record(rows, names) return records(rows, names)[1] end
local function text(id, ...)
  local rows = query(id, ...)
  local v = rows and rows[1] and rows[1][1]
  if v == nil or v == false then return nil end
  return tostring(v)
end

-- Companies and drones
local function company_name(c) return text(100, c or company()) end              -- 100
local function company_credits(c) return first(101, c or company()) end           -- 101
local function company_drones(c) return column(ask(110, c or company())) end      -- 110
local function drone_name(d) return text(200, d or me()) end                      -- 200
local function owner(d) return first(201, d or me()) end                          -- 201
local function where_is(d) return first(202, d or me()) end                       -- 202
local function hull(d) return first(203, d or me()) end                           -- 203
local function cargo_mass(d) return first(204, d or me()) end                     -- 204
local function now()                                                              -- 207
  return record(ask(207), {"day", "minute"}) or {day = 0, minute = 0}
end
local function hold(d) return records(ask(210, d or me()), {"resource", "units"}) end  -- 210
local function hull_name(h) return text(300, h or hull()) end                     -- 300
local function hull_capacity(h) return first(301, h or hull()) end                -- 301
local function capacity() return hull_capacity(hull()) end
local function space() return capacity() - cargo_mass() end

-- Resources and protocols
local function resource_name(r) return text(400, r) end                           -- 400
local function unit_mass(r) return first(401, r) end                             -- 401
local function best_sites(r) return records(ask(410, r), {"place", "abundance"}) end    -- 410
local function protocol_name(p) return text(500, p) end                           -- 500
local function protocol_minutes(p) return first(501, p) end                       -- 501
local function consumes(p) return records(ask(510, p), {"resource", "units"}) end  -- 510
local function produces(p) return records(ask(511, p), {"resource", "units"}) end  -- 511
local function retains(p) return records(ask(512, p), {"resource", "units"}) end   -- 512
local function consumers(r) return records(ask(520, r), {"protocol", "units"}) end -- 520
local function producers(r) return records(ask(521, r), {"protocol", "units"}) end -- 521
local function users(r) return records(ask(522, r), {"protocol", "units"}) end     -- 522

-- The market here: every order, best first. A sell order sells what its
-- drone holds above what it keeps (units); a buy order buys until its
-- drone holds what it wants (units: how many more)
local function sell_orders(r)                                                     -- 610
  local list = records(ask(610, r), {"price", "keep", "held", "drone"})
  for _, o in ipairs(list) do o.units = math.max(o.held - o.keep, 0) end
  return list
end
-- The share exchange (#469): orders for company c's shares that can trade
-- now; units: how many a pull or push can move
local function share_sell_orders(c)                                               -- 690
  local list = records(ask(690, c), {"price", "keep", "held", "company"})
  for _, o in ipairs(list) do o.units = math.max(o.held - o.keep, 0) end
  return list
end
local function share_buy_orders(c)                                                -- 691
  local list = records(ask(691, c), {"price", "want", "held", "company"})
  for _, o in ipairs(list) do o.units = math.max(o.want - o.held, 0) end
  return list
end
-- Shares (#471): what the company holds, who holds a company's shares,
-- its trades, and its last trade price (nil if it never traded)
local function shares_held()                                                      -- 692
  return records(ask(692), {"company", "units", "dividends", "price"})
end
local function shareholders(c)                                                    -- 693
  return records(ask(693, c), {"company", "units"})
end
local function share_trades(c)                                                    -- 694
  return records(ask(694, c), {"price", "units", "seller", "buyer", "day"})
end
-- Every company's shares on offer now, cheapest first (#473): company,
-- price, units, seller, and sales (the company's income after tax
-- a day, averaged over the last 7 game days; each share earns its part of it)
local function shares_for_sale()                                                  -- 696
  return records(ask(696), {"company", "price", "units", "seller", "sales"})
end
-- Every open offering of new shares, soonest deadline first (#485): company,
-- units offered, price, left (not yet committed), hours (game hours left),
-- count (its shares now), sales (its income after tax over the last game
-- day; each share earns sales / count of it), mine (shares your company has
-- committed), min (the fewest it issues at its deadline, #516: units for an
-- all-or-nothing offering)
local function share_offerings()                                                  -- 697
  return records(ask(697), {"company", "units", "price", "left", "hours", "count", "sales", "mine", "min"})
end
-- Company c's shares in all: 1,000 to start, more after it issues new ones
local function share_count(c)                                                     -- 698
  local row = ask(698, c)[1]
  return row and row[1] or 1000
end
local function share_price(c)
  local t = share_trades(c)[1]
  return t and t.price or nil
end
local function buy_orders(r)                                                      -- 600
  local list = records(ask(600, r), {"price", "want", "held", "drone"})
  for _, o in ipairs(list) do o.units = math.max(o.want - o.held, 0) end
  return list
end
local function volume_here(r, hours)                                              -- 620
  return record(ask(620, r, hours or 24), {"units", "credits", "trades"})
end
local function volume_everywhere(r, hours)                                        -- 621
  return record(ask(621, r, hours or 24), {"units", "credits", "trades"})
end
local function price_board(r)                                                     -- 680
  local list = records(ask(680, r), {"place", "units", "credits", "trades"})
  for _, b in ipairs(list) do b.price = b.units > 0 and b.credits // b.units or 0 end
  return list
end
local function sales(r, days_ago)                                                 -- 682
  return records(ask(682, r, days_ago or 0), {"place", "units", "price"})
end
local function taxes()                                                            -- 650
  return record(ask(650), {"permille", "last_day", "total"}) or {permille = 0, last_day = 0, total = 0}
end

-- What buying n units here would cost, cheapest sellers first (not your
-- own orders): the credits, and how many of the n are for sale
local function cost_to_buy(n, r)
  local cost, got, mine = 0, 0, me()
  for _, o in ipairs(sell_orders(r)) do
    if got >= n then break end
    if o.drone ~= mine and o.units > 0 then
      local take = math.min(n - got, o.units)
      cost, got = cost + take * o.price, got + take
    end
  end
  return cost, got
end
-- What selling n units here would bring, best buyers first, before the
-- sales tax: the credits, and how many of the n are wanted
local function sale_value(n, r)
  local value, sold_n, mine = 0, 0, me()
  for _, o in ipairs(buy_orders(r)) do
    if sold_n >= n then break end
    if o.drone ~= mine and o.units > 0 then
      local take = math.min(n - sold_n, o.units)
      value, sold_n = value + take * o.price, sold_n + take
    end
  end
  return value, sold_n
end

-- Every exchange's orders for a resource: live here, and as they stood an
-- hour of game time ago elsewhere (news takes time). Records: place, side
-- ("buy" or "sell"), price, units, drone, hours_old (0 or 1)
local function orders_everywhere(r)                                               -- 615
  local list = records(ask(615, r), {"place", "side", "price", "units", "drone", "hours_old"})
  for _, o in ipairs(list) do o.side = o.side == 1 and "buy" or "sell" end
  return list
end
-- The best prices for a resource at each place, from orders_everywhere
-- (not your own orders): quotes(r)[place] is {bid, bid_units, ask,
-- ask_units, stock, hours_old}: the best buy order's price and the units
-- it wants, the cheapest sell order's price and the units it sells (0
-- when there is none), and all the units on sale there
local function quotes(r)
  local q, mine = {}, me()
  for _, o in ipairs(orders_everywhere(r)) do
    if o.drone ~= mine and o.units > 0 then
      local p = q[o.place]
      if not p then
        p = {bid = 0, bid_units = 0, ask = 0, ask_units = 0, stock = 0, hours_old = o.hours_old}
        q[o.place] = p
      end
      if o.side == "buy" and o.price > p.bid then p.bid, p.bid_units = o.price, o.units end
      if o.side == "sell" then
        p.stock = p.stock + o.units
        if p.ask == 0 or o.price < p.ask then p.ask, p.ask_units = o.price, o.units end
      end
    end
  end
  return q
end
-- Units of a resource on sale at a place (here if no place): live here,
-- an hour old elsewhere
local function stock(r, place)
  local p = quotes(r)[place or location()]
  return p and p.stock or 0
end

-- The company's trades over the last hours (24 if not given): sales (the
-- credits buyers paid), taxes, purchases, net (sales - taxes - purchases),
-- and by_resource[r] = {sales, sold, tax, purchases, bought}
local function earnings(hours)                                                    -- 655
  local e = {sales = 0, taxes = 0, purchases = 0, net = 0, by_resource = {}}
  for _, t in ipairs(records(ask(655, hours or 24), {"resource", "sales", "sold", "tax", "purchases", "bought"})) do
    e.by_resource[t.resource] = t
    e.sales, e.taxes, e.purchases = e.sales + t.sales, e.taxes + t.tax, e.purchases + t.purchases
  end
  e.net = e.sales - e.taxes - e.purchases
  return e
end
-- Your company's sales after tax a day, averaged over the last `days` game
-- days (7 if not given): sales come in lumps, and one day is a poor guide
-- (#492). A company younger than that counts days it didn't exist as 0
local function earnings_per_day(days)
  days = days or 7
  local e = earnings(24 * days)
  return math.max(e.sales - e.taxes, 0) / days
end

-- Mining, wrecks, family
local function deposits()                                                         -- 640
  return records(ask(640), {"resource", "units", "capacity", "refill"})
end
local function wrecks()                                                           -- 660
  local list = records(ask(660), {"drone", "plundered"})
  for _, w in ipairs(list) do w.plundered = w.plundered == 1 end
  return list
end
local function wreck_hold(w) return records(ask(661, w), {"resource", "units"}) end    -- 661
local function lineage()                                                          -- 671
  return record(ask(671), {"parent", "generation"}) or {parent = 0, generation = 0}
end

-- Equips the drone (#506): a released drone starts with credits and no
-- equipment. Until it has tools (an Engine), it unpacks a kit aboard, or
-- buys `kit` here (Earth sells both kits) if it was released, or waits for
-- the kit its parent hands it if it is a clone. kit: mining_kit or
-- factory_kit
local function equip(kit)
  local clone = lineage().parent > 0
  while units(engine_module) == 0 do
    if units(factory_kit) > 0 or units(mining_kit) > 0 then
      unpack_kit()
    elseif clone then
      wait(60)
    else
      buy_here(1, kit or mining_kit)
      if units(factory_kit) == 0 and units(mining_kit) == 0 then wait(60) end
    end
  end
end

-- Places
local function place_name(p) return text(700, p or location()) end                -- 700
local function place_type(p) return first(701, p or location()) end               -- 701
local function orbits(p) return first(702, p or location()) end                   -- 702
local function position(p) return record(ask(703, p or location()), {"x", "y", "z"}) end -- 703
local trip_fields = {"distance", "seconds", "hours"}
local function trip_to(p) return record(ask(704, p), trip_fields) end             -- 704
local function trip_between(a, b) return record(ask(705, a, b), trip_fields) end  -- 705
local function orbiting(p) return column(ask(710, p or location())) end           -- 710
local function drones_at(p) return column(ask(720, p or location())) end          -- 720
local function wrecks_at(p)                                                       -- 721
  local rows = ask(721, p or location())
  return rows[1] and rows[1][2] or 0
end
local function minerals(p) return records(ask(730, p or location()), {"resource", "abundance"}) end -- 730

-- Events
local function events_affecting(d) return column(ask(800, d or me())) end         -- 800
local function local_events() return column(ask(801)) end                         -- 801
local function event(id)                                                          -- 845
  local e = record(ask(845, id), {"type", "type_name", "acting", "protocol", "affected", "resource",
                                  "quantity", "credits", "location", "place", "day", "seconds", "message"})
  if e then
    for k, v in pairs(e) do if v == false then e[k] = nil end end
  end
  return e
end

local function stdlib() return 19 end

-- Every name above, for scripts
_G.earth = earth
_G.moon = moon
_G.mars = mars
_G.phobos = phobos
_G.deimos = deimos
_G.fuel = fuel
_G.magmite = magmite
_G.kunspar = kunspar
_G.yalspar = yalspar
_G.vladnium = vladnium
_G.wolfite = wolfite
_G.mining_kit = mining_kit
_G.factory_kit = factory_kit
_G.cruiser_kit = cruiser_kit
_G.metals_a1 = metals_a1
_G.metals_a2 = metals_a2
_G.metals_a3 = metals_a3
_G.inorganics_a1 = inorganics_a1
_G.organics_a1 = organics_a1
_G.inorganics_a2 = inorganics_a2
_G.organics_a2 = organics_a2
_G.alloys_a1 = alloys_a1
_G.alloys_a2 = alloys_a2
_G.alloys_a3 = alloys_a3
_G.rods = rods
_G.travel = travel
_G.mine = mine
_G.refine = refine
_G.make = make
_G.wait = wait
_G.push = push
_G.pull = pull
_G.launch = launch
_G.plunder = plunder
_G.jettison = jettison
_G.broadcast = broadcast
_G.sell_shares = sell_shares
_G.buy_shares = buy_shares
_G.pull_shares = pull_shares
_G.push_shares = push_shares
_G.offer_shares = offer_shares
_G.commit_shares = commit_shares
_G.share_sell_orders = share_sell_orders
_G.share_buy_orders = share_buy_orders
_G.shares_held = shares_held
_G.shareholders = shareholders
_G.share_trades = share_trades
_G.share_price = share_price
_G.shares_for_sale = shares_for_sale
_G.share_offerings = share_offerings
_G.share_count = share_count
_G.ask = ask
_G.me = me
_G.location = location
_G.units = units
_G.company = company
_G.credits = credits
_G.today = today
_G.crowd = crowd
_G.engine_module = engine_module
_G.unpack_kit = unpack_kit
_G.unpack = unpack
_G.await_kit = await_kit
_G.equip = equip
_G.deposit = deposit
_G.go = go
_G.trip = trip
_G.best_offer = best_offer
_G.best_bid = best_bid
_G.sell_price = sell_price
_G.buy_price = buy_price
_G.sold = sold
_G.board_price = board_price
_G.buy_here = buy_here
_G.sell_here = sell_here
_G.buy_from_station = buy_from_station
_G.sell_to_station = sell_to_station
_G.sell_all = sell_all
_G.sell_order = sell_order
_G.buy_order = buy_order
_G.sell_order_if_changed = sell_order_if_changed
_G.buy_order_if_changed = buy_order_if_changed
_G.become = become
_G.in_mode = in_mode
_G.tick = tick
_G.ticks = ticks
_G.message_event = message_event
_G.events_here = events_here
_G.events_earth = events_earth
_G.event_drone = event_drone
_G.message = message
_G.my_clones = my_clones
_G.recipe = recipe
_G.fuel_floor = fuel_floor
_G.company_name = company_name
_G.company_credits = company_credits
_G.company_drones = company_drones
_G.drone_name = drone_name
_G.owner = owner
_G.where_is = where_is
_G.hull = hull
_G.cargo_mass = cargo_mass
_G.now = now
_G.hold = hold
_G.hull_name = hull_name
_G.hull_capacity = hull_capacity
_G.capacity = capacity
_G.space = space
_G.resource_name = resource_name
_G.unit_mass = unit_mass
_G.best_sites = best_sites
_G.protocol_name = protocol_name
_G.protocol_minutes = protocol_minutes
_G.consumes = consumes
_G.produces = produces
_G.retains = retains
_G.consumers = consumers
_G.producers = producers
_G.users = users
_G.sell_orders = sell_orders
_G.buy_orders = buy_orders
_G.volume_here = volume_here
_G.volume_everywhere = volume_everywhere
_G.price_board = price_board
_G.sales = sales
_G.taxes = taxes
_G.cost_to_buy = cost_to_buy
_G.sale_value = sale_value
_G.orders_everywhere = orders_everywhere
_G.quotes = quotes
_G.stock = stock
_G.earnings = earnings
_G.earnings_per_day = earnings_per_day
_G.deposits = deposits
_G.wrecks = wrecks
_G.wreck_hold = wreck_hold
_G.lineage = lineage
_G.place_name = place_name
_G.place_type = place_type
_G.orbits = orbits
_G.position = position
_G.trip_to = trip_to
_G.trip_between = trip_between
_G.orbiting = orbiting
_G.drones_at = drones_at
_G.wrecks_at = wrecks_at
_G.minerals = minerals
_G.events_affecting = events_affecting
_G.local_events = local_events
_G.event = event
_G.stdlib = stdlib
