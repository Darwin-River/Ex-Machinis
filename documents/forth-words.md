# Useful FORTH words for Public Forge

A dictionary of words that proved useful while building company designs (#380). The source is `examples/companies/words.fth`, which loads on the drone VM as a whole; CI checks it with the starters. A drone can't load another file, so copy the words you need into your script.

The game's built-in words (`mine`, `refine`, `make`, `units`, `deposit`, `sell-all`, `go`, …) are in the players' manual. This page builds on them.

## Goods the game words don't name

| Word | Value |
|---|---|
| `alloys-a1` `alloys-a2` `alloys-a3` | 2021, 2022, 2023 |
| `polymers` | 2051 |
| `rods` | 3011 |
| `sheets` | 3001 |
| `inorganics-a2` `organics-a2` | 2032, 2042: refining by-products; Earth and the Moon buy them |

Define more the same way: `3311 constant pipes`. The Resources page lists every ID.

## Inventory

**`metals ( -- n )`**: total Metals (A1), (A2) and (A3) aboard. Used by: Trader, Miner.

## Mining

**`dig ( mineral n -- )`**: mine until n units of the mineral are aboard. When the deposit here has fewer than 5 runs left, it waits a game hour for it to refill instead of mining nothing. Used by: every design.

- Mining takes from a deposit shared by every drone there. Moon kunspar is usually mined out, while the Moon's yalspar and vladnium are not.
- The Miner and Refiner starters move between Phobos and Deimos instead of waiting. Moving costs 5 fuel each way, so check fuel when you do.

## Fuel

**`brew-fuel ( -- )`**: brew 30 fuel from 16 yalspar and 12 vladnium (one Organics (A1) and two Inorganics (A1)). It burns about 15 fuel along the way. Used by: Miner, Refiner.

- Start brewing well before you run out: below 40, not 25. A drone that starts brewing with too little fuel dies halfway through.

**`top-up ( n m -- )`**: with less than n fuel, buy m from the House station here (5 a unit) and wait two game hours for it to arrive. Example: `40 30 top-up`. Used by: Smith, Trader (as `fuel-up`).

- The station hands out its hourly supply among everyone who orders, so at a busy exchange you may get less than you asked for. Check the tank again afterwards.

## Selling

**`portion ( resource -- )`**: sell at most 15 of a good to the House station here. Used by: Trader.

- A station pays less the more its hourly batch holds. Selling 15 an hour, over several hours, earns more than selling a whole load at once.

**`offer ( price resource -- )`**: post a standing sell order for a good at a price, keeping none back. Example: `23 metals-a1 offer`. Used by: Miner (at the Moon).

- The order stays open as the hold changes, so post it once. Another drone at the same place buys by pulling from it, even while yours is busy or waiting.
- Price it above what the House pays, or nobody gains by selling to drones.

## Buying from other drones

**`buy-cheap ( n resource price -- ior )`**: buy up to n of a good from the cheapest seller at this place, if it asks price or less. It leaves 0 when it bought, otherwise nonzero. Example: `20 metals-a2 38 buy-cheap`. Used by: Trader (as `buy`).

- It looks only at the cheapest order. Call it again to work down the list.
- The answer layout of query 610 is price (2 cells), kept minimum, units held, and seller.

## Making

**`forge-rods ( -- )`**: turn Metals (A1) into rods, two metals to an alloy and an alloy to a rod, while the metals last. It stops at the first failure, such as running out of fuel. Used by: Smith.

- The pattern works for any chain: make each step, `if exit then` on failure, and loop while the inputs last.

## Patterns and pitfalls

- **Sell every by-product.** Equipment takes 310 of a drone's 500 cargo. Anything you make and keep (Inorganics (A2) from vladnium, for one) piles up until there is no room to brew fuel, and the drone dies. Sell it all each round.
- **Refuel wherever you wait.** A drone waiting weeks for a trade still burns fuel computing. Call `top-up` in the waiting loop, not only before trips.

- **Count attempts, not quiet time.** A wait that resets whenever something happens may never end. Count how many times you have looked instead.
- **Every action costs fuel.** Mining 1, refining 2, making 2 to 4, travel 5. Computing costs too: 1 fuel per 100,000 instructions.
- **Keep comment lines under 80 characters.** The drone reads at most 80 characters of a line, and a `\` comment longer than that leaks into your code.
- **Waiting is free.** `wait` costs no fuel and no instructions, so wait rather than loop when there is nothing to do.
