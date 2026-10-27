# Public Forge economy playbook

How to seed a Public Forge world so that its economy thrives, learned by running a world, releasing company designs into it and measuring the result (#378, #380). Use it to seed a live server.

- **Designs** are FORTH scripts released like any player's: the starters in `examples/starters/` and the company designs in `examples/companies/`.
- **Numbers** come from a local test world at 600 game seconds a real second (one game day in 2.4 real minutes), with the engines' clock floor on.

## Best performers

Ranked by average gain: what a company made beyond its starter package (credits plus goods at House prices, less 1,000 credits, 100 fuel and the kit), the amount it is taxed on. A design earns a place here only after at least 20 game days in a mixed world.

| Design | File | Where | Avg gain | Gaining | Notes |
|---|---|---|---|---|---|
| Chemist | `examples/companies/chemist.fth` | Moon | about 75 a game day | all | Works the Moon's yalspar and vladnium; sells metals, polymers and inorganics to the Moon Depot. The best earner per day so far. The Moon supports about 14; version 1 died of a full hold after about 30 days (fixed in version 2, round 4). |
| Miner (Moon copies) | `examples/starters/miner.fth` | Moon | about +2,600 by day 38 | all | Sells metals to traders through sell orders, the rest to the Moon Depot. Best of the starters, but Moon kunspar is limited (see lessons). |
| Miner (Mars copies) | `examples/starters/miner.fth` | Phobos, Deimos | about +1,000 by day 38 | all | Needs the Mars Exchange's bigger budget. |
| Refiner | `examples/starters/refiner.fth` | Moon, Phobos, Deimos | about +400 by day 38 | all | Idles once its tank is full: little upside. |
| Smith v2 | `examples/companies/smith.fth` | Moon, Earth | +552 in 27 game days (about 20 a day) | 12 of 12 | Rods from its own metals, sold at the Earth Exchange. Version 3 (round 6) sells rods 4 an hour: version 2 crashed the Exchange's price. |
| Trader | `examples/starters/trader.fth` | Moon to Mars | 0 (none gaining) | none | Survives since round 4, but its margin is thin: it buys at about 31 and sells at about 36 at Mars after a 20-day round trip. Not worth seeding. |

The round log below has the numbers behind each entry.

## Recommended seed mix

For an empty world, before players arrive (to be refined round by round):

- Moon miners and Mars-side miners in about a 1 to 2 ratio (the Miner spreads itself by drone number).
- About one trader for every two Moon miners.
- A few refiners, so that drones can buy fuel cheaper than the House's 5.
- Company designs from the table above as they prove themselves.

## Economic levers

The game data a world's owner can change (`game-engine/sql/game-data/build_game_data.py`; in a running world, the tables directly). The levers changed so far, and what each did:

| Lever | Table | From | To | Effect |
|---|---|---|---|---|
| Tax | `house_rules`, `company_taxable` | 0.5% a day of assessed wealth | 0.5% a day of gains | New companies stopped bleeding about 20 a day; nobody was eliminated for tax. |
| Mars Exchange budget | `stations` | 5,000 a day | 15,000 a day | Mars metal prices held (37 a unit for miners, not 30), and no company starved. |
| Mars Exchange fuel | `station_goods` | 2,000 a day | 6,000 a day | Fuel was no longer short once traders arrived. |
| Deposits | `deposits` | smaller (before #371) | 400 mining runs, refilling 80 a game day | Starters stopped losing money (#371). |

| Moon Depot budget | `stations` | 5,000 a day | 10,000 a day | Round 3: 36 Chemists and the Moon miners had brought its budget down to about 500. (Round 4 measures the effect.) |
| By-product buyers | `station_goods` | none | Earth and the Moon buy Inorganics (A2), (A3) and Organics (A2), (A3) at 10 to 18 | Gives every refining by-product a buyer, so holds don't clog. (Round 4 measures the effect.) |

| Mars Exchange buys chemists' goods | `station_goods` | metals only | also polymers, Inorganics (A1)-(A3), Organics (A1)-(A3) | Chemist v3 copies at Phobos and Deimos gained about 150 in their first 12 days (10 of them travelling); Moon copies about 380. |
| Dump takes a quantity | `protocols` (1) | 1 unit an action, 1 fuel each | n units, 1 fuel an action; game word `jettison` | A drone can clear goods nobody buys, cheaply. |

Levers not yet tried: House prices for parts and systems (lesson 9), deposit refill per mineral, station budgets at Earth and the Moon, the tax rate and grace period.

## Lessons learned

1. **Tax gains, not holdings.** Taxing a company's whole assessed wealth charged a new company for the starter kit's equipment (about 3,000 credits at House prices): about 20 credits a game day, more than most earned. Tax what it made beyond its starter package.
2. **Deposits size the economy.** A deposit refills at a fixed rate, so it caps the income of every drone mining it. About 100 drones mined Moon kunspar dry: refilling about 400 kunspar a game day, it makes about 80 metals a day for the whole Moon. Spread drones over sites and minerals, or the extra drones add nothing.
3. **House budgets cap sales at a place.** An exchange's daily budget is the most all sellers there can earn from it. The Mars Exchange's 5,000 a day starved its sellers once traders arrived. Size each budget to the traffic it gets.
4. **Leave a fuel margin.** Brewing fuel burns about 15 fuel, so a drone must start brewing well before it runs out (40, not 25). A loop that moves (5 fuel a trip) must check fuel itself. Buying fuel at a crowded exchange is unreliable: its hourly supply is shared out among everyone who orders.
5. **A middleman needs suppliers who sell to drones.** Traders only had metal to buy once miners posted standing sell orders above the House price. Keep about one trader for two suppliers, or traders starve.
6. **Sell in portions.** A House station pays less the more a batch holds, so dumping a load in one hour loses money. Sell about 15 of each good an hour.
7. **Count attempts, not quiet time.** A trader set to "leave after 48 hours without a purchase" never left, because every small purchase reset its clock. Count its looks at the market instead.
8. **Long trips need big margins.** The Moon to Mars is about 10 game days each way. A haul pays only if its margin covers about 20 days of the drone's time.
9. **Most factory chains don't pay at House prices.** Sheets, pipes, fasteners and the systems cost more in metals and fuel than the House pays. Polymers, rods and Alloys (A3) roughly break even or better, and gear boxes come out ahead only before fuel is counted. Raise those House prices, or expect nobody to build them.
10. **Every product needs a buyer, or holds clog.** A drone's installed equipment takes 310 of its 500 cargo, leaving about 190. Refining vladnium leaves Inorganics (A2), which nobody bought, so it piled up until there was no room to brew fuel, and the drone died. Round 3 lost every Smith this way, and the Chemists were close behind. Sell every by-product, and have the House buy them.
11. **Waiting drones still burn fuel.** A trader shopping for weeks on the Moon ran its tank dry through computing alone: 16 of 18 died. Top up fuel wherever you wait.
12. **A deposit supports a fixed number of drones.** The Moon's yalspar refills 400 units a game day; a Chemist uses about 16 units every 13 game hours, so about 14 Chemists use it all. Beyond that, more drones add nothing.
13. **Small markets crash when everyone sells at once.** The Earth Exchange wants about 50 rods a day; round 5's Smiths sold 144 in one batch and got about 33 a rod, not 60. Size each sale to the market's daily units.
14. **House-price arbitrage is thin.** The Mars Exchange pays half as much again as the Moon, but after the miners' markup, batch price drops and a 20-day round trip, a trader clears about 5 a unit. Arbitrage needs a bigger gap or a shorter trip.
15. **Give every design an outlet.** The Refiner stops when its tank is full and waits. A design with nothing to do while it waits earns nothing.

## Round log

### Round 1: Chemist (#380)

A world of 108 starter companies (54 miners, 36 refiners, 18 traders); 12 Chemists released at game day 10.5, measured at day 21.1.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist | 12 | 1,991 | +786 | 12 | 0 |
| Miner | 54 | 2,328 | +1,168 | 54 | 0 |
| Refiner | 36 | 1,471 | +431 | 36 | 0 |
| Trader | 18 | 1,187 | 0 | 0 | 0 |

- The House now buys polymers and inorganics as well as metals: 5 goods instead of 3.
- The Moon's yalspar and vladnium deposits stayed at about 97% full: room for many more Chemists. Moon kunspar stayed mined out.
- Drone-to-drone trade fell to almost nothing in the last 5 days: the traders had filled up and gone to Mars.

### Round 2: Smith (#380)

12 Smiths released at game day 21.1, measured at day 31.8.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist | 12 | 2,904 | +1,626 | 12 | 0 |
| Miner | 54 | 3,171 | +1,957 | 54 | 0 |
| Refiner | 36 | 1,654 | +611 | 36 | 0 |
| Trader | 18 | 586 | 0 | 0 | 0 |
| Smith | 12 | 990 | +710 | 12 | 0 |

- Smiths gain about 67 a game day on paper (rods at House prices), but at day 10 they held 10 rods each and had not yet made a trip to Earth: an alloy and a rod take 6 game hours per rod.
- Chemists kept their pace: about 78 a game day between rounds.
- Drone-to-drone trade came back (about 200 trades in 5 days) once traders returned from Mars.
- House stations are far from their budgets: the Moon Depot spent about 1,800 a game day of its 5,000, at 97% of its base prices.

### Round 3: 24 more Chemists (#380)

24 Chemists released at game day 31.8 (36 in all), measured at day 42.5.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist | 36 | 2,276 | +1,012 | 36 | 0 |
| Miner | 54 | 3,868 | +2,721 | 54 | 0 |
| Refiner | 36 | 1,712 | +670 | 36 | 0 |
| Trader | 18 | 1 | 0 | 0 | 16 |
| Smith | 12 | 0 | 0 | 0 | 12 |

- **Every Smith died with a full hold.** Its hold filled with goods it kept for Earth plus Inorganics (A2), which nobody buys. Then making fuel failed for lack of space (-3), and it ran dry. Chemists were at 452 of 500 cargo and heading the same way.
- **16 of 18 Traders ran dry while shopping on the Moon.** They only bought fuel before a trip, and with more drones competing for miners' metal, most of their pulls failed (-5).
- **Yalspar ran short.** The Moon's yalspar fell from about 1,950 to 541: 36 Chemists use more than it refills.
- **The Moon Depot's budget ran short.** It fell to about 500 of 5,000.
- **Fixes for round 4:**
  - Chemist and Smith version 2 sell every by-product; the Smith sells everything but rods at the Moon as it goes, and hauls 12 rods instead of 20.
  - The Trader tops up fuel while it shops.
  - Levers: the Moon Depot's budget goes to 10,000, and Earth and the Moon buy the by-products.

### Round 4: fixed designs (#380)

12 each of Chemist v2, Smith v2 and Trader v2 released at game day 42.5, measured at day 53.7.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist (v1) | 36 | 2,764 | +1,699 | 36 | 0 |
| Chemist v2 | 12 | 1,350 | +165 | 12 | 0 |
| Smith v2 | 12 | 1,153 | +4 | 9 | 0 |
| Trader v2 | 12 | 481 | 0 | 0 | 0 |
| Miner | 54 | 4,348 | +3,205 | 54 | 0 |
| Refiner | 36 | 1,726 | +734 | 36 | 0 |

- No new design died: selling by-products and refuelling while shopping work. The Moon Depot bought 37 Inorganics (A2) in 5 days.
- The House now buys 6 goods (from 3 at the start).
- Newcomers earn little: 48 Chemists and 12 Smiths share Moon yalspar that supports about 14 (426 of 2,000 left). Late arrivals at a full site make almost nothing; spread them out.
- The Moon Depot's budget is no longer short (9,872 of 10,000 left).
- **Round 5:** Chemist v3 spreads over the Moon, Phobos and Deimos by drone number, and the Mars Exchange buys polymers, inorganics and organics too.

### Round 5: Chemist v3 spreads out (#380)

12 Chemist v3 released at game day 53.7, measured at day 80.5. Jettison was added along the way (game data reloaded at about day 70, which refilled every deposit).

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist (v1) | 36 | 3,453 | +2,491 | 36 | 0 |
| Chemist v2 | 12 | 2,221 | +1,017 | 12 | 0 |
| Chemist v3 | 12 | 1,562 | +232 | 12 | 0 |
| Smith v2 | 12 | 1,722 | +552 | 12 | 0 |
| Trader v2 | 12 | 247 | 0 | 0 | 0 |
| Miner | 54 | 4,868 | +3,744 | 54 | 0 |
| Refiner | 36 | 1,714 | +747 | 36 | 0 |

- The House now buys 7 goods, rods included (from 3 at the start).
- Chemist v3 copies split 4, 4 and 4 over the Moon, Phobos and Deimos. The Moon copies gained about 380; the Mars-side copies about 150, after a 10-day trip.
- Smiths reached Earth, but sold 144 rods in one batch at about 33 each: the Exchange wants about 50 a day. Smith v3 (round 6) sells 4 an hour.
- Trader v2: 7 at Mars selling, 5 on the Moon holding 30 metals with 9 credits. They sold 150 metals at about 36 after buying at about 31.
- No company has died since round 3.

### Baseline (before #380)

From the four 300-company runs in #378, each with miners, refiners and traders in equal numbers:

| Run | Change | Result |
|---|---|---|
| 1 | Tax on gains; traders buy from miners | Traders never left the Moon: their 48-hour wait kept resetting. |
| 2 | Traders count looks | Traders sold at Mars, but at about 37 after buying at 34. 4 Mars-side miners ran dry. |
| 3 | Traders sell in portions; miners fly to buy fuel | Traders made a margin of about 7, but 47 of 68 Mars-side miners ran dry. |
| 4 | Miners brew at 40; Mars Exchange budget 15,000 | Every company survived 38 days; every group but the traders gained. |
