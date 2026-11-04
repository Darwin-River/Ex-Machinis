---
title: Markets that move
date: 2026-11-03
summary: The House's prices now rise and fall, each exchange in its own way, with booms and slumps on top. A factory that picks its product from live prices each round, and a trader that follows the spread, earn from it; one that does the same thing whatever the market says doesn't.
---

In round 36 every newcomer worked out the same thing: the House paid about 25 to 33 credits a factory-hour for any system, wherever and whenever. Choosing what to make hardly mattered; only where you bought your inputs did. That's no longer true.

## What moves

Each exchange's demand (what the House buys, and what it bids) and supply (what it sells, and what it asks) now moves over game time, every half game day, each exchange in its own way:

- **The Earth Exchange** is a big, steady market: long, shallow waves, about 20 game days from top to top, a few percent either way.
- **The Moon Depot** is a frontier market: short, deep waves, about a week, up to a fifth either way.
- **The Mars Exchange** is a young colony: middling waves, and the sharpest booms and slumps.

Each tier of goods (ores, refined goods, parts, systems, modules) rides its own wave at each exchange, and each good a smaller one of its own. On top of that, in any 8-day stretch a tier at an exchange may have a **boom** (the House wants more and bids more), a **slump**, a **shortage** (it sells less, dearer) or a **glut**. `/api/v1/news` lists the ones under way:

```json
{"place": "Mars Exchange", "tier": "systems", "side": "demand", "kind": "boom", "percent_now": 31,
 "text": "Mars Exchange: a boom in systems (demand): the House bids up 31%, days 40 to 48."}
```

Your drones can't read the news. They see what it does to prices where they are, and to the price board.

## Choose what to make, each round

`recipe` (new in library 18) tells a drone what a thing takes. A factory that prices its options from live prices each round goes where the money is:

```lua
-- What one of a product earns here now, per hour of work: what it sells
-- for, less the tax, less what its inputs cost to buy here
function rate(product)
  local r = recipe(product)
  if not r then return 0 end
  local cost = r.fuel * buy_price(fuel)
  for input, n in pairs(r.uses) do
    local p = buy_price(input)
    if p == 0 then return 0 end   -- nobody sells it here
    cost = cost + n * p
  end
  return (sell_price(product) * 95 // 100 * r.makes - cost) / r.hours
end
```

This counts only the last step: a pump bought as rods and hoses. For a whole chain, call `rate` on the parts too, or plan it with `/api/v1/recipes/4311/tree`, which adds up everything one unit takes down to metals, organics and inorganics.

Pricing ten products is ten `recipe` questions and dozens of price questions: 1,000 instructions each, so about a fuel a round. Price once a round, not before every step.

## Follow the spread

A trader no longer needs a fixed price list. The new Trader starter reads the price board (what a metal sold for at Mars and at Earth yesterday), buys at the Moon only when that, less the tax, beats the seller's price by enough to pay for the trip, and hauls the load to whichever pays most. When a Mars boom lifts refined goods, it goes; when it fades, it waits. A trader that waits burns no fuel.

## Don't sell your last fuel

A long build can drain a tank without your program noticing. In round 36 a factory's nine-step build took it from 50 fuel to 3, and the failure path sold its leftover parts, a fuel a push, until it had none. It became a wreck with 5,517 credits in the bank. Set a floor:

```lua
-- A round: make a pump, rest an hour (yours does more)
function work()
  make(4311)
  wait(60)
end
function launched()
  fuel_floor(15)
  unpack()
  while true do work() end
end
```

At or below 15 fuel, `make` (but for fuel), `mine`, `refine` and `push` refuse with -30. The fuel that's left is for buying more.

## Try it against the real market

Release it and watch its page: every release is live and free, so a Moon plan meets the miners' metals at the Moon, the bids at Mars and the House's real booms and slumps from its first round. If it goes wrong, release a better version.
