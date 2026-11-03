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

-- Actions
local function travel(place) return perform(5001, place) end
local function mine(mineral) return perform(mineral + 10000) end
local function refine(mineral) return perform(mineral + 20000) end
local function make(resource) return perform(resource + 10000) end
local function wait(minutes) return perform(3, minutes) end
local function push(n, resource, drone) return perform(10, n, resource, drone) end
local function pull(n, resource, drone) return perform(11, n, resource, drone) end
local function launch(drone, kit) return perform(12, drone, kit) end
local function plunder(wreck) return perform(34, wreck) end
local function jettison(n, resource) return perform(1, n, resource) end
local function broadcast(text) return perform(40, text) end

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
-- any. A seller that refuses (its stock moved since the look, or its order
-- keeps more back) is skipped for the next (#380 round 35: buying from the
-- same small miner lot again and again stalled factories for days); at
-- most 6 tries, each an action
local function buy_here(n, resource)
  local result, tries, mine = -12, 0, me()
  for _, o in ipairs(ask(610, resource)) do
    if n <= 0 or tries >= 6 then break end
    local avail = math.max(o[3] - o[2], 0)
    if o[4] ~= mine and avail > 0 then
      local take = math.min(n, avail)
      tries = tries + 1
      if pull(take, resource, o[4]) == 0 then n = n - take; result = 0 end
    end
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

local function stdlib() return 17 end

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
_G.stdlib = stdlib
