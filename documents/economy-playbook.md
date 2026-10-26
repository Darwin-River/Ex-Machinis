# Public Forge economy playbook

How to seed a Public Forge world so that its economy thrives, learned by running a world, releasing company designs into it and measuring the result (#378, #380). Use it to seed a live server.

- **Designs** are FORTH scripts released like any player's: the starters in `examples/starters/` and the company designs in `examples/companies/`.
- **Numbers** come from a local test world at 600 game seconds a real second (one game day in 2.4 real minutes), with the engines' clock floor on.

## Best performers

Ranked by average gain: what a company made beyond its starter package (credits plus goods at House prices, less 1,000 credits, 100 fuel and the kit), the amount it is taxed on. A design earns a place here only after at least 20 game days in a mixed world.

| Design | File | Where | Avg gain | Gaining | Notes |
|---|---|---|---|---|---|
| Chemist | `examples/companies/chemist.fth` | Moon | +786 in 10.5 game days (about 75 a day) | 12 of 12 | Works the Moon's yalspar and vladnium, which nobody else mines; sells metals, polymers and inorganics to the Moon Depot. The best earner per day so far; 12 copies left the deposits nearly full. |
| Miner (Moon copies) | `examples/starters/miner.fth` | Moon | about +2,600 by day 38 | all | Sells metals to traders through sell orders, the rest to the Moon Depot. Best of the starters, but Moon kunspar is limited (see lessons). |
| Miner (Mars copies) | `examples/starters/miner.fth` | Phobos, Deimos | about +1,000 by day 38 | all | Needs the Mars Exchange's bigger budget. |
| Refiner | `examples/starters/refiner.fth` | Moon, Phobos, Deimos | about +400 by day 38 | all | Idles once its tank is full: little upside. |
| Trader | `examples/starters/trader.fth` | Moon to Mars | about -200 by day 38 | none | Short of supply when traders are as many as Moon miners; about one trip in 38 days. |

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
10. **Give every design an outlet.** The Refiner stops when its tank is full and waits. A design with nothing to do while it waits earns nothing.

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

### Baseline (before #380)

From the four 300-company runs in #378, each with miners, refiners and traders in equal numbers:

| Run | Change | Result |
|---|---|---|
| 1 | Tax on gains; traders buy from miners | Traders never left the Moon: their 48-hour wait kept resetting. |
| 2 | Traders count looks | Traders sold at Mars, but at about 37 after buying at 34. 4 Mars-side miners ran dry. |
| 3 | Traders sell in portions; miners fly to buy fuel | Traders made a margin of about 7, but 47 of 68 Mars-side miners ran dry. |
| 4 | Miners brew at 40; Mars Exchange budget 15,000 | Every company survived 38 days; every group but the traders gained. |
