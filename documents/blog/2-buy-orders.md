---
title: Let the market come to you
date: 2026-10-29
summary: A standing buy order brings fuel and supplies to your drone while it works or waits. Set the price to pay for the delivery, and keep the order small.
---

Most drones fetch what they need: they fly to an exchange, buy fuel and metals, and fly back. That costs fuel and days. A **standing buy order** turns this around. You post a price, and other players' drones bring the goods to you and push them into your hold. You are paid for nothing and pay only for what arrives.

```lua
-- buy_order(price, want, resource): pay up to price a unit, until want are aboard
-- buy_order(27, 10, metals_a1)
```

The order stays open while your drone does other things. It fills each time another drone sells into it, until your hold has `want` units of the resource. To stop it, post a new order with a limit of 0, because a drone has at most one buy order for each resource.

## Who did it well

- **Sol** (round 25) ran a gear-box factory at the Moon that bought its metals through standing buy orders, so passing miners could sell straight to it. Its second company made **+10,766 credits**, the best newcomer result up to then.
- Sol's *first* company is the warning. Its orders were large and always open, and miners kept pushing metals into them until its credits fell from 1,000 to 31. With no credits left for fuel, it became a wreck on day 8. The second company opened its orders only while it had 900 credits or more, and kept them small (10 of Metals (A1), 3 of Metals (A2)).
- **Gia** (round 29) worked the other side: her fuel brewers looked for fuel buy orders wherever they were and pushed fuel into any that paid 5 or more. Her three drones averaged **+14,459**.

## Supplies: bid only while you can afford it

```lua
-- Standing bids for a factory's metals, open only while the company has
-- 900 credits or more, and small
bid_floor = 900
bidding = false
function rich() return credits() >= bid_floor end
-- Bid one credit over the best buy order here, never over top
function bid_for(top, want, resource)
  buy_order(math.min(sell_price(resource) + 1, top), want, resource)
end
function bids_on() bid_for(27, 10, metals_a1); bid_for(39, 3, metals_a2) end
function bids_off() buy_order(1, 0, metals_a1); buy_order(1, 0, metals_a2) end
-- Call once a round: opens or closes the bids as the credits change
function sync_bids()
  local now = rich()
  if now == bidding then return end
  bidding = now
  if now then bids_on() else bids_off() end
end
```

## Fuel: call for it, then wait

Nothing in the game rescues a drone with an empty tank. A drone that is stranded, far from an exchange or with nobody selling fuel nearby, can still save itself: post a buy order for fuel at a price well above what it costs anywhere else, then wait. Waiting runs no instructions, so it burns no fuel. A courier that finds the order is paid at once for every unit it pushes.

```lua
-- Low on fuel and nobody sells here: pay 12 a unit (more than twice the
-- exchanges' price) for up to 60, wait two game hours at a time until some
-- has come, then stop the order
function call_for_fuel()
  buy_order(12, 60, fuel)
  while units(fuel) < 20 do wait(120) end
  buy_order(1, 0, fuel)
end
```

## The courier's side

Somebody has to answer those orders, and it can be you. A courier carries fuel to where drones run low (mining sites, Phobos and Deimos, the far end of a long trip) and sells into the buy orders it finds:

```lua
-- Sell fuel into the best buy order here if it pays at least 8 a unit,
-- keeping 60 for the trip home
function serve()
  local price, _, want = best_bid(fuel)
  if price < 8 then return end
  local n = math.min(want, units(fuel) - 60)
  if n > 0 then sell_here(n, fuel) end
end
```

## Tips

- **A buy order spends your credits while you aren't looking.** Keep the limit small, and close it before you need the credits for fuel.
- **The price pays for the trip.** A seller has to come to you, so a bid at the exchange's price brings nobody. Offer enough to make the trip worth it.
- **Your orders travel with you.** While a drone is in flight nobody can trade with it. When it arrives, its orders are live where it is.
