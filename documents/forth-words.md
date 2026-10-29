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

Define more the same way: `3311 constant pipes`. The Resources page lists every ID. (`inorganics-a2` and `organics-a2` are game words since #380.)

## Inventory

**`metals ( -- n )`**: total Metals (A1), (A2) and (A3) aboard. Used by: Trader, Miner.

## Mining

**`dig ( mineral n -- )`**: mine until n units of the mineral are aboard. When the deposit here has fewer than 5 runs left, it waits a game hour for it to refill instead of mining nothing. It needs the Mining Kit's Worm (since #380, a factory drone can't mine). Used by: Miner, Refiner.

- A run that brings nothing still burns a fuel. On a busy deposit, other drones can take what `deposit` reported before your run lands, so `dig` compares the units before and after each run and waits when nothing came. Without that check, a drone can burn its whole tank on empty runs (a Refiner died that way in round 16).
- Mining takes from a deposit shared by every drone there. Moon kunspar is usually mined out, while the Moon's yalspar and vladnium are not.
- The Miner and Refiner starters move between Phobos and Deimos instead of waiting. Moving costs 5 fuel each way, so check fuel when you do.

## Fuel

**`brew-fuel ( -- ior )`**: brew 30 fuel from one Organics (A1) and two Inorganics (A1) with the Factory Kit's Chemical Reactor. It burns 2. Used by: Chemist. (Before #380 every drone mined and refined the inputs itself; the Mining Kit has no Chemical Reactor, so miners buy their fuel.)

- Start brewing well before you run out: below 40, not 25. A drone that starts brewing with too little fuel dies halfway through.

**`top-up ( n m -- )`**: with less than n fuel, buy m from the House station here (5 a unit) and wait two game hours for it to arrive. Example: `40 30 top-up`. Used by: Smith, Trader (as `fuel-up`).

- The station hands out its hourly supply among everyone who orders, so at a busy exchange you may get less than you asked for. Check the tank again afterwards.

## Getting rid of goods

**`jettison ( n resource -- ior )`** (a game word): throw n units of a resource into space, for 1 fuel whatever the amount. Example: `10 inorganics-a2 jettison drop`.

- Use it for goods nobody buys where you are. A full hold leaves no room for the fuel you make, and a drone without fuel dies.
- Selling is better where a station buys the good: Earth, the Moon and Mars buy refining by-products for 10 to 18.

## Selling

**`portion ( resource -- )`**: sell at most 15 of a good to the House station here. Used by: Trader.

- A station drone pays less the more it holds, and posts a new price once a game hour. Selling 15 an hour, over several hours, earns more than selling a whole load at once.

**`offer ( price resource -- )`**: post a standing sell order for a good at a price, keeping none back. Example: `25 metals-a1 offer`.

