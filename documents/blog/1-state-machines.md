---
title: Drones that change their minds
date: 2026-10-29
summary: A released drone can't be changed, but the market changes every day. A state machine lets a drone decide what to do next from what it sees.
---

You can't change a drone after you release it. The market changes all the time anyway: deposits run dry, rivals undercut you, and buyers fill up and stop bidding. A drone that follows one fixed plan (mine, sell, repeat) will sooner or later work hard for nothing.

The companies that did best in our playtests read the market every round and changed what they did. The simplest way to write that is a **state machine**.

## The idea

The drone is always in one **mode**, such as shopping, making, hauling or resting. Each time round its loop, it does one step of that mode's work. Then it either stays in the mode or switches to another, depending on what it saw. Every drone knows four words for this:

| Word | What it does |
|---|---|
| `become ( mode -- )` | Switch to a mode, and start counting rounds in it again |
| `in? ( mode -- f )` | True if the drone is in that mode |
| `tick ( -- )` | Count a round: call it once each time round your loop |
| `ticks ( -- n )` | Rounds since the last `become`: give up on a mode that takes too long |

## Who did it well

- **Uma** (round 26) ran a factory at Earth with five modes: *deciding*, *building*, *selling*, *resting* and *growing*. Each time it was deciding, it priced rods, pumps and gear boxes from the live market and built whichever paid best. When nothing paid, it rested. When it was rich, it grew: it bought a hull and launched a copy of itself. It averaged **+11,163 credits** per company, the best of its round, and testers rated its feedback 8 out of 10.
- **Ines** (round 29) ran a Moon factory that, every round, scored twenty recipes by profit per hour from live prices. It switched from Alloys (A3) to Alloys (A2) on its own when prices moved, and averaged **+10,370**.
- The **Adaptive** design that comes with the game is the same pattern at its simplest. Read it in `examples/companies/adaptive.fth`.

## The code

Here is a complete rod maker, cut down from the Adaptive design. It buys Metals (A1) at the Moon only while two of them (a rod's worth) cost well under what a rod fetches at Earth. It makes rods while it has metals, hauls a load to Earth, and rests when nothing pays.

```forth
\ The modes
0 constant shopping   1 constant making   2 constant hauling   3 constant resting
2021 constant alloys-a1   3011 constant rods

\ Worth buying metals? Two make a rod, which fetches about 225 at Earth;
\ allow 40 for fuel and the trip
: worth-buying? ( -- f )
  metals-a1 buy-price ?dup if 2 * 40 + 225 < else 0 then ;

\ Each mode's step does a little work, then picks the next mode
: shop ( -- )
  worth-buying? 0= if resting become exit then
  10 metals-a1 buy-here drop
  metals-a1 units 1 > if making become else resting become then ;
: make-one ( -- )
  metals-a1 units 1 > if alloys-a1 make drop rods make drop exit then
  rods units 11 > if hauling become exit then
  shopping become ;
: haul ( -- )
  earth go drop  rods units rods sell-here drop  moon go drop
  shopping become ;
\ Nothing pays: wait two game hours and look again. After six rests,
\ sell whatever is aboard rather than wait for ever
: rest ( -- )
  120 wait drop
  rods units 0> ticks 5 > and if hauling become exit then
  worth-buying? if shopping become then ;

\ The machine: run the step for the mode it is in, count the round
: step ( -- )
  shopping in? if shop exit then
  making in? if make-one exit then
  hauling in? if haul exit then
  rest ;
: launched ( -- ) unpack drop moon go drop shopping become begin step tick again ;
```

## Tips

- **One small step per round.** Make one rod, not twelve, and then look at the market again. A drone that commits to a long job can't react until the job is done.
- **Always have a way out.** `ticks` counts rounds in the current mode. A mode that has gone on too long, such as six rests in a row or sixty rounds of building, should hand over to another one.
- **Add a mode, not an `if`.** When you think of something new for the drone to do, like growing, refuelling or moving, give it its own mode and step. The `step` word stays a simple list.
- **Try it first.** Use the Test button on the Release page, and run `worth-buying?` on the Machine page with live game data. You see what the drone would decide today before you risk a credit.
