---
title: Go where nobody is
date: 2026-10-29
summary: Copies of the same idea crowd the same deposit and the same buyer. The drones that earned most found what nobody else was doing, and checked again when they arrived.
---

Every new player starts from the same starter scripts, and the starters all do the obvious thing. In round 25 the starter Miners emptied the Moon's kunspar by day 3, while its wolfite stayed full all round. In round 28 the starter Makers pushed Earth's best bid for rods down from 225 to 113. A crowd of copies drives prices down for all of them.

The players who did best asked one question before every decision: **what is nobody else doing?**

## Who did it well

- **Hal** (round 29) found the Moon's wolfite untouched, because everyone was mining kunspar. His first company was the Moon's only wolfite miner all round. It chose where to start from how crowded each place was. His companies averaged **+8,490 credits**.
- **Dax** (round 28) bet on Phobos and Deimos, where nobody mined, selling at the Mars Exchange, where buyers paid twice the Moon's price. He averaged **+12,957**, the best of his round.
- **Jude** (round 29) watched his stock Miner sit on the Moon's empty kunspar. His second company mined whichever of kunspar and wolfite had more left. It had 2,727 credits by day 12, while the first was still on its way to Phobos. The starter Miner now does the same.
- **Cleo** (round 28) noticed that every starter bought fuel from whoever sold it cheapest, and opened a fuel depot at the Moon one credit under the exchange. She ran 6 drones in two companies, averaging **+10,575**.

## The code

Two questions every drone can ask: how many working drones are at a place now (`crowd(place)`), and how much of a mineral is left here (`deposit(mineral)`).

```lua
-- Of two places, the one with fewer working drones now
function quieter(a, b)
  if crowd(b) < crowd(a) then return b end
  return a
end
-- Of two minerals, the one with more left in the deposit here
function richer(a, b)
  if deposit(b) > deposit(a) then return b end
  return a
end

-- Hal's rule: work the Moon while fewer than 8 drones are there, else the
-- quieter of Mars's moons
function pick_site()
  if crowd(moon) < 8 then return moon end
  return quieter(phobos, deimos)
end
-- One mining run on whichever ore has more left here
function dig_richer() mine(richer(kunspar, wolfite)) end
```

## Decide again when you arrive

A trip to Mars took about 10 game days then (drones accelerate four times as hard now, so it's about 2 to 3), and a lot changes in 10 days. Hal's second company chose Phobos at launch, when both of Mars's moons were empty. It arrived to find 7 drones at Phobos and nobody at Deimos. So check again when you land, while the next place is only a short hop away:

```lua
-- At Mars's moons, look again on arrival: Deimos is 3 hours from Phobos
-- (quieter is the function above)
function settle()
  local place = quieter(phobos, deimos)
  if place ~= location() then go(place) end
end
```

## Tips

- **Look before you release.** The API shows the crowds (`/api/v1/places`), the orders at every place (`/api/v1/orders`), and any query as a drone there would see it (`/machine/query`, for `deposit(wolfite)` say).
- **A niche doesn't last.** Others will find it too: Cleo's own clones crowded her Moon fuel market by day 24. Keep checking, and move when it fills up.
- **Sell what others need.** Fuel, cheap metals and parts nobody makes are niches with buyers already waiting.
