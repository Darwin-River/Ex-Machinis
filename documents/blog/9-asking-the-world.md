---
title: Asking the world, by name
date: 2026-11-04
summary: Drones can now see every exchange's prices and stock, an hour old, price a whole lot before they buy it, add up their company's earnings, and say on their page why they're sitting still.
---

In round 39 one newcomer's factory sat at Earth for weeks making valves for 636 while the Moon paid 1,061 for them. Its plan B was sound: after four idle rounds, move on. But each valve it managed to build reset the count, so it never left. It had no way to see what the Moon paid without flying there.

Another newcomer's factory planned a build on Metals (A1) at 1 credit a unit. The seller had 2 units. The build failed halfway, and the drone was left holding gears and belts it couldn't finish.

Both drones were asking the wrong questions, because the right ones were hard to ask. Until now, most of what a drone could learn was in numbered queries (`ask(612, r, p)`), with columns read by position (`rows[1][3]`). Now every question has a name, and the answers have named fields.

## Every question by name

```lua
function look_around()
  print("at", place_name(), "with", space(), "space free")
  for _, item in ipairs(hold()) do print(resource_name(item.resource), item.units) end
  for _, o in ipairs(sell_orders(metals_a1)) do print("metals:", o.units, "at", o.price) end
  local t = trip_to(mars)
  if t then print("Mars is", t.hours, "game hours away") end
end
```

The players' manual, chapter 8, lists them all by subject: your drone, your company, the market here, other exchanges, places, wrecks, game data and events. The Queries page still lists every query by number, and now starts each with the function that asks it.

## Other exchanges, an hour late

`quotes(r)` gives the best prices for a good at every exchange: the best bid and how many it wants, the cheapest ask and how many it sells, and the stock on sale there. Where your drone is, that's live. Everywhere else it's the market as it stood an hour of game time ago, like the price board: news takes time to travel, and other drones read the same news.

That's enough for a plan B that looks before it flies:

```lua
function better_place(product, margin)
  local here, q = location(), quotes(product)
  local best, best_bid = nil, (q[here] and q[here].bid or 0) + margin
  for place, p in pairs(q) do
    if place ~= here and p.bid >= best_bid and p.bid_units > 0 then best, best_bid = place, p.bid end
  end
  return best
end
```

`stock(r, place)` is the stock alone: when an exchange starts running out of metals, you'll see it an hour later, not when you arrive.

## The price of the whole lot

`buy_price(r)` is what the cheapest seller asks, for however few it has. `cost_to_buy(n, r)` walks the sell orders here, cheapest first, and tells you what n would really cost, and how many of the n are there. `sale_value(n, r)` does the same for selling. Before you start a build, check its inputs are all on sale:

```lua
function inputs_ready(product, n)
  local r = recipe(product)
  if not r then return false end
  for input, per in pairs(r.uses) do
    local _, got = cost_to_buy(per * n - units(input), input)
    if got < per * n - units(input) then return false end
  end
  return true
end
```

## How is it doing, and why is it waiting?

`earnings(hours)` adds up your company's trades: what it sold, the tax, what it bought, and the net, by good too. A drone can report it once a day, or change course when a product stops paying.

And `set_status(text)` puts a line on the drone's page, in the API and in test results. A drone that sits still can now say why:

```lua
function idle(reason)
  set_status(reason)
  wait(60)
end
-- idle("waiting for metals: none on sale at " .. place_name())
```

Set it when the reason changes. It costs as much thinking as a question, so not every round.
