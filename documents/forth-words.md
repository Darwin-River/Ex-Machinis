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

- A run that brings nothing still burns a fuel. On a busy deposit, other drones can take what `deposit` reported before your run lands, so `dig` compares the units before and after each run and waits when nothing came. Without that check, a drone can burn its whole tank on empty runs (a Refiner died that way in round 16).
- Mining takes from a deposit shared by every drone there. Moon kunspar is usually mined out, while the Moon's yalspar and vladnium are not.
- The Miner and Refiner starters move between Phobos and Deimos instead of waiting. Moving costs 5 fuel each way, so check fuel when you do.

## Fuel

**`brew-fuel ( -- )`**: brew 30 fuel from 16 yalspar and 12 vladnium (one Organics (A1) and two Inorganics (A1)). It burns about 15 fuel along the way. Used by: Miner, Refiner.

- Start brewing well before you run out: below 40, not 25. A drone that starts brewing with too little fuel dies halfway through.

**`top-up ( n m -- )`**: with less than n fuel, buy m from the House station here (5 a unit) and wait two game hours for it to arrive. Example: `40 30 top-up`. Used by: Smith, Trader (as `fuel-up`).

- The station hands out its hourly supply among everyone who orders, so at a busy exchange you may get less than you asked for. Check the tank again afterwards.

## Getting rid of goods

**`jettison ( n resource -- ior )`** (a game word): throw n units of a resource into space, for 1 fuel whatever the amount. Example: `10 inorganics-a2 jettison drop`.

- Use it for goods nobody buys where you are. A full hold leaves no room for the fuel you make, and a drone without fuel dies.
- Selling is better where a station buys the good: Earth, the Moon and Mars buy refining by-products for 10 to 18.

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

**`build ( -- )`** (Machinist): one gear box from 8 Metals (A1) and 2 Metals (A2): three Alloys (A1), two Alloys (A2), then a rod, a gear and a spring, then the gear box (it needs the starter kit's Spider). Nine actions and about 23 fuel. Used by: Machinist.

- Check every input before the first step (`metals-a1 units 8 < ... if exit then`), so a half-built chain doesn't strand alloys in the hold.
- A long chain needs a bigger fuel margin: start brewing at 60, not 40.

## Buying from other drones, with a cash floor

**`buy ( n metal price -- )`** (Machinist): like `buy-cheap`, but it buys nothing while the company has less than `cash-floor` credits, so it never spends the money it needs for fuel. Used by: Machinist, Smith v4 (as `buy-metals`).

## Growing: launching clones

**`reinvest ( -- )`**: at a House exchange, with 7,000 credits or more, buy a Mining Cruiser kit (3,000), launch a clone of this drone, buy a Starter Kit I (2,000) and hand it to the clone with 40 fuel. Used by: every starter and design since #380.

- One kit at a time: equipment fills 364 of a drone's 500 cargo, so both kits (40 each) don't fit at once with a tank of fuel.
- If a Starter Kit I is still aboard from a launch whose hand-over failed, `reinvest` hands it over first.

**`equip-clone ( -- )`**: push a starter kit and 40 fuel to this drone's newest clone (the last row of `670 ask`).

**`await-kit ( -- )`**: wait, an hour at a time, until a starter kit is aboard. Put it first in `launched`: a clone starts with an empty hold and runs `launched` at once.

## Patterns and pitfalls

- **Sell every by-product.** Equipment takes 310 of a drone's 500 cargo. Anything you make and keep (Inorganics (A2) from vladnium, for one) piles up until there is no room to brew fuel, and the drone dies. Sell it all each round.
- **Refuel wherever you wait.** `wait` itself is free, but a drone that wakes every few hours to look for a trade burns fuel computing each time it looks, and over weeks that empties the tank. Call `top-up` in the waiting loop, not only before trips.
- **Count attempts, not quiet time.** A wait that resets whenever something happens may never end. Count how many times you have looked instead.
- **Every action costs fuel.** Mining 1, refining 2, making 2 to 4, travel 5. Computing costs too: 1 fuel per 100,000 instructions.
- **Leave with fuel to spare.** Every action burns fuel, even `sell-to-station` and `buy-from-station`. Check the tank before a trip, and at a station buy fuel before you sell.
- **Keep comment lines under 80 characters.** The drone reads at most 80 characters of a line, and a `\` comment longer than that leaks into your code.
- **`credits` is a double.** Compare it as one: `credits 200 0 d< if exit then` (less than 200). Dropping the high cell works only until the balance passes 32,767.
- **Don't reuse FORTH's names.** A constant called `load` shadows the standard `load` word (the drone says "load redefined"); `dump` is taken too, which is why the game word is `jettison`. Prefix your names: `rod-load`.
- **Waiting is free.** `wait` costs no fuel and no instructions, so wait rather than loop when there is nothing to do.
