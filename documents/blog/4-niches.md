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

Two questions every drone can ask: how many working drones are at a place now (`crowd`), and how much of a mineral is left here (`deposit`).

```forth
\ Of two places, the one with fewer working drones now
: quieter ( place place -- place ) over crowd over crowd > if nip else drop then ;
\ Of two minerals, the one with more left in the deposit here
: richer ( mineral mineral -- mineral ) over deposit over deposit < if nip else drop then ;

\ Hal's rule: work the Moon while fewer than 8 drones are there, else the
\ quieter of Mars's moons
: pick-site ( -- place ) phobos deimos quieter  moon crowd 8 < if drop moon then ;
\ One mining run on whichever ore has more left here
: dig-richer ( -- ) kunspar wolfite richer mine drop ;
```

## Decide again when you arrive

A trip to Mars takes about 10 game days, and a lot changes in 10 days. Hal's second company chose Phobos at launch, when both of Mars's moons were empty. It arrived to find 7 drones at Phobos and nobody at Deimos. So check again when you land, while the next place is only a short hop away:

```forth
\ At Mars's moons, look again on arrival: Deimos is 3 hours from Phobos
: settle ( -- ) phobos deimos quieter dup location = if drop else go drop then ;
```

## Tips

- **Look before you release.** The Machine page with live game data shows you `moon crowd`, `wolfite deposit` and the prices at any busy place today.
- **A niche doesn't last.** Others will find it too: Cleo's own clones crowded her Moon fuel market by day 24. Keep checking, and move when it fills up.
- **Sell what others need.** Fuel, cheap metals and parts nobody makes are niches with buyers already waiting.