- The order stays open as the hold changes, so post it once. Another drone at the same place buys by pulling from it, even while yours is busy or waiting.
- Price it above what the House pays, or nobody gains by selling to drones, and under what the House asks, or nobody buys from you: the House sells ores and refined goods too, at half as much again as it pays (#380).

**`offer-under-house ( resource -- )`**: post a standing sell order halfway between what the House station here pays and what it asks (nothing where there is no station). Used by: Miner and Refiner (as `offer`).

## Buying where it is cheapest

**`buy-cheapest ( n resource -- )`** (#380): buy up to n of a good here from whoever sells it cheapest: another drone's sell order (`best-offer`) or the House station (`house-price`, side 2). It pulls at once, from the drone or from the House's station drone, and what the cheapest drone doesn't sell it buys from the House. Used by: every design that buys (Maker, Smith, Machinist, Chemist, Miner and Refiner for fuel).

- The House always sells, at half as much again as it pays, so a maker never runs out of inputs, but a miner asking less sells first.

## Reading the market

Every drone also knows `buy-price`, `sell-price`, `best-offer`, `best-bid` and `house-price` (game words; see the players' manual, "Market-aware drones"). These build on them:

**`board-price ( resource place -- price )`** (#380): what the House paid a unit for a good at a place over the last game day (query 681, the House's price board); 0 if it bought none there. Example: `rods earth board-price`. Used by: Adaptive.

- A station drone's listed price is what it pays with no stock. When many drones sell to it, it pays less, and this shows by how much. Query 680, the players' board, has the same layout.

**`overstocked? ( product -- f )`** (#380): true when the drone holds more than half of what sold at Earth yesterday (`sold`, query 682). Check it before making more of a product, and sell first or make something else. Change `earth` to the place where you sell.

**`spare ( -- n )`**: credits above `cash-floor`, at most 32,000. Spend only `spare`, so the drone always keeps money for fuel. Used by: Maker, Smith, Machinist, Trader, Adaptive.

- Round 25's traders and makers spent every credit on goods, then couldn't buy fuel and died.

**`smart-ask ( resource -- price )`** and **`reoffer ( resource -- )`**: adaptive pricing for a seller. Where there is a House station, it asks a unit under what the House asks while its stock sells out (fewer than 4 aboard), a unit over what the House pays while stock piles up (more than 16), and halfway otherwise. `reoffer` posts that as a standing sell order: call it every round, and the order follows the drone's own sales. Example: `metals-a1 reoffer metals-a2 reoffer`.

- A released script can't be changed, so a fixed price is a one-shot bet. In round 25 a miner priced at 23 when makers would pay 27, and demand outran it.

**`richer ( mineral mineral -- mineral )`**: of two minerals, the one with more left in the deposit here. Example: `kunspar wolfite richer 20 dig`.

**`profit ( product input n -- credits )`**: what one product sells for here, less what n of its input cost here. Example: `alloys-a1 metals-a1 2 profit` (an alloy from two metals). Negative: buying to make it loses money.

## State machines

A drone that changes what it does as the market changes is easiest to write as a state machine (see the players' manual, "Market-aware drones"). The drone is always in one **mode**. Each round it does one step of that mode's work, then stays in the mode or moves to another. The game words `become ( mode -- )`, `in? ( mode -- f )`, `tick ( -- )` and `ticks ( -- n )` keep track:

```
0 constant mining   1 constant selling
: mine-step ( -- ) kunspar mine drop  kunspar units 19 > if selling become then ;
: sell-step ( -- ) kunspar refine drop  metals-a1 sell-all  mining become ;
: step ( -- ) mining in? if mine-step exit then sell-step ;
: launched ( -- ) unpack drop moon go drop  mining become  begin step tick again ;
```

**`stuck-for? ( n -- f )`**: true once the drone has spent more than n rounds in its current mode. Give every mode a way out: `rest-step` might go back to work when prices recover, or after `6 stuck-for?` sell what it has anyway. Used by: Adaptive (as `ticks 5 >`).

- Keep each step small: one action, or one product. The drone reads the market again before the next.
- Don't pass execution tokens around: `'` works only outside a definition. Numbered modes and `in?` need nothing else.

## Buying from other drones

**`buy-cheap ( n resource price -- ior )`**: buy up to n of a good from the cheapest seller at this place, if it asks price or less. It leaves 0 when it bought, otherwise nonzero. Example: `20 metals-a2 38 buy-cheap`. Used by: Trader (as `buy`).

- It looks only at the cheapest order. Call it again to work down the list.
- The answer layout of query 610 is price (2 cells), kept minimum, units held, and seller.

## Making

**`forge-rods ( -- )`**: turn Metals (A1) into rods, two metals to an alloy and an alloy to a rod, while the metals last. It stops at the first failure, such as running out of fuel. Used by: Smith.

- The pattern works for any chain: make each step, `if exit then` on failure, and loop while the inputs last.

**`build ( -- )`** (Machinist): one gear box from 8 Metals (A1) and 2 Metals (A2): three Alloys (A1), two Alloys (A2), then a rod, a gear and a spring, then the gear box (it needs the Factory Kit's Spider). Nine actions and about 23 fuel. Used by: Machinist.

- Check every input before the first step (`metals-a1 units 8 < ... if exit then`), so a half-built chain doesn't strand alloys in the hold. Check the fuel too: `fuel units 30 < if exit then`.
- A long chain needs a bigger fuel margin: start brewing at 60, not 40.

## Buying from other drones, with a cash floor

**`buy ( n metal price -- )`** (Machinist): like `buy-cheap`, but it buys nothing while the company has less than `cash-floor` credits, so it never spends the money it needs for fuel. Used by: Machinist, Smith v4 (as `buy-metals`).

## Growing: launching clones

**`reinvest ( -- )`**: at a House exchange, with 7,000 credits or more, buy a Mining Cruiser kit (3,000), launch a clone of this drone, buy the design's kit, `my-kit` (a Mining or Factory Kit, 2,000), and hand it to the clone with 40 fuel. Used by: every starter and design since #380.

- One kit at a time: equipment fills 364 of a drone's 500 cargo, so both kits (40 each) don't fit at once with a tank of fuel.
- If a kit is still aboard from a launch whose hand-over failed, `reinvest` hands it over first. A hull already bought is launched whenever the fuel allows, without checking credits again.
- Launching (30 fuel) and equipping the clone (40) empty the tank: `reinvest` refills it to 60 afterwards. Without that, every parent in round 19 died on its way home.

**`get-one ( resource -- )`**: buy one of a good here from the House unless one is aboard, and wait for it in game-hour steps (up to 6). The House's station drone sells at once; the wait covers a station drone that has none in stock until it brings more up.

**`fill-to ( n -- )`**: buy fuel here up to n from the House and wait for it the same way. It waits for the fuel to arrive, not to reach n: computing burns a unit or two just after the delivery, and waiting for n then sat out all six game hours (round 23).

**`equip-clone ( -- )`**: push a kit and 40 fuel to this drone's newest clone (the last row of `670 ask`).

**`await-kit ( -- )`**: wait, an hour at a time, until its kit is aboard. Put it first in `launched`: a clone starts with an empty hold and runs `launched` at once.

## Patterns and pitfalls

- **Sell every by-product.** Equipment takes 310 of a drone's 500 cargo. Anything you make and keep (Inorganics (A2) from vladnium, for one) piles up until there is no room to brew fuel, and the drone dies. Sell it all each round.
- **Refuel wherever you wait.** `wait` itself is free, but a drone that wakes every few hours to look for a trade burns fuel computing each time it looks, and over weeks that empties the tank. Call `top-up` in the waiting loop, not only before trips.
- **Count attempts, not quiet time.** A wait that resets whenever something happens may never end. Count how many times you have looked instead.
- **Every action costs fuel.** Mining 1, refining 2, making 2 to 4, travel 5. Computing costs too: 1 fuel per 100,000 instructions.
- **Leave with fuel to spare.** Nearly every action burns fuel, even `sell-to-station`; buying with `buy-from-station` is free, so a drone with an empty tank at a station can still buy fuel. Check the tank before a trip, and at a station buy fuel before you sell.
- **Long lines are fine, long strings aren't.** The game splits a line over 79 characters between words (dropping its comment) before the drone reads it, but a single string (`." ..."`) longer than that can't be split. The checker and the Machine split lines the same way.
- **`credits` is a double.** Compare it as one: `credits 200 0 d< if exit then` (less than 200). Dropping the high cell works only until the balance passes 32,767.
- **Don't reuse FORTH's names.** A constant called `load` shadows the standard `load` word (the drone says "load redefined"); `dump` is taken too, which is why the game word is `jettison`, and so is `report`. Prefix your names: `rod-load`.
- **Call `report` in a loop that never ends.** A drone's output reaches you when its script ends, so a `launched` that loops forever is silent. `report` sends what it has printed so far: `." fuel " fuel units . report`.
- **Waiting is free.** `wait` costs no fuel and no instructions, so wait rather than loop when there is nothing to do.
