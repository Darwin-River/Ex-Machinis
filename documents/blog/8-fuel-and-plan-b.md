---
title: Cheaper fuel, bigger holds, and a plan B
date: 2026-11-03
summary: Fuel now costs 3 credits and half as much thinking, and new drones carry 1,000. But in round 37 half the drones ran dry where everyone bought their fuel. The ones that lasted kept a reserve and had somewhere else to go.
---

Round 37 was the first with markets that move, and four newcomers made the most of them: a miner that chose, each sale, to sell its ore raw or refined; factories that priced every system from its recipe tree each round; a hauler that bought pumps at Earth for 388 and sold them at the Moon for 1,229.

It was also the round where 50 of 129 drones became wrecks.

## What happened at the Moon

About 95 drones worked the Moon. They all bought their fuel at the Moon Depot, and they all bought the same metals. Two things ran out:

- **Fuel.** The Depot was meant to sell 6,000 a day. A fault in the House's station program refilled its fuel only once a round, and a round of its program takes days, so it sold about 500 every two days. That's fixed: the station now tops up its fuel after every import.
- **Metals (A1).** Every chain needs it, and from about day 13 there were none for sale at the Moon at any price.

The drones that ran dry had code that worked in an empty world. They waited for fuel that didn't come, or kept mining until the tank was empty.

## Cheaper to go further

Fuel at the exchanges now costs **3 credits** (it was 5), and thinking costs half as much: **1 fuel per 200,000 instructions** (it was 100,000). A new drone's hold and a Cruiser's now carry **1,000** (they were 500), so a hauler can take a bigger load and enough fuel for a long trip. The Trader starter now gathers 150 metals before it sets off.

So Earth, Mars, Phobos and Deimos are cheaper to reach. In round 37, Yusuf's drone flew 11 days to Phobos, found it empty of other drones and full of ore, and earned nearly twice what his Moon drone did.

## Keep a reserve

Decide what the next step needs (a trip, a build, a day of thinking) and buy it while it's for sale, not when the tank is low:

```lua
reserve = 60
function fuel_for(trip_fuel)
  local want = trip_fuel + reserve - units(fuel)
  if want <= 0 then return true end
  local price, seller, avail = best_offer(fuel)
  if avail > 0 then buy_here(math.min(want, avail), fuel) end
  return units(fuel) >= trip_fuel + reserve
end
```

And set a fuel floor, so nothing the drone does spends its last units:

```forth
: work ( -- ) 4311 make drop  60 wait drop ;
: launched ( -- ) 30 fuel-floor  unpack drop  begin work again ;
```

## Have a plan B

The price board tells you what sold, not what you can buy. Before a drone counts on an input, it should check someone sells it:

```forth
\ True if someone here sells at least n of a resource
: on-sale? ( n resource -- f ) best-offer >r 2drop r> swap < 0= ;
```

And it needs somewhere else to go when nobody does: another exchange, another mine, another product. In round 37 the six Systems shops took the top six places. They are no cleverer than the rest, but after four idle rounds they fly to the other exchange. Uma's factory, the best on the board at day 22, had no such rule; when the Moon's metals ran out it waited for the last 16 days.

The game won't do this for your drones. That's the job: a drone that reads the market each round, keeps a reserve, and has a plan B keeps working when the crowd arrives.
