---
title: Keep a waiting drone cheap
date: 2026-10-30
summary: An order stays up until you replace it, and every post costs a fuel. Drones that re-posted their orders or rescanned the market every round burned their tanks while waiting. Post on a change, and wait longer while nothing changes.
---

A drone that waits for business still pays for every round it runs. In round 31 most of the drones that ran dry weren't flying or mining. They were waiting, and paying for it.

## Where the fuel went

- **Suki**'s Moon broker re-posted its four orders every round at the same prices. Each post is an action, and every action costs a fuel, so the broker burned 35 to 40 fuel a game day on orders that were already up. It wrecked on day 9.7 with 518 credits aboard.
- **Ravi**'s fuel depot re-posted its sell order whenever a rival's offer came or went, and its bids every three rounds. When its inputs dried up, it went on paying for orders and wrecked holding 3,870 credits.
- **Theo**'s factory scored 17 recipes every 4 game hours, each from live prices. A query counts as 1,000 instructions, and 100,000 instructions cost a fuel, so the scan cost about 5 fuel a game day. Stranded at Earth with nobody selling fuel, it ran its tank dry thinking.

## Post an order once

An order stays up until the drone posts another for the same resource, and trades don't use it up: it fills again whenever the hold changes. You never need to post it again to keep it alive.

When the price does follow the market, post only when it changes. `sell_order_if_changed` and `buy_order_if_changed` take the same arguments as `sell_order` and `buy_order`, and skip the post (no action, no fuel) when this drone's order is already at that price and limit:

```lua
-- Ask one under the cheapest other seller, never under a floor;
-- call it every round: it posts only when the price moves
price_floor = 20
function ask_for(resource)
  local price = buy_price(resource)   -- 0: nobody else sells it here
  if price > 0 then price = price - 1 else price = 30 end
  return math.max(price, price_floor)
end
function keep_offering(resource)
  sell_order_if_changed(ask_for(resource), 0, resource)
end
```

## Wait longer while nothing changes

Waiting runs no instructions, so it burns no fuel. A drone that finds nothing to do can double its wait each time, up to a limit, and go back to short waits as soon as something happens:

```lua
-- Wait 30 game minutes after a busy round, twice as long after each
-- quiet one, up to a game day
nap = 30
function busy() nap = 30 end
function quiet()
  wait(nap)
  nap = math.min(nap * 2, 1440)
end
```

In the loop, call `busy()` when the round did something (a trade, a new order, a move) and `quiet()` when it didn't. A drone that rests a game day between checks spends a few instructions a day, not thousands.

## Check the cost before you release

Try your round in the test world: a drone's page shows the instructions it has run and the fuel it used for thinking in the last game day. A round that costs 0.2 fuel is fine; run it every hour and it is about 5 fuel a day, more than a miner earns back on a slow day. Make the expensive part, such as a market scan, run only when the drone is ready to act on it.
