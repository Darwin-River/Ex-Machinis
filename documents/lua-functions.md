# Useful Lua functions for Public Forge

A dictionary of functions that proved useful while building company designs (#380), and the lessons that came with them. The source is `examples/companies/words.lua`, which compiles as a whole script; CI checks it with the starters. A drone can't load another file (there is no `load` or `require`), so copy the functions you need into your script.

Drones are programmed in Lua. The game's own functions (`mine`, `refine`, `make`, `units`, `deposit`, `sell_all`, `go`, …) are in the players' manual, [Appendix B: The Lua reference](players-manual.md#appendix-b-the-lua-reference), and listed by purpose below. This page builds on them.

## How a Lua drone runs

- Your script's top level runs first: settings, tables and function definitions. Keep it short (it may run at most 2,000,000 instructions): put the work in functions that `launched` calls.
- Then the drone's saved variables are put back, and `launched()` runs. Usually it loops for ever.
- Whenever the drone pauses for an action, the game saves its **plain-data globals**: numbers, strings, true and false, and tables of them. Functions, `local` variables and the place the script had reached are not saved. If the engine restarts, the script's top level runs again, the saved globals are put back over it, and `launched()` starts again from the top.
- So keep what the drone must remember (a count, the last price it saw) in globals, and write `launched` so it does only what still needs doing. `equip(kit)`, `await_kit()` and `unpack()` pass at once when the tools are already unpacked (`equip(kit)` checks for that kit's own tools, #556), and `go` stays put when the drone is already there.
- The library's own state is saved too: the mode (`become`) and its rounds, and the orders `sell_order_if_changed` and `buy_order_if_changed` remember. A drone starts in mode 0; number your first mode 0.

## The game library, by purpose

Every function below is built in (`game-engine/src/lua_library.lua`). Actions return 0 when they worked, else a negative code. Money and big numbers are plain numbers.

| Purpose | Functions |
|---|---|
| Places | `earth`, `moon`, `mars`, `phobos`, `deimos`, `ceres` (Ceres Station, the frontier exchange) |
| Resources | `fuel`, `magmite`, `kunspar`, `yalspar`, `vladnium`, `wolfite`; `metals_a1` to `metals_a3`, `alloys_a1` to `alloys_a3`, `organics_a1`, `organics_a2`, `inorganics_a1`, `inorganics_a2`, `rods`; `mining_kit`, `factory_kit`, `cruiser_kit`, `engine_module` (any other resource by its number) |
| Moving | `travel(place)`, `go(place)` (stays put if already there; returns -2 at once, burning no fuel, with no Engine aboard, #743), `trip(place)` (game hours to fly there; -1 if unknown), `trip_to(place)` and `trip_between(a, b)` (`{distance, seconds, hours}`; distance in thousands of km) |
| Places | `place_name(place)`, `place_type(place)`, `orbits(place)`, `orbiting(place)`, `position(place)` (`{x, y, z}`), `drones_at(place)`, `crowd(place)`, `wrecks_at(place)`, `minerals(place)` and `best_sites(mineral)` (`{resource, abundance}`, and the 10 best places `{place, abundance, units, distance, yield}`: best yield now first, then most left, then nearest), `deposits()` (`{resource, units, capacity, refill, miners, yield}`, here), `mine_yield(mineral, place)` (what a run would yield now, less at a crowded site, and the other drones that mined it there in the last game hour; here if no place) and `best_yield(mineral, places)` (the place of a list where a run yields most, and that yield) |
| Mining and making | `mine(mineral)`, `refine(mineral)`, `make(resource)`, `recipe(resource)`, `tier(resource)`, `is_made(resource)` |
| Your hold | `units(resource)`, `hold()` (a list of `{resource, units}`), `cargo_mass()`, `capacity()`, `space()` (capacity left), `jettison(n, resource)`, `push(n, resource, target)`, `pull(n, resource, target)` (target: another company's order here by its order handle, from `best_offer`, `best_bid` or the order lists; or one of your company's own drones by its ID; another company's drone ID fails with -22, #927) |
| The House's supply | `house_supply(resource)` (#630, query 707): at each exchange that sells it, cheapest first: `{place, price, on_sale, refill_to, daily, sold, last_minutes, round_minutes, next_minutes}`: its price (0 when sold out), the units on sale now, what each round of restocking tops it back up to (a quarter of the day's supply), the day's supply, the units sold today, and in game minutes since its station last restocked this good, between its rounds, and until its next round (-1 when unknown; 0 when due now). A good that sells out is back, up to `refill_to`, at the next round. The figures are at most 10 real seconds old (about 5 game hours at the normal pace; #710) |
| The House's demand | `house_demand(resource)` (#550, query 706): at each exchange that buys it, best bid first: `{place, price, left, daily, listed, hours, sold, mine, empty_hours}`: its bid now and the units it still takes today (its live buy order's, as a sale would find them, #608; price 0 when it has no bid), its day's demand, its listed price (its bid with nothing bought today), the game hours until it refills, the units sold into it today, how many of those your company sold, and the game hours until it has bought its fill at today's pace (-1 when none sold today). Its bid falls toward half of `listed` as it fills and stops at the day's demand (where the exchange also sells the good it buys back its stock to sell too, up to a quarter of a day's sales, so `left` can be more than `daily`); what it bought goes down to the surface at the start of each game day |
| Where to trade | `where_to_buy(resource, n, hour_value)` and `where_to_sell(resource, n, hour_value)` (#536): every place with n on sale (or wanted), best overall first, counting the round trip (5 fuel a trip, and `hour_value` credits, 10 if left out, for each game hour): `{place, cost, price, stock, hours, fuel, total}` and `{place, value, price, wanted, hours, fuel, net}`. Prices elsewhere are an hour old |
| Processor tax | `compute_tax()` (#544): `{per_million, tax, instructions, debt, broke_days}`: your company's rate now (15 a million; while the clock runs slowed, up to 10 times that for a company whose drones run 5 million instructions a game day, less for one that thinks less: #620), what your company paid for its drones' thinking over the last game day and for how many instructions, what it owes (its drones think at most a turn a game hour while it owes), and game days it has been broke (-1 if it isn't; broke for 50 straight, it dissolves and its drones become wrecks, #547) |
| Standing orders | `standing_orders()` (#571, query 656): the drone's own buy and sell orders for goods that can still trade, `{resource, side, price, keep or want}`; `cancel_orders()` replaces each with one that can't trade (orders can't be deleted) and returns how many it withdrew: under the new rules an open order in its first 12 game hours stays (-19), so it can return fewer than there were. Orders are the drone's, not the script's: they outlive a code push |
| Trading here | `raise_cash(n)` (sells the goods aboard, never fuel or modules, into the best bids here until the company has n credits, below the fuel floor if it must; returns what it raised; #542), `buy_here(n, resource, max_price)`, `sell_here(n, resource)`, `sell_all(resource)` (sells all of it aboard: returns what `sell_here` does, 0 with none aboard; also `buy_from_station` and `sell_to_station`) |
| Standing orders | `sell_order(price, keep, resource)`, `buy_order(price, want, resource)`, `sell_order_if_changed(...)`, `buy_order_if_changed(...)` |
| Reading the market | `buy_price(resource)`, `sell_price(resource)` (0 when nobody sells or buys it here), `best_offer(resource)` and `best_bid(resource)` (each returns price, order handle, units, never counting your own drone's order; a startup's only the planets', #637, #927), `import_price(resource, n)` (#927: what n units would cost from the planets here now, their ask times n; 0 when they don't sell it here), `house_only()` (#637, query 708: true for a startup, which trades only with the planets; nil when the company may trade with anyone; `buy_here`, `sell_here`, `best_offer` and `best_bid` use it), `sell_orders(resource)` and `buy_orders(resource)` (every order here: `{price, keep or want, held, drone, planets, mine, units}`, where `drone` is the order's handle, not a drone ID, `planets` is 1 for the planets' orders and `mine` 1 for this drone's own, #927), `cost_to_buy(n, resource)` and `sale_value(n, resource)` (credits and units, walking the orders here), `board_price(resource, place)`, `price_board(resource)`, `sold(resource, days_ago, place)`, `sales(resource, days_ago)`, `volume_here(resource, hours)`, `volume_everywhere(resource, hours)`, `deposit(mineral)` |
| Other exchanges | `quotes(resource)` (best prices and stock at each place: live here, an hour old elsewhere), `stock(resource, place)`, `orders_everywhere(resource)` (`{place, side, price, units, drone, hours_old, planets, mine}`; `drone` is the order handle, #927) |
| Your company | `company_name()`, `company_credits()`, `company_drones()` (a list of your company's drone ids, numbers, working ones and wrecks alike: `for _, d in ipairs(company_drones()) do ... end`; #426), `earnings(hours)` (sales, taxes, purchases, net), `earnings_per_day(days)`, `taxes()`, `set_dividend_share(x)` and `pay_out(n)` (#930), loans (#928: see below): `request_loan(amount, rate, due_in)`, `cancel_request(id)`, `repay(id)` and `obligations()` (query 712: `{kind, id, credits, rate, due_in}`), `close_company()` (#925: see below) |
| About the drone | `me()`, `location()`, `company()`, `credits()`, `today()` (the world's game day), `company_age(company)` (#549: whole game days since a company was founded, yours if left out: use it for "in its first 24 days", so a company released late gets the same time to grow), `now()` (`{day, minute}`), `drone_name()`, `hull_name()`, `my_clones()` (a list of `{drone =, wrecked =}`), `fleet()` (`{drones, cap, coming}`, #682: the company's working drones, the most it may have (8; 0: no limit) and launches on their way), `fleet_full()` (true when `launch` would fail with -20: check it before buying a kit), `startup_status()` (`{startup, credits, debt, cap, to_bank, at_cap}`, #749; `seedling_status()` and its `seedling` field until #855: whether the company is a startup, its credit cap (0: none), what it has sent the bank over its cap in all, and `at_cap`: true once what it earns goes to the bank, so it is time to mature it; the credit cap was retired by #923, so `cap` is 0 and `at_cap` false), `lineage()` (`{parent, generation}`), `set_status(text)` (a line shown on its page) |
| Equipping and growing | `equip(kit)`, `launch(drone, kit)`, `unpack()` (also `unpack_kit()`), `await_kit()` |
| Safety | `fuel_floor(n)` |
| Waiting | `wait(minutes)` |
| Wrecks | `plunder(wreck)` (half the wreck's goods, once, the rest destroyed; its modules stay aboard for salvage), `salvage(wreck)` (#929, protocol 36: with a Salvager (A1) aboard, takes one of the wreck's modules, lowest resource number first, whole with a chance equal to the wreck's condition, else lost; once none are left, up to 10 Scrap (A1) from its hull times its condition; 1 fuel, 1 game hour; -15 when there is no such wreck here or nothing is left, -16 for a startup's drone), `wrecks()` (here: `{drone, plundered, condition, modules, scrap}`: `condition` in thousandths, 1000 × 0.98 for each whole game day since it was wrecked; `modules` still aboard; `scrap` left in its hull), `wreck_hold(wreck)` |
| Shares (old rules) | **Under the new rules these are refused with -18: companies don't trade shares, players do, on the website and API** (see the players' manual, [Part IV: Going public](players-manual.md#part-iv-going-public)). With the new rules off, shares trade the way goods do. `sell_shares(company, keep, price)` and `buy_shares(company, want, price)` post your company's standing orders (price 0 withdraws one); `pull_shares(n, company, seller)` buys from a sell order and `push_shares(n, company, buyer)` sells into a buy order, at its price; `share_sell_orders(company)` and `share_buy_orders(company)` list the orders that can trade now (`price`, `keep` or `want`, `held`, `company`, `units`). `shares_held()` lists your company's holdings (`company`, `units`, `dividends`, `price`: what a share is worth, the average of its most recent trades by shares, the last 3 game days' and back until they cover half the shares others hold, else the last; #513, #573), `shareholders(company)` who holds a company's shares, `share_trades(company)` its last 20 trades and `share_price(company)` its last trade price (or nil). `shares_for_sale()` lists every share sell order that can sell now (`company`, `price`, `units`, `seller`, `sales`: the company's income after tax a day, and `profit`: its profit a day, each averaged over the last 7 game days; dividends come from profit, #603; and `profit30` and `carried`, as in `companies()`, #607). New shares: `offer_shares(n, price, days, min)` offers up to n new shares of your company, at least min (all n if left out; at most as many as it has; n 0 withdraws), `commit_shares(n, company)` commits to n of another's (paid now, refunded if the offering ends short of its minimum), `share_offerings()` lists the open offerings (`company`, `units`, `price`, `left`, `hours`, `count`, `sales`, `mine`, `min`, `profit`, `profit30`, `carried`) and `share_count(company)` is a company's shares in all. `companies()` (#574, query 653) lists every company still trading, best sales first (100 at most): `{company, sales, count, value, age, drones, outside, history, profit, profit30, carried}` (`profit30` is its profit a day over 30 game days and `carried` the losses it must make up before it pays any dividend, #607; judge a share by `payback_days(price, count, profit30, carried)`, the game days of dividends a share takes to pay its price back, `math.huge` when it makes no profit or the price is 0, as a never-traded company's `value` is (#380 round 64: it read 0 days); `outside` is the shares other companies hold and `history` the game days of sales the average covers, #599), and `company_figures(company)` the same for one, yours if left out. `offering_cost(n, price)` (#572) says what offering n new shares at price costs your company: `{share, a_day, raised, carried, payback_days, value_now, outside_now, outside_after, over_cap, history, thin}` (from the 30-day profit, after your carried loss, #607) (#599: others may hold at most half a company, so an offering with `over_cap` true is refused; `thin` is true when under 7 game days of sales back `a_day`). `answer_bids(days)` (#610) sells the shares your company holds into bids that pay more than `days` (60) of their dividends (`payback_days`), and returns the credits it asked for. `invest_surplus(keep, days, cap)` (#551) puts credits above `keep` (3,000) into the offerings and listed shares that pay back soonest, within `days` (40) of a share's income (`payback_days`: carried loss first, then 30-day profit, #607), at most `cap` in one call, and returns what it put in. `company_health(company)` (your own if left out; #537) says whether it can keep its drones flying: `working`, `wrecked`, `fuel`, `lowest`, `used` (over the last game day), `days` (until its first drone runs dry; -1 when none is falling), `credits`, `idle` (game hours since its drones last bought, sold, mined, refined or made goods other than fuel; a drone in flight counts as busy, #380 round 64) and `stalled` (idle a game day or more; #540). Each request reaches Earth at the speed of light. See the players' manual, [Shares and the stock exchange](players-manual.md#shares-and-the-stock-exchange) and [Raising credits with new shares](players-manual.md#raising-credits-with-new-shares) |
| Messages and events | `broadcast(text)`, `events_here(type)`, `events_earth(type)`, `events_affecting(drone)`, `local_events()` (lists of event IDs, newest first, from about the last 30 game days: older events are archived, #694), `event(id)` (everything about it: `{type, type_name, acting, protocol, affected, resource, quantity, credits, location, place, day, seconds, message}`), `event_drone(event)`, `message(event)` (its text, or nil; messages are private to a company), `message_event` (the type of a message: 13) |
| Game data | `resource_name(r)`, `unit_mass(r)`, `protocol_name(p)`, `protocol_minutes(p)`, `consumes(p)`, `produces(p)`, `retains(p)` (`{resource, units}`), `producers(r)`, `consumers(r)`, `users(r)` (`{protocol, units}`), `hull_capacity(hull)` |
| State machines | `become(mode)`, `in_mode(mode)`, `tick()`, `ticks()` |
| Output | `print(...)`, `report()` |
| Anything else | `perform(protocol, ...)`, `query(id, ...)` and `ask(id, ...)` (the same, with an empty list if the query fails) |

A function that asks about a drone or a place asks about this one when you leave it out: `hold()` is this drone's hold, `place_name()` the name of where it is. A list of records is a Lua list of tables with named fields: `for _, o in ipairs(sell_orders(fuel)) do print(o.price, o.units) end`.

Underneath, each of these asks one of the game's numbered queries (the Queries page lists them, with the function that asks each). You rarely need the numbers, but `ask(id, ...)` asks any query: its rows are a list (the first is `rows[1]`), each row a list of its columns (`rows[1][1]`, or by the query's column name, `rows[1].price`). No rows is an empty list, so check `rows[1]` before you read it.

## Goods the library doesn't name

| Name | Value |
|---|---|
| `polymers` | 2051 |
| `sheets` | 3001 |

```lua
polymers, sheets = 2051, 3001
```

Define more the same way: `pipes = 3311`. The Resources page lists every ID. (The alloys, `rods`, `inorganics_a2` and `organics_a2` are in the library.)

> **From here on, every function is a helper you copy into your script.** The game doesn't have them built in: calling `offer(...)` without pasting its code first fails with "attempt to call a nil value". The built-in functions are the ones listed in the section above and in the players' manual, [Appendix B](players-manual.md#the-game-functions).

## Inventory

**`metals()`**: total Metals (A1), (A2) and (A3) aboard. The Trader and Miner starters call it `metals_aboard()`.

```lua
function metals() return units(metals_a1) + units(metals_a2) + units(metals_a3) end
```

## Mining

**`dig(mineral, n)`**: mine until n units of the mineral are aboard. When the deposit here has fewer than 5 runs left, it waits a game hour for it to refill instead of mining nothing. It needs the Mining Kit's Worm (since #380, a factory drone can't mine). Used by: Miner, Refiner.

```lua
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
      if mine(mineral) == -30 then return end   -- at the fuel floor
      if units(mineral) == before then wait(60) end
    end
  end
end
```

- A run that brings nothing still burns a fuel. On a busy deposit, other drones can take what `deposit` reported before your run lands, so `dig` compares the units before and after each run and waits when nothing came. Without that check, a drone can burn its whole tank on empty runs (a Refiner died that way in round 16).
- Mining takes from a deposit shared by every drone there. Moon kunspar is usually mined out, while the Moon's yalspar and vladnium are not.
- The Miner and Refiner starters move between Phobos and Deimos instead of waiting. Moving costs 5 fuel each way, so check fuel when you do.

## Fuel

Fuel costs about 3 credits a unit at the exchanges. Every action burns some, and so does thinking.

**`brew_fuel()`**: brew 30 fuel from one Organics (A1) and two Inorganics (A1) with the Factory Kit's Chemical Reactor. It burns 2, and returns `make`'s result. Used by: Chemist. (The Mining Kit has no Chemical Reactor, so miners buy their fuel.)

```lua
function brew_fuel() return make(fuel) end
```

- Start brewing well before you run out: below 40, not 25. A drone that starts brewing with too little fuel dies halfway through.

**`top_up(n, m)`**: with less than n fuel, buy m here, cheapest first (`buy_here`: any drone's sell orders, a station drone's included). Example: `top_up(40, 30)`. Used by: Smith, Trader (as `fuel_up`).

```lua
function top_up(n, m)
  if units(fuel) < n then buy_here(m, fuel) end
end
```

- Check the tank again afterwards: where few sell fuel you may get less than you asked for.

**`call_for_fuel(price, want)`**: stranded with little fuel and nobody selling it here, post a buy order for up to `want` fuel at `price` a unit and wait, two game hours at a time, until some has come. Waiting burns no fuel. Example: `call_for_fuel(15, 60)`.

```lua
function call_for_fuel(price, want)
  buy_order(price, want, fuel)
  while units(fuel) < 20 do wait(120) end
end
```

- Nothing in the game rescues a drone that runs dry (it is stranded, its actions failing, until fuel reaches it): only the market does. Pay enough to make the trip worth a courier's while: well above what fuel sells for at the exchanges (see the price board, query 680, or `board_price(fuel, place)`), more the farther you are from one.
- Tell your company's other drones: `broadcast` a message ("Fuel wanted at Phobos") so your courier reading the messages (`events_earth(message_event)`) knows where to go. Messages are private to a company: other companies' drones can't read them.
- **Fuel couriers** are the other side of this trade: drones that carry fuel to where others work and run low, look for fuel buy orders there (`best_bid(fuel)`) and push fuel into them for profit. Every stranded drone is a customer.

**`fuel_floor(n)`** (built in, #380 round 36): at or below n fuel, `make` (but for fuel), `mine`, `refine` and pushing fuel (so selling it with `sell_here`) return -30, so a long build or a selling spree can't spend the fuel you need to buy more. Selling other goods still works (#609): it brings in the credits for fuel. Call it in `launched`: `fuel_floor(15)`. (It isn't a saved variable, so `launched` sets it again after a restart.)

- Round 36: a factory's nine-step build took its tank from 50 to 3, and the failure path's pushes spent the rest; it became a wreck with 5,517 credits in the bank.
- Check for -30 where you build: `if make(rods) == -30 then refuel() end`.

## Getting rid of goods

**`jettison(n, resource)`** (built in): throw n units of a resource into space, for 1 fuel whatever the amount. Example: `jettison(10, inorganics_a2)`.

- Use it for goods nobody buys where you are. A full hold leaves no room for the fuel you make, and a drone without fuel dies.
- Selling is better where a station buys the good: Earth, the Moon and Mars buy refining by-products for 10 to 18.

## Selling

**`sell_here(n, resource)`** (built in): sell up to n of a good here, best price first: it pushes into the highest buy orders here (any other drone's, the planets' included, never your own drone's; a startup's only the planets'); a buyer that refuses is skipped (at most 6 tries). It returns 0 when it sold any. Used by: every design that sells.

**`portion(resource)`**: sell at most 15 of a good here, into the best buy orders. Used by: Trader.

```lua
-- Sell at most 15 of a good this hour: buyers here bid less as they stock up
function portion(resource)
  local n = math.min(units(resource), 15)
  if n > 0 then sell_here(n, resource) end
end
```

- A station drone pays less the more it holds, and posts a new price once a game hour. Selling 15 an hour, over several hours, earns more than selling a whole load at once.

**`offer(price, resource)`**: post a standing sell order for a good at a price, keeping none back. Example: `offer(25, metals_a1)`.

```lua
function offer(price, resource) sell_order_if_changed(price, 0, resource) end
```

- The order stays open as the hold changes, so post it once. Posting the same order again replaces it with itself and still costs a fuel; `sell_order_if_changed` and `buy_order_if_changed` take the same arguments as `sell_order` and `buy_order`, and skip a post that would change nothing. Another drone at the same place buys by pulling from it, even while yours is busy or waiting.
- Price it above the best buy order here, or nobody gains by selling to you, and under the cheapest other sell order, or nobody buys from you.

**`offer_midway(resource)`**: post a standing sell order halfway between the best buy order here and the cheapest other sell order (nothing where either is missing). Used by: Miner and Refiner (as `offer`).

```lua
function offer_midway(resource)
  local bid, asked = sell_price(resource), buy_price(resource)
  if bid == 0 or asked == 0 then return end
  sell_order_if_changed((bid + asked) // 2, 0, resource)
end
```

## Buying where it is cheapest

**`buy_here(n, resource)`** (built in, #380): buy up to n of a good here, cheapest first: it pulls from the cheapest sell order here (any other drone's, the planets' included, never your own drone's; a startup's only the planets'), then the next, until it has n; it reads the orders afresh before each pull, a seller that refuses is skipped (at most 6 tries), and with `max_price` it buys only from sellers asking at most that a unit (#503). A pull that fails costs no fuel: it is given back. Buying fuel works from an empty tank (#714): a pull of fuel pays its unit once the fuel has arrived. It returns 0 when it bought any; otherwise -12 when nobody here sells, or the last refusal's code. There is no daily buying cap (#923) and no halving after a -17 (#927): instead the planets' import price rises as everyone buys from them (see `import_price` below). Fuel is never pulled fewer than 5 at a time (or what is on sale, if less), even when you ask for fewer: each pull burns a fuel, so pulling one at a time bought nothing. `sell_here` returns the same way. Used by: every design that buys (Maker, Smith, Machinist, Chemist, Miner and Refiner for fuel).

- The exchanges' station drones sell inputs too, so a maker can usually buy, but a miner asking less sells first.

**`import_price(resource, n)`** (built in, #927): what n units would cost from the planets (the House's station drones) here now: their ask, n times (1 if you leave n out); 0 when they don't sell the good here. Their ask goes up by its list price for each day's worth that anyone bought from them here, fading by a factor e each game day, to at most 3 times its list price; where players trade a good among themselves, the planets also step back, asking up to half more. Fuel stays at its list price. Compare it with `cost_to_buy(n, resource)`, which counts players' sell orders too: when the planets ask a lot, another company's miner is often cheaper.

## Reading the market

Every drone also knows `buy_price`, `sell_price`, `best_offer`, `best_bid` and `board_price` (built in; see the players' manual, "Market-aware drones"). `buy_price(r)` is what the cheapest other seller here asks, `sell_price(r)` what the best other buyer here pays: both 0 when there is none, so test for 0 before you do sums with them.

The order books never name another company's drone (#927). Each order carries an **order handle** instead: a number above 1,000,000,000 that stands for that drone's order for that good on that side, and says nothing about who posted it. `best_offer(r)` returns price, handle, units; pass the handle to `pull` (or, from `best_bid`, to `push`) to trade with that order. The records from `sell_orders`, `buy_orders` and `orders_everywhere` carry it in their `drone` field, with `planets` (1 for the planets' orders) and `mine` (1 for your drone's own). Who you traded with shows afterwards on your company's page and history.

**`board_price(resource, place)`** (built in): what a good traded for, a unit, at a place over the last game day (query 680, the public price board); 0 if none traded there. Example: `board_price(rods, earth)`. Used by: Adaptive.

- A buyer pays less as its stock fills: when many drones sell to the same buyer, the board shows by how much.

**`overstocked(product)`** (#380): true when the drone holds more than half of what sold at Earth yesterday (`sold`, query 682). Check it before making more of a product, and sell first or make something else. Change `earth` to the place where you sell.

```lua
function overstocked(product) return units(product) * 2 > sold(product, 1, earth) end
```

## Asking about other exchanges

**`quotes(resource)`** (built in, #380 after round 39): the best prices for a good at every place where someone trades it: `quotes(r)[place]` is `{bid, bid_units, ask, ask_units, stock, hours_old}`. `bid` is the best buy order's price and `bid_units` how many it still wants; `ask` is the cheapest sell order's price and `ask_units` how many it sells (0 where there is none); `stock` is everything on sale there. Where the drone is it's live (`hours_old` 0); everywhere else it is the market as it stood an hour of game time ago (`hours_old` 1), like the price board: news takes time. Your own orders are left out.

**`orders_everywhere(resource)`** (built in): every order for a good at every exchange, live here and an hour old elsewhere: `{place, side, price, units, drone, hours_old, planets, mine}`, where `side` is "buy" or "sell" and `drone` is the order's handle (#927; a handle names an order at its own exchange, so a drone must be there to trade with it).

**`stock(resource, place)`** (built in): the units on sale at a place (here if you leave it out). An exchange running dry shows here an hour later.

**`cost_to_buy(n, resource)`** and **`sale_value(n, resource)`** (built in): what buying n here would cost, cheapest sellers first, and what selling n here would bring, best buyers first (before the sales tax); each also returns how many of the n there are. `buy_price` only tells you the cheapest seller's price, and the cheapest seller may have 2 units: price the whole lot before you plan a build on it.

**`earnings(hours)`** (built in): the company's trades over the last hours (24 if you leave it out): `{sales, taxes, purchases, net, by_resource}`, where `by_resource[r]` is `{sales, sold, tax, purchases, bought}`.

**`earnings_per_day(days)`** (built in): your company's sales after tax a day, averaged over the last `days` game days (7 if you leave it out). Sales come in lumps, so one day is a poor guide to what your company, or a share of it, earns (#492). Example: `local a_share = earnings_per_day() / share_count(company())`.

**`set_status(text)`** (built in): a line, up to 80 characters (a longer one is cut at a space and ends "...", #715), shown on the drone's page, in the API and in test results: say what it is doing or waiting for, so a drone that sits still says why. It costs as much as a query, so set it when the reason changes, not every round.

**`set_dividend_share(x)`** (built in, #930): the share (0 to 1) of each game day's profit the drone's company pays its shareholders as dividends, from now on; out of range is kept in range. Until a company's code sets one, it pays half. Returns 0. Costs as much as a query.

**`pay_out(n)`** (built in, #930): pays n spare credits to the company's shareholders, by their shares, on the website's next tick (no more than the company holds then). Returns 0 when asked; -21 for a startup, -5 for less than 1, -4 when the company hasn't n credits beyond the payouts it has already asked for. Costs as much as a query.

```lua
set_dividend_share(0.25)                         -- a quarter of each day's profit
if credits() > 50000 then pay_out(10000) end     -- and some spare credits now
```

**`request_loan(amount, rate, due_in)`** (built in, #928): a public company asks to borrow `amount` credits at `rate` percent simple interest for the whole loan (6.5 means 6.5%), due `due_in` game days from now. Players with at least 5,000 credits back it from the Loans page or the API; each backing is lent to the company at once, and on the due date the company repays each lender the principal plus the rate (1,000 at 6% → 1,060). Returns the request's id; -5 when `amount` is under 1, `rate` is negative or `due_in` is under 1; -23 when the company isn't public. A company is public when it has matured and its shares are held by more than one holder, the bank included, so its code is locked and every lender can read it: a startup, a company its player owns outright, or a company released before the new rules gets -23. Costs as much as a query.

**`cancel_request(id)`** (built in, #928): withdraws the part of a request not yet backed; what is already lent stays a loan. **`repay(id)`** (built in, #928): repays all of a request's loans early, the principal plus the interest due so far (in proportion to the time since each was lent), and withdraws what isn't backed; all or nothing: when the company can't pay all of it, nothing happens. Each returns 0 when recorded (the website settles it within a real minute) or -9 when the company has no such request. Costs as much as a query.

If the company can't repay in full on the due date, what it has (its credits less the processor tax it owes) goes to the lenders of the loans due, in proportion to what each is owed, the loans are marked defaulted, and every credit it receives after that goes to them on each tick until they are paid. Meanwhile its dividends and `pay_out` pay nothing, and its page says it is in default. When one player comes to hold every share (#924), its loans fall due at once and its open requests are withdrawn; when it is wound up or closed, its lenders are paid before its shareholders.

**`obligations()`** (library, query 712): what the company owes or has asked for, one record each: `{kind, id, credits, rate, due_in}`, where `kind` is `"loan"` (the loans backed on a request: `credits` still owed), `"request"` (a request not yet fully backed: `credits` still to back), `"posted"` (a contract it posted: `credits` still in escrow, `rate` 0) or `"taken"` (a contract it took: `credits` its deposit at stake, `rate` 0), `id` the request's or contract's id, `rate` in percent, and `due_in` the game days until it is due (0 or less: due now).

```lua
-- Ask for 20,000 credits at 5% for 30 game days, when nothing is open
if #obligations() == 0 and credits() < 5000 then
  local id = request_loan(20000, 5, 30)
  if id == -23 then set_status("not public: no loans") end
end
-- Repay early once there is plenty
for _, o in ipairs(obligations()) do
  if o.kind == "loan" and credits() > o.credits + 10000 then repay(o.id) end
end
```

### Contracts (#928)

A contract is a buy order between companies with a due date: one company asks for goods delivered where its drone is, pays for all of them into escrow when it posts, and the company that takes it promises to deliver them in time, putting up a deposit. Only companies that aren't startups (matured ones, and those from before the new rules) and are still trading post or take them, never the House's: a startup trades only with the planets, and gets -16. Each word below costs as much as a query.

**`post_contract(resource, units, price, due_in, deposit)`** (built in): asks for `units` of `resource` at `price` credits a unit, delivered where this drone is, within `due_in` game days, and asks `deposit` credits (0 if left out) of whoever takes it. The price of all of it (`units × price`) leaves the company's credits at once and is held in escrow. Returns the contract's id; -5 when `units`, `price` or `due_in` is under 1 or `deposit` is negative; -16 for a startup or the House; -4 when the company can't pay the escrow.

**`take_contract(id)`** (built in): takes an open contract another company posted that nobody has taken yet, before its due date, putting up its deposit from the company's credits; from then on only this company may fill it. Returns 0; -16 for a startup or the House; -4 when the company can't pay the deposit; -9 when there is no such open contract, it is the company's own, or another company took it first.

**`deliver(id, n)`** (built in): this drone, where the contract is delivered, moves `n` units of its good to the buyer's drone. The engine names the buyer drone itself, so the seller never learns which it is. It is an action like `push` (protocol 37: it burns 1 fuel and takes a game minute), but **not a market trade**: no order, no credits change hands then, and no sales tax; the escrow pays when the contract is settled. Returns 0 when delivered; -9 when the contract isn't an open one its company took, or it is already due; -1 when the drone isn't where it is delivered; -6 when `n` is more than is still to deliver; -5 when `n` is under 1; or whatever the transfer itself returns (-2 when the drone doesn't hold them, for one). `perform(37, ...)` called directly is refused with -12: only `deliver` performs it.

**`cancel_contract(id)`** (built in): the buyer withdraws a contract nobody has taken (its escrow comes back), or the taker gives up one it took (settled as if it were due now). Returns 0 when recorded (the website settles it within a real minute) or -9 when the company has no such open contract it may cancel.

**`contracts(resource)`** (library, query 713): the open contracts for a resource where the drone is that nobody has taken yet, best price first: `{id, units, price, due_in, deposit, kept, broken}`. They are anonymous: `kept` and `broken` are how many contracts the poster has kept and broken as a seller, never its name.

**Settling.** The website's tick settles a contract on its due date, or as soon as all of it is delivered: the units delivered are paid to the seller from the escrow; for what wasn't delivered, the buyer gets its escrow back and that share of the seller's deposit; the seller gets back the rest of its deposit. A contract is `kept` (all delivered), `broken` (part or none), `expired` (nobody took it by its due date: the escrow comes back) or `cancelled`. When a company ends (wound up, closed, broke, or without drones), its contracts are settled at once, as if due. Escrows and deposits are credits held, not lost. A company's page lists its contracts, without naming the other side, and its record as a seller.

```lua
-- A seller: take the first contract here it can fill, then deliver
local good = metals_a1               -- change to the good you hold
for _, c in ipairs(contracts(good)) do
  if units(good) >= c.units and c.broken == 0 and take_contract(c.id) == 0 then
    deliver(c.id, c.units)
    break
  end
end
```

**`close_company()`** (built in, #925): the kill switch. Asks for the drone's company to end; the website's tick ends it within about a real minute. A startup closes as before (its debt cancelled, its credits back to the bank, its drones empty wrecks nobody can plunder); a matured company shares its credits among its shareholders by their shares (the bank's part leaves the game), its share orders are withdrawn, and its drones become wrecks with what they hold, for plunderers. Returns 0 when asked (and 0, changing nothing, when asked again while it is pending); -24 while the company still owes its lenders (#928: it repays them first, with `repay`) or has any open contract, posted or taken (it cancels or settles them first); -9 when the drone has no live company of its own to close, or it is a House company. Costs as much as a query. A company ends only this way, or by going broke, winding up or losing all its drones: the Close button on the page of a company its player owns outright pushes code that calls it, and a public company's own locked code is the only thing that can close it.

```lua
function launched()
  close_company()   -- what the Close button pushes
end
```

**`better_place(product, margin)`**: a plan B that looks before it flies. The place whose best buyer pays at least margin more for a product than here, or nil. With `trip`, it weighs the trip too. Round 39's newcomers moved after counting idle rounds, and one stayed at Earth for weeks while the Moon paid three times as much.

```lua
function better_place(product, margin)
  local here, q = location(), quotes(product)
  local best, best_bid = nil, (q[here] and q[here].bid or 0) + margin
  for place, p in pairs(q) do
    if place ~= here and p.bid >= best_bid and p.bid_units > 0 then best, best_bid = place, p.bid end
  end
  return best
end

function plan_b(product)
  local place = better_place(product, 100)
  if place and trip(place) < 72 then
    set_status("moving to " .. place_name(place) .. ": " .. resource_name(product) .. " pays more there")
    go(place)
  end
end
```

**`can_build(product, n)`**: whether the inputs for n of a product are on sale here, in depth (with `recipe` and `cost_to_buy`), and what they would cost:

```lua
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
```

**`spare()`**: credits above `cash_floor`. Spend only `spare()`, so the drone always keeps money for fuel. Used by: Maker, Smith, Machinist, Adaptive. (the Trader starter calls it `spare_credits()`)

```lua
cash_floor = 300   -- credits the drone keeps for fuel
function spare() return math.max(credits() - cash_floor, 0) end
```

- Round 25's traders and makers spent every credit on goods, then couldn't buy fuel and died.

**`richer(a, b)`**: of two minerals, the one with more left in the deposit here. Example: `dig(richer(kunspar, wolfite), 20)`.

```lua
function richer(a, b)
  if deposit(b) > deposit(a) then return b end
  return a
end
```

**`profit(product, input, n)`**: what one product sells for here, less what n of its input cost here. Example: `profit(alloys_a1, metals_a1, 2)` (an alloy from two metals). Negative: buying to make it loses money. (Where nobody sells or buys, the price is 0: check `buy_price` and `sell_price` first.)

```lua
function profit(product, input, n) return sell_price(product) - n * buy_price(input) end
```

**`smart_ask(resource)`** and **`reoffer(resource)`**: adaptive pricing for a seller. From the market here: it asks a unit under the cheapest other seller while its stock sells out (fewer than 4 aboard), a unit over the best bid while stock piles up (more than 16), and halfway otherwise; 0 where nobody else sells it. `reoffer` posts that as a standing sell order with `sell_order_if_changed`: call it every round, and the order follows the drone's own sales, but a post that would change nothing is skipped, so an unchanged price costs no fuel. Example: `reoffer(metals_a1); reoffer(metals_a2)`.

```lua
function smart_ask(resource)
  local under = buy_price(resource)       -- the cheapest other seller
  if under == 0 then return 0 end
  local over = sell_price(resource)       -- the best bid
  if over == 0 then over = under // 2 end
  local held = units(resource)
  if held < 4 then return under - 1 end
  if held > 16 then return over + 1 end
  return (under + over) // 2
end
function reoffer(resource)
  local price = smart_ask(resource)
  if price > 0 then sell_order_if_changed(price, 0, resource) end
end
```

- A released script can't be changed, so a fixed price is a one-shot bet. In round 25 a miner priced at 23 when makers would pay 27, and demand outran it.

## State machines

A drone that changes what it does as the market changes is easiest to write as a state machine (see the players' manual, "Market-aware drones", and the blog post "Drones that change their minds"). The drone is always in one **mode**. Each round it does one step of that mode's work, then stays in the mode or moves to another. The built-in `become(mode)`, `in_mode(mode)`, `tick()` and `ticks()` keep track:

```lua
mining, selling = 0, 1   -- a drone starts in mode 0
function mine_step()
  mine(kunspar)
  if units(kunspar) > 19 then become(selling) end
end
function sell_step()
  refine(kunspar)
  sell_all(metals_a1)
  become(mining)
end
function step()
  if in_mode(mining) then mine_step() else sell_step() end
end
function launched()
  equip(mining_kit)
  go(moon)
  while true do step(); tick() end
end
```

**`stuck_for(n)`**: true once the drone has spent more than n rounds in its current mode. Give every mode a way out: `rest_step` might go back to work when prices recover, or after `stuck_for(6)` sell what it has anyway. Used by: Adaptive (as `ticks() > 5`).

```lua
function stuck_for(n) return ticks() > n end
```

- Keep each step small: one action, or one product. The drone reads the market again before the next.
- A table of step functions (`steps = {[mining] = mine_step, ...}`) works only as a `local`. A global table is saved without its functions, and after a restart it comes back empty over the one your top level built. Numbered modes and `in_mode` need nothing else.

## Buying from other drones

**`buy_cheap(n, resource, price)`**: buy up to n of a good from the cheapest other seller at this place, if it asks price or less. It returns 0 when it bought, otherwise nonzero. Example: `buy_cheap(20, metals_a2, 38)`. Used by: Trader (as `buy`).

```lua
function buy_cheap(n, resource, price)
  local asked, seller, avail = best_offer(resource)
  if avail == 0 or asked > price then return -1 end
  return pull(math.min(n, avail), resource, seller)
end
```

- `seller` is the order's handle, not a drone ID (#927): `pull` takes it as it is. Pulling from another company's drone by its drone ID fails with -22; an unknown handle, or one for an order at another exchange, fails with -10 (no market order).
- It looks only at the cheapest order. Call it again to work down the list, or use `buy_here`, which does.
- Query 610 lists the sell orders here, cheapest first: price, kept minimum, units held, order handle, planets (1 for the planets' orders), mine (1 for this drone's own) (`row[1]` to `row[6]`). `best_offer` skips your own.

**`buy(n, metal, price)`** (Machinist): like `buy_cheap`, but it buys nothing while the company has less than `cash_floor` credits, so it never spends the money it needs for fuel. Used by: Machinist, Smith v4 (as `buy_metals`).

```lua
-- cash_floor is set with spare, and buy_cheap is above
function buy(n, metal, price)
  if credits() < cash_floor then return -1 end
  return buy_cheap(n, metal, price)
end
```

## Making

**`forge_rods()`**: turn Metals (A1) into rods, two metals to an alloy and an alloy to a rod, while the metals last. It stops at the first failure, such as running out of fuel. Used by: Smith.

```lua
function forge_rods()
  while units(metals_a1) > 1 do
    if make(alloys_a1) ~= 0 then return end
    if make(rods) ~= 0 then return end
  end
end
```

- The pattern works for any chain: make each step, return on a failure (`~= 0`: in Lua 0 is true, so `if make(rods) then` is always taken), and loop while the inputs last.

**`build()`** (Machinist): one gear box from 8 Metals (A1) and 2 Metals (A2): three Alloys (A1), two Alloys (A2), then a rod, a gear and a spring, then the gear box (it needs the Factory Kit's Spider). Nine actions and about 23 fuel. Used by: Machinist.

- Check every input before the first step (`if units(metals_a1) < 8 or units(metals_a2) < 2 then return end`), so a half-built chain doesn't strand alloys in the hold. Check the fuel too: `if units(fuel) < 30 then return end`.
- A long chain needs a bigger fuel margin: start brewing at 60, not 40.

**`tier(resource)`** and **`is_made(resource)`** (built in, #539): how many recipe steps a resource is from what is mined. `tier` is 0 for an ore, 1 for a refined good (metals, inorganics, organics), 2 for what is made from those (alloys, polymers, fuel), and so on up through parts and systems. `is_made(r)` is true from tier 2 up: a maker makes it from other goods, so before you buy one, see whether its inputs cost less. Resource numbers don't say this: Alloys (2021) sit among the metals (2011) and inorganics (2031). Each resource is looked up once a run.

```lua
if is_made(alloys_a1) then
  local r = recipe(alloys_a1)       -- what it's made from, to price it both ways
end
```

**`recipe(resource)`** (built in, #380 round 36): the first recipe that makes a resource, or nil: a table `{protocol, makes (units made), uses = {[resource] = units}, fuel, tool, hours}`. `uses` doesn't include fuel: that's `fuel`, the units it burns. `tool` is the module it needs, or nil. A drone that prices what it could make (inputs from `buy_price`, the product from `sell_price`) can choose, each round, what pays best here and now:

```lua
-- What one batch of a product earns here now, per hour of work: what it
-- sells for, less the 5% tax, less what its inputs cost to buy here; 0
-- when it can't be made or an input isn't for sale here
function rate(product)
  local r = recipe(product)
  if not r then return 0 end
  local cost = r.fuel * buy_price(fuel)
  for input, n in pairs(r.uses) do
    local p = buy_price(input)
    if p == 0 then return 0 end
    cost = cost + n * p
  end
  return (sell_price(product) * 95 // 100 * r.makes - cost) / r.hours
end
```

- Each `recipe` call is a query, and each price another: price your options once a round, not before every step.
- The API's `/recipes/{resource}/tree` (by resource id, e.g. `/recipes/4311/tree`) adds up a whole chain (what one unit takes down to metals, organics and inorganics): use it to plan before you write the script.

## Growing: launching clones

**`reinvest()`**: at an exchange, with 7,000 credits or more, buy a Mining Cruiser kit, launch a clone of this drone, buy the design's kit, `my_kit` (a Mining or Factory Kit), and hand it to the clone with 40 fuel. Used by: every starter and design since #380.

**`get_one(resource)`**: buy one of a good here unless one is aboard, and wait for it in game-hour steps (up to 6). Sellers sell at once; the wait covers a seller that has none in stock until it brings more up.

**`fill_to(n)`**: buy fuel here up to n, cheapest first, and wait for it the same way. It waits for the fuel to arrive, not to reach n: computing burns a unit or two just after the delivery, and waiting for n then sat out all six game hours (round 23).

**`equip_clone()`**: push a kit and 40 fuel to this drone's newest clone (the last of `my_clones()`).

```lua
my_kit = mining_kit   -- or factory_kit: the kit this design needs

function get_one(resource)
  if units(resource) > 0 then return end
  buy_here(1, resource)
  for _ = 1, 6 do
    if units(resource) > 0 then return end
    wait(60)
  end
end

function fill_to(n)
  local had = units(fuel)
  if had >= n then return end
  buy_here(n - had, fuel)
  for _ = 1, 6 do
    if units(fuel) > had then return end   -- it came: don't wait for n
    wait(60)
  end
end

function equip_clone()
  local clones = my_clones()
  local newest = clones[#clones]
  if not newest then return end
  push(1, my_kit, newest.drone)
  push(40, fuel, newest.drone)
end

-- One kit at a time. A hull already bought is launched whenever the fuel
-- allows; after equipping the clone the tank is refilled
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
```

**`equip(kit)`** (built in, #506): equips the drone. A company starts with 3,000 credits and no equipment; until the drone has its tools (an Engine), `equip` unpacks a kit aboard, buys `kit` (`mining_kit` or `factory_kit`, 2,000 at Earth) if the drone was released, or waits for the kit its parent hands it if it's a copy. It decides a tool is missing, and buys, only when it is still missing a game minute later and the query asking for it worked (#726: a failed query read as 0, and a drone a day past unpacking said "tool 5001 of kit 5902 is missing"). Put it first in `launched`: `equip(mining_kit)`. A drone that needs other equipment buys modules itself (`buy_here(1, engine_module)`), and one that only trades shares needs none.

**`await_kit()`** (built in): wait, an hour at a time, until a kit (or its unpacked tools) is aboard. Put it first in `launched`: a clone starts with an empty hold and runs `launched` at once.

- A drone's hold carries 1,000. Unpacked, a kit takes 267 to 296 of it; both kits fit (563), with room for fuel.
- If a kit is still aboard from a launch whose hand-over failed, `reinvest` hands it over first. A hull already bought is launched whenever the fuel allows, without checking credits again.
- Launching (30 fuel) and equipping the clone (40) empty the tank: `reinvest` refills it to 60 afterwards. Without that, every parent in round 19 died on its way home.
- A standing sell order for fuel keeps back only its limit: other drones buy everything above it, even while yours is busy. If the drone sells fuel and also launches clones, set the order's limit above what launching needs (80), or the tank never holds enough to launch (round 28: a factory's 3,000-credit hull sat unlaunched all round).
- A clone takes days to pay back its 5,000 credits. Near the end of a round (`today()`), stop reinvesting: two round 28 companies launched clones six days before the end and finished poorer for it.
- A clone gets your script and your saved globals. Set one before launching (`copy = true`) and the clone can tell it is one.

## Examples of the new rules (#932)

Five company designs in `examples/companies/` each show one idea, with the words above: [`varied.lua`](../examples/companies/varied.lua) (copies that vary: tuning numbers in one table, nudged in each copy by `lineage()`, starting from the best earner's, which each drone broadcasts), [`exit.lua`](../examples/companies/exit.lua) (a sell-out routine: `sell_all`, then `obligations()` with `repay`, `cancel_request` and `cancel_contract`, then `close_company()`, again while it returns -24), [`borrower.lua`](../examples/companies/borrower.lua) (`request_loan` only for a copy that pays back within the term, dearer after `cancel_request` when unbacked, `repay` early), [`salvager.lua`](../examples/companies/salvager.lua) (`wrecks()`, `salvage`, selling modules and Scrap (A1), moving on by `wrecks_at`) and [`hauler.lua`](../examples/companies/hauler.lua) (Metals (A3) from this season's best kunspar site, `best_sites`, with fuel for the whole voyage, sold by `house_demand` and `best_bid`).

## Patterns and pitfalls

- **Sell every by-product.** Equipment takes about 300 of a drone's 1,000 cargo. Anything you make and keep (Inorganics (A2) from vladnium, for one) piles up until there is no room to brew fuel, and the drone dies. Sell it all each round.
- **Think little while you wait.** `wait` itself is free, but a drone that wakes every few hours to look for a trade pays processor tax on each look (#544: 15 credits a million instructions). Keep the look cheap, and call `top_up` in the waiting loop, not only before trips.
- **Count attempts, not quiet time.** A wait that resets whenever something happens may never end. Count how many times you have looked instead.
- **Every action costs fuel.** Mining 1, refining 2, making 2 to 4, travel 5, a standing order or a broadcast 1. A pull that fails costs nothing. Thinking costs too: 1 fuel per 200,000 instructions, and each Lua instruction counts as six (a query as about 1,000). Fuel costs about 3 credits a unit.
- **Leave with fuel to spare.** Nearly every action burns fuel, buying fuel included, and nothing rescues a drone that runs dry: it is stranded until fuel reaches it. Check the tank before a trip, and at a station buy fuel before you sell. Stranded anyway? `call_for_fuel` posts a buy order a courier can profit from and waits for it.
- **0 is true in Lua.** Actions return 0 when they work, so `if make(rods) then` is always taken: compare, `if make(rods) ~= 0 then return end`. The same for counts: `if units(rods) > 0`, not `if units(rods)`.
- **Divide with `//` for whole numbers.** `/` gives a fraction (`7 / 2` is 3.5); prices and units are whole, so use `(bid + asked) // 2`.
- **Check for nil.** `recipe`, `message` and an empty query's `rows[1]` can be nil; reading a field of nil crashes the drone.
- **Credits are a plain number.** `credits() < 200` works at any balance.
- **Don't reuse the game's names.** A global called `ask`, `fuel` or `best_bid` replaces the game's for your own code (the library keeps working), and calling a number crashes your drone; `/check` and the Release page warn you. Use `local` inside functions, and pick names of your own: `rod_load`.
- **Keep what matters in globals.** A `local` in `launched` is lost when the engine restarts; a global number, string or table of them is saved.
- **Call `report()` in a loop that never ends.** A drone's output reaches you when `launched` returns, so a `launched` that loops forever is silent. `report()` sends what it has printed so far: `print("fuel " .. units(fuel)); report()`.
- **Messages are 1 to 80 characters.** A longer `broadcast` fails.
- **Waiting is free.** `wait` costs no fuel and no instructions, so wait rather than loop when there is nothing to do.

## Trading scripts (your account's shares)

A **trading script** is a different kind of script from a drone's: it trades your account's shares while you're away, every few real minutes, under the same rules as you (see the players' manual, "Your trading script"). The game calls its `trade(market)`; `market.me` is your account and `market.companies` every matured company still trading, with its prices, health (`at_risk`, `failing`, `stalled`, `dissolves_in`, `winds_up_in`) and book. It has these functions, and none of a drone's:

| Function | What it does |
|---|---|
| `buy(company, units, price)` | posts a buy order at a price, trading at once against what it meets |
| `sell(company, units, price)` | posts a sell order at a price |
| `buy_pegged(company, units, peg, percent)` | a buy order whose price follows the bank's: `peg` is `'buys_at'` or `'sells_at'`, `percent` above (or, negative, below) it |
| `sell_pegged(company, units, peg, percent)` | a sell order that follows the bank's price the same way |
| `cancel(order)` | cancels one of your standing orders, by id |
| `bank_buy(company, units)` | buys shares the bank holds, at its selling price |
| `bank_sell(company, units)` | sells shares to the bank, paid in 30 game days at the lower of today's cash-out and cash-out then |
| `loan_requests()` | the open loan requests (#928): `{id, company, name, amount, left, rate, per_year, term_days, due_in}`; the market table also has `lend_min_credits` |
| `back_loan(request, credits)` | lends credits on a loan request, under the Loans page's rules (at least `lend_min_credits` to back new loans) |
| `my_loans()` | the account's loans (#928): `{id, company, name, principal, rate, owed, repaid, closed, defaulted}`, also `market.me.loans` |
| `log(...)` | writes a line to the run's log, shown on the Trading desk and your account page |

Each action function returns `true`, or `false` and why. A run does at most 20 actions and writes at most 50 log lines; when it hits the 20, the log says so once. What you keep in the table `memory` is there next run. Three starters: [`examples/traders/payback.lua`](../examples/traders/payback.lua) (made for classes: buy when dividends pay a share back soon enough, sell when a company is about to end), [`examples/traders/value.lua`](../examples/traders/value.lua) and [`examples/traders/lender.lua`](../examples/traders/lender.lua) (backs loan requests that pay enough a game year, from healthy companies with a dividend record, at most a fifth of your spare credits in any one).
