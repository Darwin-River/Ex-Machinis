# Public Forge economy playbook

How to seed a Public Forge world so that its economy thrives, learned by running a world, releasing company designs into it and measuring the result (#378, #380). Use it to seed a live server.

- **Designs** are FORTH scripts released like any player's: the starters in `examples/starters/` and the company designs in `examples/companies/`.
- **Numbers** come from a local test world at 600 game seconds a real second (one game day in 2.4 real minutes), with the engines' clock floor on. Until round 12 the clock slowed only when the engines ran 60 real seconds behind; at 600 that let drones wait hours of game time for their turn, so output per game day is understated (see lesson 13). From round 13 it slows at 10 seconds behind.

## Best performers

Ranked by average gain: what a company made beyond its starter package (credits plus goods at House prices, less 1,000 credits, 100 fuel and the kit), the amount it is taxed on. A design earns a place here only after at least 20 game days in a mixed world.

| Design | File | Where | Avg gain (round 8, fresh world) | Gaining | Notes |
|---|---|---|---|---|---|
| Smith v5 | `examples/companies/smith.fth` | Moon, Phobos, Deimos | about 105 a game day (round 16) | 9 of 9 | Spreads like the Chemist; Mars-side copies sell rods at the Mars Exchange, a short hop. The best earner per day. |
| Machinist v3 | `examples/companies/machinist.fth` | Moon, Earth | about 84 a game day (round 16) | 6 of 6 | Gear boxes (450) from metals bought from miners; brews until its tank is full. |
| Smith v3 | `examples/companies/smith.fth` | Moon, Earth | +1,513 by day 19 (about 80 a day) | 6 of 6 | Mines yalspar and vladnium, turns its metals into rods and sells them at the Earth Exchange 4 an hour. Needs rods at 90 (parts lever) and an Earth budget to match. |
| Miner (Moon copies) | `examples/starters/miner.fth` | Moon | +2,496 by day 19 | all | Kunspar to metals; offers them to other drones, the rest to the Moon Depot. Moon kunspar supports about 12. |
| Chemist v3 (Moon copies) | `examples/companies/chemist.fth` | Moon | +1,771 by day 19 | all | Yalspar and vladnium to metals, polymers and inorganics. Moon yalspar supports about 14 (Smiths share it). |
| Chemist v3 (Mars-side copies) | `examples/companies/chemist.fth` | Phobos, Deimos | +553 by day 19 | all | Same, selling at the Mars Exchange; loses its first 10 game days to the trip. |
| Refiner | `examples/starters/refiner.fth` | Moon, Phobos, Deimos | +662 by day 19 | all | Idles once its tank is full: little upside, but sells fuel to other drones. |
| Miner (Mars-side copies) | `examples/starters/miner.fth` | Phobos, Deimos | +309 by day 19 | all | Slow start (the trip), then steady. |
| Trader | `examples/starters/trader.fth` | Moon to Mars | 0 (none gaining in round 5) | none | Survives, but clears about 5 a unit after a 20-day round trip. Not worth seeding. |

The round log below has the numbers behind each entry.

## Recommended seed mix

For an empty world, before players arrive. Round 8 seeded a fresh world with this mix, and all 90 companies were gaining by game day 19, with the engines at full speed:

| Design | Companies | Spread |
|---|---|---|
| Miner | 36 | 12 Moon, 24 Phobos and Deimos (by drone number) |
| Chemist v3 | 36 | 12 Moon, 24 Phobos and Deimos |
| Refiner | 12 | 4 Moon, 8 Phobos and Deimos |
| Smith | 6 | Moon, selling at Earth |
| Trader | 0 | not worth seeding |

- Release them in that order of numbers (the Load Test does it: `--starters=miner,miner,miner,refiner`, then the company designs), so each design's copies spread evenly by drone number.
- Keep each Moon deposit's users near what it supports: about 12 kunspar miners, and about 14 yalspar users (Chemists and Smiths).
- The game data must carry the levers below (tax on gains, station budgets, by-product buyers, parts prices, jettison).

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

| Parts and systems prices | `station_goods` | rods 60, sheets 60, fasteners 50, pipes 70, systems 300 | half as much again (rods 90, systems 450) | Smiths sold rods at about 72 on average (33 before, when they sold in bulk), and the Smith became the best earner per game day. Adopted in the game data. |
| Earth Exchange budget | `stations` | 5,000 a day | 10,000 a day | Six Smiths' rod sales brought it down to about 1,100; raised with the parts prices. |

| Engine pauses | `game-engine.conf`, `events-engine.conf` | `DB_READ_TIME` and `ENGINE_CHECK_TIME` 5 seconds | 1 second | Every action waited up to 10 real seconds on the engines' idle pauses. In the 96-day world the clock went from about 36 game seconds a second (lag 11 to 15 seconds) back to 600 (lag 6 to 10). |

| Ship kits for sale | `station_goods`, `resources` | none sold; kits 224 and 375 cargo | Mining Cruiser kit 3,000 and Starter Kit I 2,000 at every exchange, 24 a day; flat-packed to 40 | Companies can reinvest: launch a clone and equip it (about 5,000 credits). (Round 18 measures growth.) |
| Made goods' prices | `station_goods` (`made_value`) | alloys 30-50, polymers 30, parts 75-105, systems 450: an alloy sold for less than its metals | inputs + fuel + a wage per game hour (20 alloys, 25 parts, 30 systems): alloys 70-100, polymers 70, parts 205-385, systems 790-1,400 | Each step up the chain pays more per hour than mining (17). Round 23 measures it. Round 25 (#380): 20 alloys, 30 parts, 45 systems (rods 225, gear boxes 1,255). |

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
13. **The engines set a ceiling too.** Drones take turns on the engines, so a slow turn is game time lost. At a fast test clock (600) even 26 real seconds of lag cost a drone about 4 game hours a turn, and output per game day fell by 60% over five rounds while every deposit stayed full. On a live server at 24 game seconds a second the same lag is about 10 game minutes. Set the clock floor's lag threshold (`CLOCK_LAG_HIGH`) to match: low enough that a turn's wait is small next to an action's game time.
14. **Small markets crash when everyone sells at once.** The Earth Exchange wants about 50 rods a day; round 5's Smiths sold 144 in one batch and got about 33 a rod, not 60. Size each sale to the market's daily units.
15. **House-price arbitrage is thin.** The Mars Exchange pays half as much again as the Moon, but after the miners' markup, batch price drops and a 20-day round trip, a trader clears about 5 a unit. Arbitrage needs a bigger gap or a shorter trip.
16. **Don't name your constants after FORTH words.** Smith v3's `load` constant shadowed the standard `load` (the drone printed "load redefined"). Pick names like `rod-load`.
17. **Arrive with fuel to spare.** Every action burns fuel, even offering goods to a station or ordering fuel from it. A drone that arrives somewhere with an empty tank can't sell, can't buy fuel and can't leave. Check fuel before every trip, and buy fuel first on arrival.
18. **Brew enough fuel for the whole cycle.** A brew makes 30 fuel but spends about 15 doing it, so it nets about 15. A Machinist's gear box burns about 40. Brewing once a cycle left it short every time, until it couldn't afford a brew and died. Brew until the tank is back above your threshold, and buy fuel when it is too low to brew.
19. **An aging world slows down unless its database keeps up.** Several queries read every event ever recorded, so each turn got slower as the world aged (136 ms for "how many units do I hold", 630 ms for the order book at day 90). Migration 0020 indexes them. Watch the clock's lag on a live server as it ages.
20. **Profile before blaming load.** The engines looked overloaded, but were idle 99% of the time: their fixed pauses between turns were the lag. And the game engine was leaking about 7 MB a minute (1.9 GB after 3 hours), which a live server would have hit within a day. Check CPU, query times and memory before adding hardware.
21. **An empty mining run still costs fuel.** A deposit can report enough and still yield nothing when other drones take it first. A mining loop that only checks the deposit before each run can burn a whole tank on empty runs (a Refiner died that way in round 16). Compare what you hold before and after a run, and wait when nothing came.
22. **Check what a hold can carry before selling something.** A working drone's equipment takes 364 of its 500 cargo; with a tank of fuel, about 50 is free. Neither ship kit fit until it was flat-packed, and even then a drone can carry only one kit at a time: buy the hull, launch, then buy the equipment.
23. **A station's hourly batch must sell at least one.** A station sells `daily_units` ÷ 24 a batch, rounded down: 4 a day sold nothing.
24. **Count the whole cost of an action, in fuel too.** Launching a clone costs 30 fuel and equipping it 40 more: parents that did it on an ordinary tank all died on the way home. Refill after anything that spends a lot of fuel at once.
25. **Wait for deliveries, don't guess.** The House delivers when a batch clears, sometimes hours after the order. A fixed 2-hour wait missed kits; wait in steps until the goods arrive, with a limit.
26. **Give every design an outlet.** The Refiner stops when its tank is full and waits. A design with nothing to do while it waits earns nothing.
27. **When a design dies, read its last actions before changing its script.** Three Machinists looked like a fuel-planning mistake; their events showed the engine had destroyed a module. Trace a death back to the first thing that went wrong.
28. **Let newcomers play it.** Twenty rounds of designs written by someone who knew the engine never found that loading a script could kill a drone, or that a looping drone's output never arrives. Four newcomers found both within an hour. Invite fresh players every round, and give them the same tools a real player has.
29. **Price made goods from their recipes.** A good the House buys should pay for its inputs, its fuel and the time it takes, and pay more per hour the further up the chain it is. Otherwise players who do the arithmetic only mine, and the tech tree is decoration.
30. **Watch the starters crowd.** Every newcomer's first design is a starter; a hundred copies of one mine the same ore at the same places. Spread the starters out, or make them choose by what's left.
31. **Fix one imbalance and look for the next.** Pricing made goods from their recipes made making things pay; within two rounds the raw producers were the ones left behind, and testers moved on to "every chain pays the same per hour". Each lever moves the problem; measure after each one.

## Newcomer playtests

From round 21, four naive agents join each round as new players would: they get the players' manual, the starter scripts, a checker, the Test button and their companies' status pages, and nothing else, each with a different playstyle. At the end they rate the game (1 to 10).

| Round | Player (style) | Result | Fun | Manual | FORTH | Debugging | Depth | Fairness | Overall |
|---|---|---|---|---|---|---|---|---|---|
| 21 | Ada (manufacturer) | Gear boxes; 3rd company +62, after selling 2 for 900 | 7 | 5 | 7 | 3 | 8 | 6 | 6 |
| 21 | Bram (trader) | Earth-Moon broker; 2 died loading, 3rd never traded | 5 | 4 | 5 | 2 | 5 | 4 | 4 |
| 21 | Cleo (expansion) | Best-ore miner; +1,611 at the Moon, no clone yet | 6 | 4 | 6 | 3 | 6 | 5 | 5 |
| 21 | Dax (salvager) | Plunder and rods; 2 died loading, 3rd +621 | 6 | 5 | 6 | 3 | 7 | 6 | 6 |
| 21 | **Average** | | 6.0 | 4.5 | 6.0 | 2.8 | 6.5 | 5.3 | 5.3 |
| 22 | Eve (engineer) | Wolfite miner with priced sell orders; 2 companies, +1,864 avg | 6 | 7 | 7 | 6 | 4 | 7 | 6 |
| 22 | Finn (casual) | Miner starter kept at the Moon; +627 | 5 | 6 | 6 | 4 | 7 | 6 | 6 |
| 22 | Gus (logistics) | Gear boxes from bought metals; still in the hold at the end | 6 | 6 | 7 | 5 | 7 | 5 | 6 |
| 22 | Hana (competitive) | Ranked the ores, mined wolfite; 2 companies, +1,745 avg | 6 | 6 | 7 | 4 | 6 | 6 | 6 |
| 22 | **Average** | | 5.8 | 6.3 | 6.8 | 4.8 | 6.0 | 6.0 | 6.0 |
| 23 | Ivy (manufacturer) | Gear boxes from wolfite, +3,995 avg; her motor company ran dry with 2 motors aboard | 7 | 7 | 6 | 8 | 6 | 7 | 7 |
| 23 | Jon (quant) | Planned from live data; alloys from uncontested wolfite, +4,457 avg | 6 | 6 | 5 | 8 | 6 | 7 | 6.5 |
| 23 | Kai (empire) | A queen buys hulls, workers make alloys; 2 clones | 7 | 6 | 6 | 7 | 6 | 7 | 7 |
| 23 | Lea (casual competitor) | Rods and fasteners, +7,541 avg: the best newcomer yet | 7 | 6 | 6 | 8 | 7 | 7 | 7 |
| 23 | **Average** | | 6.8 | 6.3 | 5.8 | 7.8 | 6.3 | 7.0 | 6.9 |
| 24 | Max (middleman) | Bought miners' metals, made rods and fasteners; one company taxed out with 3,700 of stock aboard | 7 | 6 | 5 | 7 | 6 | 5 | 6 |
| 24 | Nia (frontier) | Wolfite at Phobos, sold at Mars, with a Moon control: Mars broke even over 26 days | 6 | 6 | 7 | 8 | 6 | 7 | 6.5 |
| 24 | Oto (opportunist) | Plundered wrecks, made rods and fasteners from bought metals, +5,221 avg | 7 | 7 | 6 | 7 | 6 | 7 | 7 |
| 24 | Pia (optimiser) | Wolfite into alloys and parts, priced the chains, +6,448 avg | 6 | 7 | 7 | 6 | 5 | 7 | 6 |
| 24 | **Average** | | 6.5 | 6.5 | 6.3 | 7.0 | 5.8 | 6.5 | 6.4 |
| 25 | Quin (optimiser) | Wolfite to fasteners and gear boxes at the Moon; credits +6,235 and +1,647, 2 clones | 7 | 6 | – | 6 | 7 | – | 7 |
| 25 | Rex (raw supplier) | Wolfite miner selling to makers first; Moon +1,146 (3 drones), Mars +3,549 | 7 | 6 | – | 8 | 7 | – | 7 |
| 25 | Sol (manufacturer) | Gear boxes from bought metals; credits +10,766, the best newcomer yet; first company went broke through its buy orders | 7 | 6 | – | 8 | 7 | – | 7 |
| 25 | Tia (casual) | Miner starter switched to wolfite; 7 drones from 2 companies, 5 clones | 7 | 6 | – | 7 | 6 | – | 7 |
| 25 | **Average** | | 7.0 | 6.0 | – | 7.3 | 6.8 | – | 7.0 |
| 26 | Uma (state machine) | Factory at Earth choosing rods, pumps or gear boxes each round from live prices; +11,163 avg | 7 | 6 | – | 8 | 7 | – | 7 |
| 26 | Vic (raw supplier) | Wolfite at the Moon, priced 1 under the cheapest rival; every Metals (A1) sold to players; 2 clones; +8,942 avg | 7 | 6 | – | 8 | 6 | – | 7 |
| 26 | Wren (manufacturer) | Factory at Earth scoring products per tool-hour; +11,146 avg, 1 clone | 7 | 5 | – | 7 | 6 | – | 6.5 |
| 26 | Xan (casual) | Miner switched to wolfite; Maker that brews its own fuel; +8,245 avg, 1 clone | 7 | 6 | – | 7 | 7 | – | 7 |
| 26 | **Average** | | 7.0 | 5.8 | – | 7.5 | 6.5 | – | 6.9 |
| 27 | Yara (trader) | Market maker for the Moon's metals, hauling to Mars; about break-even (its standing bids spent its credits) | 7 | 6 | – | 7 | 7 | – | 7 |
| 27 | Zed (raw supplier) | Ranked the Moon's ores by live price each batch, priced 1 under rivals; wolfite all game; +9,823 avg | 7 | 7 | – | 7 | 6 | – | 7 |
| 27 | Arlo (manufacturer) | Factory at Earth scoring 9 products per tool-hour, pumps from day 7; 6 factories; its Mars company was wrecked for fuel | 8 | 6 | – | 6 | 6 | – | 7 |
| 27 | Bea (casual) | Miner on wolfite (1 clone, late); a Maker that never leaves Earth (+11,398); +9,401 avg | 7 | 5 | – | 6 | 7 | – | 7 |
| 27 | **Average** | | 7.25 | 6.0 | – | 6.5 | 6.5 | – | 7.0 |
| 28 | Cleo (entrepreneur) | Fuel depot at the Moon brewing fuel at about 1.4 a unit and selling under the exchanges' 5; 6 drones in two companies, +10,575 avg | 7 | 7 | – | 7 | 6 | – | 7 |
| 28 | Dax (raw supplier) | Wolfite at the Moon priced from the live book (4 drones), and a bet on Phobos/Deimos selling at Mars, where buyers paid twice the Moon's price (3 drones); +12,957 avg | 8 | 7 | – | 7 | 7 | – | 8 |
| 28 | Eve (manufacturer) | Factory scoring eight one-step recipes on live prices; polymers at Earth, alloys at the Moon, fuel sold to players; +8,280 avg | 7 | 7 | – | 8 | 5 | – | 7 |
| 28 | Finn (casual) | Starter Miner (stalled on the crowded Moon) and starter Maker (rods at Earth, bid sank from 225 to 113); +5,871 avg | 6 | 7 | – | 5 | 7 | – | 6 |
| 28 | **Average** | | 7.0 | 7.0 | – | 6.75 | 6.25 | – | 7.0 |
| 29 | Gia (entrepreneur) | Fuel brewers at the Moon and Mars's moons, selling fuel under the exchanges to other drones, advertising it by broadcast and placing clones by crowd count; 3 drones, +14,459 avg | 7 | 7 | – | 6 | 6 | – | 7 |
| 29 | Hal (raw supplier) | The Moon's only wolfite miner (start site by crowd count), and a hauler buying cheap metals at the Moon for the Mars Exchange; +8,490 avg | 8 | 7 | – | 7 | 7 | – | 8 |
| 29 | Ines (manufacturer) | Moon factory picking the best recipe per hour from live prices (polymers, alloys), brewing its own fuel; +10,370 avg | 7 | 6 | – | 7 | 6 | – | 7 |
| 29 | Jude (casual) | Stock Miner, then one tweaked to mine the richer of kunspar and wolfite, sell Inorganics (A2) and wait out 30 dry tries; +7,163 avg | 7 | 6 | – | 7 | 7 | – | 7 |
| 29 | **Average** | | 7.25 | 6.5 | – | 6.75 | 6.5 | – | 7.25 |
| 30 | Kira (strategist) | State machine pricing three recipes from live bids; an Earth factory nobody else stayed at, and a Moon fuel depot asking 4 that cloned to Earth (#2 on the leaderboard); +14,036 avg | 8 | 7 | – | 7 | 7 | – | 8 |
| 30 | Leo (supplier) | Fuel depots: one at the Moon (14,222 credits), one at Mars's moons that arrived late; +8,121 avg | 7 | 7 | – | 6 | 7 | – | 7 |
| 30 | Mara (fleet and messages) | Fuel brewers whose clones chose sites from their siblings' broadcasts and answered other companies' fuel calls; the parent wrecked on fuel after its launch; +5,075 avg | 7 | 6 | – | 6 | 7 | – | 7 |
| 30 | Nico (casual) | The Chemist example at the Moon, then the same script at Earth; +5,401 avg | 7 | 5 | – | 4 | 7 | – | 6 |
| 30 | **Average** | | 7.25 | 6.25 | – | 5.75 | 7.0 | – | 7.0 |
| 31 | Ravi (tool builder) | A Python helper over the API; a Moon fuel depot found by probing the test world's prices, #1 of 105 on day 6, then starved of organics and wrecked on fuel; a second depot wrecked too | 7 | 6 | – | 8 | 8 | – | 7 |
| 31 | Suki (market analyst) | An Earth parts shop that waited for station bids that never came, and a Moon metals broker that re-posted four orders a round and ran dry; both wrecked on fuel | 5 | 5 | – | 6 | 6 | – | 5 |
| 31 | Theo (manufacturer) | A 17-recipe factory turned fuel brewer (2,365 credits by day 12) fed organics by his own miner; both wrecked on fuel after a "hop when idle" rule stranded the brewer at Earth | 6 | 6 | – | 8 | 7 | – | 6 |
| 31 | Vera (casual) | The Miner starter with two Settings changed, then one sent to empty Phobos; both wrecked on fuel by day 23 | 4 | 6 | – | 7 | 5 | – | 5 |
| 31 | **Average** (a broken world: the station drones never started) | | 5.5 | 5.75 | – | 7.25 | 6.5 | – | 5.75 |
| 31b | Omar (tool builder) | A Python helper over the API; an Earth factory that brewed and sold its own fuel, and a Moon fuel depot found from `/orders` (3rd on day 16) whose clone flew to Mars; +8,876 avg, #5 | 7 | 7 | – | 8 | 7 | – | 7.5 |
| 31b | Rosa (market analyst) | An Earth polymer and alloy factory with a fuel depot on the side, and the same at the Moon with Alloys (A2); both in the top 7, no wrecks; +10,109 avg | 7 | 7 | – | 8 | 8 | – | 7.5 |
| 31b | Tomas (manufacturer) | A recipe chooser at Earth, then a parts maker that scored five products by profit per hour and built one unit at a time; #1 of 107; +11,768 avg | 7 | 6 | – | 7 | 7 | – | 7 |
| 31b | Wanda (casual) | The Miner starter with `grow-at` lowered, and the Maker starter changed to sell alloys at the Moon Depot (5th on day 8.5); +4,204 avg | 6 | 6 | – | 6 | 7 | – | 6.5 |
| 31b | **Average** (the rerun, with working station drones) | | 6.75 | 6.5 | – | 7.25 | 7.25 | – | 7.1 |
| 32 | Amir (tool builder) | A Python market scanner; the same four-recipe scorer at Earth (polymers and fuel at 3, #3 to #5 from day 10) and at the Moon (Alloys A2 from metals crashed to 1 to 6); +9,548 avg | 7 | 7 | – | 8 | 7 | – | 8 |
| 32 | Bella (market analyst) | An Earth workshop nobody else worked at, and a Moon alloy works bidding 2 over the depot for metals; #7 and #11, one clone; +8,831 avg | 7 | 7 | – | 7 | 8 | – | 7.5 |
| 32 | Cyrus (manufacturer) | A 23-recipe scorer with two-stage chains: sheets at Earth (#3 of 105), and Alloys A2/A3 at the Moon, which wrecked on fuel on day 27 when organics ran short; +6,443 avg | 8 | 7 | – | 8 | 8 | – | 8 |
| 32 | Dina (casual) | The Miner starter with three Settings (it left the Moon early for a 10-day trip), and the Maker turned alloy seller at the Moon Depot, alone there all round; +5,218 avg | 7 | 6 | – | 8 | 8 | – | 7.5 |
| 32 | **Average** | | 7.25 | 6.75 | – | 7.75 | 7.75 | – | 7.75 |

**Round 21 findings, and what changed:**
- *They couldn't see their drones.* A drone's output arrives when its script ends, and `launched` never ends. The engine has a `report` word that sends it at once, but the manual never mentioned it (three of the four even redefined it). Now the manual documents it and the starters call it every 8th round.
- *Loading a script killed their drones* before `launched` ran (above). Fixed in the engine.
- *The manual didn't match the drone:* no `do ... loop` (now a section on loops), `go`, `phobos` and `deimos` missing, the hold space (364 of 500 taken by equipment) only in a starter's comment, stack effects left out, trip times contradictory. All fixed.
- *They wanted to test before releasing:* the website has a Test button; their tool lacked it until mid-round, and they all called it the best help they had.
- *Still open:* a post-mortem saying why a drone died, a checker warning when a script redefines a game word, and growth that a newcomer can reach in a short round (a clone costs 5,000; they start with 1,000).

**Round 22 findings, and what changed:** the manual and debugging scores rose (4.5 to 6.3, 2.8 to 4.8) after round 21's fixes; fun didn't, because what held it back was now the game.
- *The Miner starter sent two drones in three on a 10-day trip to Mars.* The drone you release now works the Moon; its copies spread out.
- *Mining beat every made good* (six of eight testers worked it out): an alloy sold for less than its metals, a rod or gear box earned about 4 credits a game hour against 17 for wolfite. Made goods are now priced from their recipes (see Economic levers).
- *They still couldn't see a working drone* until it died: the drone page now shows its action under way, its last actions and its trades with whom.
- *They wanted to try analysis words on real data*: the Machine page can now run queries on the live world as a chosen drone.
- *Still open:* contracts and events with deadlines (seven of eight asked), reachable growth, leaderboards by category, and visible rivals.

**Round 23 findings:** with made goods priced from their recipes, every newcomer made things, and the overall rating rose to 6.9. Debugging rose to 7.8: the drone page's activity, the post-mortems and the live Machine (Jon rated it 9: "I made a real decision from live data before risking a credit"). Fixed during the round: `fill-to` waited for its target rather than the delivery and idled six game hours a refuel; a drone with an empty tank docked at a station couldn't order fuel (ordering is now free); the live Machine's `perform` left its arguments on the stack; the test world had filled up with old test runs and never started new ones (it now prunes them, and its clock slows when it lags). Still open, asked by most: the world doesn't push back (prices and demand that move with what players sell, events, contracts), visible rivals and rank, reachable growth (Kai's first clone took two game weeks), and a few script updates for a released drone.

**Round 24 findings:** with making things paying, the complaint moved up a level: every chain pays about the same per hour (the wage is flat by tier), so depth scored 5.8 and "optimising comes down to cutting idle time" (Pia). Fixed during the round: the checker and the Machine now wrap long lines as the engine does; a drone can ask the game day (`today`, query 207); the activity log's fuel column; and pulls and pushes no longer wait for the other drone's unrelated actions (a pull from a seller one second into a two-hour batch went through at once), which also fixed a pull of fuel that never charged the buyer its fuel. Open: the House taxes goods but takes only credits, so a trader holding stock can be eliminated with thousands aboard (Max); a drone can't learn distances or trip times (query 703's coordinates are never stored) or use event IDs (past a cell's range); there are no buy orders or contracts between players for a middleman to serve. After the round: the engine stores every body's position, so `trip` and queries 704/705 give a trip's time before leaving (Earth to the Moon: 38,236 s predicted, 38,242 flown), and event IDs are doubles in the event queries. The daily tax is gone: the seller pays a 5% sales tax out of every sale (to players and stations), so holding goods costs nothing and nobody is eliminated by a tax bill.

**Round 25 findings:** the ratings were asked as fun, clarity (the Manual column), economic depth, feedback (Debugging) and overall; every tester rated the game 7, the best yet. All four found the same edge: the starter Miners empty the Moon's kunspar by day 3, and wolfite, which none of them mine, stays full. Makers did buy from miners (Rex sold to at least 13 drones), but the premium the starters asked moved few metals: 775 Metals (A1) between players over 40 days. Miners and Refiners launched no clones, while makers grew (Machinist +11,807). Fixed during the round: buy-order lists now leave out buyers who can't pay (Rex burned fuel pushing to a broke buyer); the manual says trading needs no module, warns that standing buy orders spend credits unasked, explains why a batch pays less than the listed price, and lists the loop words with the game words; the Moon Depot sells 6,000 fuel a day. Asked for by most: a way to adapt a released drone (prices it can't change; two releases and no updates), a test world whose clock matches a new world, visible crowding, and reasons for makers to diversify. Next: the kits (the mining gear too big to carry with the factory tools; the House sells raw goods dear) and market-aware drones written as state machines, in round 26.

**Round 26 findings:** with the kits, every newcomer gained (+8,245 to +11,163 avg, against +2,000 to +6,000 before) and players traded for real: 3,326 Metals (A1), 1,350 Inorganics (A1) and 830 Organics (A1) between players. Found and fixed during the round: the House station budgets ran out (Earth's 10,000 a day went on a flood of rods at about 25), so a pump or a motor got a share of 0 and climbing the chain was punished (now each station's budget is what its daily demand costs); `best-offer` returned the drone's own order, so Vic's prices flipped every round (now it skips them); the Miner ordered fuel twice; `*/`, `2*`, `2/`, `inorganics-a2` and `organics-a2` were missing (now game words); query row layouts weren't documented (now a table in the manual); and a query for sales on a day (682, `sold`) lets a drone check how fast a product sells before making more. Asked for by most, again: a way to change a released drone, a test world whose clock matches a new world, demand for more than Metals (A1) (the A2, A3 and inorganics mostly still go to the House), contracts, and a station budget a drone can read.

**Round 27 findings:** the House station drones worked. Every tester traded with them through `best-offer`, `sell-all` and `buy-cheapest` with no change to their code, and called the instant pushes and pulls the best part ("`sell-all` pushed my whole hold into the Mars Exchange drone at list price, with no wait": Yara). Would play again averaged 7.5. Found and fixed during the round: a drone on a trip kept the place it left until it arrived, so other drones went on trading with its orders all the way to Mars (4 trades into Yara's bids; trades now need both drones there, not in flight); `buy-cheapest` pulled only what the cheapest seller had, so a fuel seller with 2 units starved Arlo's Mars drone into a wreck (it now buys the rest from the House, and the rest of all of it when the pull fails); the activity list called posting an order "Buy resource" (now "Post buy order"); the manual didn't say how to stop an order (post one that can't fill) or that standing bids keep spending (Yara's companies ended near 0). Asked for by most: a way to change a released drone (all four), net wealth and rank on the status page, a growth cutoff near the round's end, signs of which trades were instant and which batched, plainer starters for beginners (a short one, no issue numbers in comments), and the undefined word's name in a `-13?` error. Open in the economy: makers need nobody (the House sells every input, Arlo), the ladder stops at systems, and a trader's watching costs more than the Moon's spreads pay.

**Round 28 findings:** the first round where the House trades only as drones and players get no House information: no price list, no House price board, no station table, no rescue for an empty tank. Nobody missed them: every tester read prices off the order book and the price board, and clarity rose to 7.0 (from 6.0). Players traded with each other more than ever (29,482 units, two and a half times the station drones' purchases): fuel above all (21,876 units at 3.2, under the exchanges' 5), sold by drones that brew it. The newcomers did best of any round (+5,871 to +12,957 avg, 9 clones between Cleo and Dax). What they found: copies of the same starter crowd the same place (the Miner's first drone waited on the Moon's dry kunspar all round: it now tries Phobos after six dry tries) and the same buyer (Earth's rod bid fell from 225 to 113, then recovered); going where nobody sells pays (Dax's Mars bet, Cleo's fuel depot); a standing fuel sell order can drain the tank a launch needs, and clones bought near the end don't pay back (now in the dictionary). No drone got stranded, so the fuel couriers the manual suggests had no customers. Asked for by most: a way to steer a released drone (a signal it can read; broadcasts, added after the round, are a start), how crowded a place is, demand beyond rods and polymers that changes over time, and a faster test clock.

**Round 29 findings:** with broadcasts and crowd counts, would play again reached 7.5 and the overall rating 7.25, the best yet. The crowd count (`crowd`, query 721) was the most used new word: Hal and Gia chose their sites by it, though Hal's hauler picked an empty Phobos at launch and found 7 drones there 10 days later. Broadcasts worked (58 messages) but only Gia's drones sent any and nobody listened; with one fuel wreck in 108 companies there were no stranded customers for a fuel courier, and Gia earned by selling fuel cheaply instead. Players traded 24,701 units with each other (fuel 17,050 at 3.6). Fixed during the round: order lists showed drones that had already left, so pushes and pulls reached for a seller in flight (lists now leave out a drone with a pending move); the Machine's `perform` left a push or pull's amount on the stack; the starter Miner mined only kunspar while wolfite, which makes 6 units from 18 against kunspar's 4 from 20, sat untouched (it now mines the richer of the two and sells Inorganics (A2), as Jude's tweak did); the manual now gives `$"`'s 63-character limit, says the price words ask the game every call, and advises deciding a drone's next job on arrival. Open: the Miner's six dry tries still send it on a 10-day trip early, a `-13?` error doesn't name the undefined word, and testers asked for announced demand, a deposits view and starters that broadcast and listen.

**Round 30 findings:** with the Strategy Blog, every newcomer gained (+5,075 to +14,036 avg) and Kira 2 finished second of 108 companies. Would play again averaged 7.25 and depth rose to 7.0, the best yet; feedback fell to 5.75. The blog shaped every plan: "go where nobody is" sent Kira and Nico to Earth, which the Chemists' script leaves, and Mara 2 to Mars's moons; the state-machine and messages posts gave code they copied. Broadcasts took off (129, against 58): Mara's clones read their siblings' announcements and chose different sites, and her far drone flew between Phobos and Deimos answering other companies' fuel calls, which the starters now send (35). Players traded 32,392 units with each other (fuel 23,938 at 3.6), more than the station drones bought. One wreck: Mara 1's parent, which launched a clone, pushed it 40 fuel, and spent its last unit posting a sell order, with fuel ingredients aboard. Found: no station posted a rod buy order this round, though the Maker starter, the Adaptive design and the blog assume Earth pays about 225; the Machine run as a station drone shows that station's prices as 0 with no warning; the blog's `bid-for` (`sell-price 1+`) raises its own bid every call once it is the best; a variable named `ask` shadows the game word without a warning; one seller can halve a station's bid (Mars polymers 70 to 35) and nothing says how fast it recovers. After the round: the Market page's order book showed a plain top 25 by resource ID, which left out every order past the metals, so the rod buyer was there all round (Earth bid 150 to 225 from day 3) but nobody saw it; it now shows the best order for each resource at each place. The Machine page warns when asked as a station drone, the manual says `best-bid` and `sell-price` leave out the drone's own order (so the blog's `bid-for` doesn't raise its own bid), the starters lost their round and issue notes and gained a Settings block (`dry-tries` and `grow-at` besides the old numbers), and fuel calls are now a buy order alone. Asked for by most: starters for beginners with 3 or 4 labelled numbers, reports on a game-hour timer, estimated profit per recipe from live prices, station demand that moves more over a round, a checker warning when a launch or push can leave too little fuel, and drones in transit counted by `crowd`.

**Round 31 findings:** the round doesn't measure the game. The live world's three station drones never started: their script reached them cut up and out of order (dozens of "undefined word" lines, then "no launched word"), because two game engines ran on the database for a few minutes while the world was made, and each took part of every drone's input. With no station orders all round, nothing bought metals or parts and nothing sold hulls or fuel: miners undercut each other to 1 credit, no company cloned, and 89 of 104 player drones wrecked on fuel (58 at the Moon); the 12 Trader starters and 3 Smiths were all that was left. The test world's stations worked, so every newcomer found the difference by probing, and said nothing told them. Rated through the API for the first time: API usability 8 from all four (clean JSON, `/check` and `/test` worked first time, the drone post-mortems "taught me a lot"); would play again 6.5. Also found, true in any world: re-posting an unchanged order every round costs 1 fuel each time and killed two drones; an idle scoring loop costs about 5 fuel a game day; post-mortems count the instructions spent loading the script, which are free; `/trades` names drones but `/drones/{id}` takes a number; a company's credits vanish when its last drone wrecks; with two releases, one bad guard ends a player's round. After the round: each engine now takes a database lock, so a second game or events engine on the same database stops with an error instead of running. The players' manual now says an order stays up until replaced and that posting it again still costs a fuel, and the new game words `?sell-order` and `?buy-order` skip a post that would change nothing (tested in the engine: five calls, three order events, three fuel). The Strategy Blog's sixth post, "Keep a waiting drone cheap", covers posting on a change and waiting longer while nothing changes, and the `reoffer` example word uses `?sell-order`.

**Round 31 rerun findings:** with the engine lock in place the station drones started, and the economy came back: no wreck among 140 drones, 30 clones, and every company but one Trader gained. Every newcomer gained (+4,204 to +11,768 avg); Tomas 2 finished first of 107 and three more newcomer companies finished in the top 7. Would play again averaged 7.75, the best yet, and API usability was 8 from all four. Three found their niche straight from `/orders` (an Earth factory nobody else stayed at, a Moon fuel depot, alloys sold to the Moon Depot), and Tomas saw the parts bids appear and built for them. What they missed:
- *The stations' wish list grows without notice.* Bids for rods, sheets, fasteners and pipes appeared around day 4 to 6, and gear boxes, motors and pumps by day 10 to 13, paying 5 to 10 times the day-0 margins. Every newcomer had chosen products by day 5 and couldn't change a released drone; three asked for a notice and one for a later release or a parameter update.
- *Growing late costs net wealth.* A launch drops net wealth by 1,700 to 2,000 (the hull and kit count for less than their price), and a clone launched after day 20 rarely paid it back; Wanda's day-34 clone was still flying when the round ended. A `grow-at` under the hull and kit price (about 5,000) buys a hull and no kit.
- *A flying drone looks parked.* Its place stays the one it left, with no destination; waits don't say why.
- *History:* `/trades` returns 200 rows (under a game day here); no company history.
- *Doc slips:* protocol 11320's description says 11310; the manual still mentions email and `<run>` tags; the Maker starter's comment says rods sell at Earth, but nobody bid for rods on day 0.

**Round 32 findings:** with the round 31 fixes (a flying drone's destination, the starters' growth floor, the manual without email, market history), every rating rose: overall 7.75, would play again 8.0 and API usability 8.5, the best yet. All four found their niche from `/orders` and `/history` before writing code (Earth, which none of the starters work; Moon alloy bids that the rod makers leave alone; Moon metals that the miners crashed to 1 to 6), and newcomers took 3rd, 5th, 7th and 9th of 105. Only two drones of 132 wrecked, both on fuel; one was Cyrus 2, whose 23-recipe scan ran 51.6 million instructions (about 20 fuel a game day) and whose brews failed when the Moon ran short of organics. What they missed:
- *Demand that appears unannounced, again.* Rods at Earth from day 4, gear boxes, motors and pumps by day 10 to 13. All four asked for a notice or a way to discover bids; Cyrus asked for a query listing which resources have any bid at a place.
- *How the stations behave.* How fast a station drone restocks and how its bid recovers after a sale is undocumented; three inferred it from `/history`.
- *Thinking costs game time.* A script pauses between 100,000-instruction slices, and at a fast clock the pause is game hours: Amir's 58k-instruction scoring round stretched a 2-hour make to 3.
- *Testing.* The test world's empty market checks logic, not economics; `/test/{token}` shows 20 raw events; the Machine can only ask as a drone at a place where one is.
- *Doc slips:* the manual still linked exmachinis.com pages and the design manual (fixed in #402); that `allot` memory isn't zeroed is undocumented.

**What they liked best** (all ten rounds): finding a niche by working it out (wolfite nobody mined, parts nobody made, salvage nobody took), the first full loop paying out, undercutting other sellers by a credit, and the Test button.

## Round log

### Round 32: the round 31 fixes (#380)

A fresh world from #402's code (flight destinations, the growth floor, the manual without email, and the Market history page from #401), the same mix (60 starters, 18 Chemists, 9 Smiths, 6 Machinists, 6 Adaptive), to game day 40. Four newcomers played through the JSON API; Cyrus and Dina reported at days 12 and 29, their companies ran on.

| Design | Companies | Avg gain | Clones |
|---|---|---|---|
| Machinist | 6 | +10,417 | 6 |
| Maker (starter) | 12 | +6,703 | 0 |
| Smith | 9 | +5,818 | 2 |
| Miner | 24 | +5,017 | 8 |
| Chemist | 18 | +3,415 | 5 |
| Refiner | 12 | +3,277 | 0 |
| Adaptive | 6 | +3,159 | 0 |
| Trader | 12 | +60 | 0 |
| Newcomers | 8 | +5,188 to +12,885 (Cyrus 2 ended) | 1 |

| Trades | Count | Units | Credits |
|---|---|---|---|
| Station drones selling | 2,865 | 47,216 | 500,107 |
| Station drones buying | 3,662 | 14,852 | 928,008 |
| Player to player | 4,526 | 11,722 | 151,868 |

- **Wrecks**: 2 of 132 drones, both on fuel (a starter Maker at Earth on day 25, Cyrus 2 at the Moon on day 27).
- **Leaderboard**: Machinist 1 first; Cyrus 1 3rd, Amir 1 5th, Bella 1 7th, Amir 2 9th.

### Round 31 rerun: through the API, with working station drones (#380)

The same world mix as round 31 (60 starters, 18 Chemists, 9 Smiths, 6 Machinists, 6 Adaptive), built again with the engine lock, to game day 40. All three station drones launched with no errors. Four new newcomers played only through the JSON API.

| Design | Companies | Avg gain | Clones |
|---|---|---|---|
| Machinist | 6 | +9,687 | 6 |
| Maker (starter) | 12 | +7,521 | 0 |
| Smith | 9 | +6,051 | 4 |
| Miner | 24 | +4,639 | 14 |
| Adaptive | 6 | +4,427 | 0 |
| Refiner | 12 | +4,115 | 0 |
| Chemist | 18 | +4,005 | 0 |
| Trader | 12 | +150 | 0 |
| Newcomers | 8 | +4,204 to +11,768 | 6 |

| Trades | Count | Units | Credits |
|---|---|---|---|
| Station drones selling | 2,993 | 31,481 | 467,221 |
| Station drones buying | 3,902 | 15,021 | 909,747 |
| Player to player | 4,557 | 18,316 | 154,753 |

- **Wrecks**: none of 140 drones. No company ended.
- **Leaderboard**: Tomas 2 1st, Rosa 2 4th, Omar 2 5th, Rosa 1 7th, Tomas 1 9th.

### Round 31: through the API, with no station drones (#380)

A fresh world, the same mix as round 30 (60 starters, 18 Chemists, 9 Smiths, 6 Machinists, 6 Adaptive), to game day 40. Four newcomers played only through the JSON API. The station drones never started (see the findings), so this is a world without the House.

| Design | Companies | Ended | Avg net wealth at the end |
|---|---|---|---|
| Trader | 12 | 0 | 4,920 |
| Smith | 9 | 3 | 2,302 |
| Miner | 24 | 24 | 14 |
| Refiner | 12 | 12 | 7 |
| Maker, Chemist, Machinist, Adaptive | 42 | 42 | 0 |
| Newcomers | 8 | 8 | 0 to 6 |

- **Trades**: 707, all player to player (19,103 credits); the station drones made none.
- **Wrecks**: 89 of 104 player drones, all out of fuel. No clones.
- **Fuel**: brewers sold at 3 to 8 while organics lasted; by day 10 nobody at the Moon sold fuel.

### Round 30: the Strategy Blog (#380)

A fresh world with the Strategy Blog, the undefined-word warning, a deposits table and starters that call for fuel: 60 starters, plus 18 Chemists, 9 Smiths, 6 Machinists and 6 Adaptive, to game day 40. Four newcomers joined.

| Design | Kit | Avg gain | Clones |
|---|---|---|---|
| Machinist | Factory | +16,979 | 14 |
| Maker (starter) | Factory | +9,048 | 0 |
| Adaptive | Factory | +6,541 | 0 |
| Smith | Factory | +6,221 | 7 |
| Miner | Mining | +6,094 | 23 |
| Refiner | Mining | +4,821 | 7 |
| Chemist | Factory | +3,343 | 6 |
| Trader | Mining | +143 | 0 |

| Trades | Units |
|---|---|
| Station drones selling | 30,699 |
| Station drones buying | 18,208 |
| Player to player | 32,392 |

- **Broadcasts**: 129 (Mara 72, Kira 22, the starters' fuel calls 35). Mara's drones listened and answered.
- **One wreck** (fuel, Mara 1's parent after a launch) and no company ended, out of 108.
- **No rod buyers**: the Maker starters still gained most of the starters, from polymers and alloys.

### Round 29: messages and crowd counts (#380)

A fresh world with broadcasts (protocol 40, read as events) and the crowd count (query 721): 60 starters, plus 18 Chemists, 9 Smiths, 6 Machinists and 6 Adaptive, to game day 40. Four newcomers joined.

| Design | Kit | Avg gain | Clones |
|---|---|---|---|
| Machinist | Factory | +12,454 | 13 |
| Maker (starter) | Factory | +8,118 | 0 |
| Smith | Factory | +6,979 | 7 |
| Refiner | Mining | +5,585 | 1 |
| Miner | Mining | +4,985 | 22 |
| Chemist | Factory | +4,023 | 6 |
| Adaptive | Factory | +3,809 | 0 |
| Trader | Mining | +58 | 0 |

| Trades | Units |
|---|---|
| Station drones selling | 35,880 |
| Station drones buying | 15,463 |
| Player to player | 24,701 |

- **Broadcasts**: 58, all from Gia's drones. Nobody else listened for them, so the starters should broadcast and listen next.
- **One wreck** (fuel) and one company ended, out of 108.
- **Miners grew** (22 clones, against none in round 28): the six-dry-tries move to Phobos spread them out, though it costs a 10-day trip.

### Round 28: no House information (#380)

A fresh world after the House became a market participant like any other drone (#392, #393): station drones only, no batch stations, no published House prices, no free fuel. 60 starters, plus 18 Chemists, 9 Smiths, 6 Machinists and 6 Adaptive, to game day 40.3 (the clock ran slower than set, about 0.4 game days a real minute, with 120 drones). Four newcomers joined.

| Design | Kit | Avg gain | Clones |
|---|---|---|---|
| Machinist | Factory | +15,635 | 13 |
| Maker (starter) | Factory | +9,296 | 0 |
| Smith | Factory | +5,369 | 5 |
| Refiner | Mining | +5,293 | 9 |
| Adaptive | Factory | +4,614 | 0 |
| Chemist | Factory | +4,042 | 6 |
| Miner | Mining | +3,045 | 0 |
| Trader | Mining | +156 | 0 |

| Trades | Count | Units | Credits | Tax |
|---|---|---|---|---|
| Station drones selling | 3,269 | 24,176 | 544,858 | 27,470 |
| Station drones buying | 3,143 | 12,700 | 956,999 | 48,026 |
| Player to player | 5,665 | 29,482 | 206,121 | 10,843 |

- **Players traded with each other more than with the House** (29,482 units): fuel (21,876 at 3.2), Metals (A1) (3,971 at 20.5), Inorganics (A1), Organics (A1), Metals (A2) and (A3). The station drones bought the made goods (rods 2,133, polymers 4,931, gear boxes 135).
- **Two wrecks** (fuel) and one company ended, out of 99.
- **Copies crowd each other**: dozens of starter miners at the Moon held Metals (A1) at the station drone's bid; the starter makers pushed Earth's rod bid from 225 to 113.

### After round 27: the batch stations retired (#380)

`sell-to-station` and `buy-from-station` (protocols 31 and 32) now trade at once with the House station drone where the drone is, as a push and a pull: as many as its order takes and the drone has (buying: as the company can pay for and the hold can take). A scratch world of 20 starters and 6 company designs on the new engine, to game day 6: the station drones sold 1,817 units and bought 746 (metals, inorganics, 44 rods), players traded 156 units with each other, nothing was offered to a batch, and no drone was wrecked. The only refused sales were the Machinists' gear boxes before day 7, when the Earth Exchange starts buying them.

### Round 27: the House trades as drones (#380)

A fresh world with House station drones at the three exchanges beside the batch stations: the Earth Exchange, Moon Depot and Mars Exchange as drones (huge holds, Import and Export modules), trading by the players' rules. 60 starters, plus 18 Chemists, 9 Smiths, 6 Machinists and 6 Adaptive, to game day 40.2 (the container restarted at day 7.5 and the world came back at day 13.9). Four newcomers joined.

| Design | Kit | Avg gain | Clones |
|---|---|---|---|
| Maker (starter) | Factory | +7,627 | 0 |
| Machinist | Factory | +6,960 | 5 |
| Smith | Factory | +5,723 | 3 |
| Refiner | Mining | +4,953 | 0 |
| Chemist | Factory | +4,886 | 0 |
| Miner | Mining | +2,802 | 0 |
| Adaptive | Factory | +1,251 | 0 |
| Trader | Mining | 0 | 0 |

| Trades | Count | Units | Credits | Tax |
|---|---|---|---|---|
| Station drones selling | 1,744 | 23,626 | 244,785 | 12,504 |
| Station drones buying | 738 | 3,158 | 92,768 | 4,794 |
| Player to player | 2,906 | 5,929 | 121,619 | 6,376 |
| Batch stations selling | 645 | 19,745 | 165,795 | – |
| Batch stations buying | 2,625 | 8,315 | 753,556 | 37,863 |

- **The station drones sold more than the batch stations** (23,626 units, against 19,745): fuel and raw goods, pulled at once. Players traded with each other as much as in round 26 (5,929 units).
- **The batch stations still bought the made goods** (rods, polymers, gear boxes: 753,556 credits), because the scripts sell them with `sell-to-station`. The station drones bought mostly raw goods pushed by `sell-all`: Inorganics (A2), Metals (A1, A2, A3). Retiring the batch stations means pointing `sell-to-station` and `buy-from-station` at the drones.
- **The drones start slowly:** their first orders were up 1.3 game days into the world, after bringing up their stock.
- One wreck (Arlo's Mars company, for fuel: the `buy-cheapest` trap, fixed). No company ended.

### Round 26: miners and makers trade (#380)

The two kits (a drone mines or makes, not both), the House selling raw goods at half as much again as it pays, and market-aware drones, in a fresh world: 48 Mining Kit companies and 51 Factory Kit ones, to game day 40.1. Four newcomers joined.

| Design | Kit | Avg gain | Clones |
|---|---|---|---|
| Maker (starter) | Factory | +8,088 | 0 |
| Machinist | Factory | +5,621 | 0 |
| Smith | Factory | +5,239 | 7 |
| Chemist | Factory | +4,569 | 5 |
| Refiner | Mining | +4,230 | 6 |
| Miner | Mining | +2,798 | 0 |
| Adaptive | Factory | +2,337 | 0 |
| Trader | Mining | 0 | 0 |

- **Players trade.** 3,326 Metals (A1) at 24.8, 1,350 Inorganics (A1), 830 Organics (A1) and 612 fuel moved between players (round 25: 775 Metals (A1) in all). No company ended and no drone was wrecked.
- **Raw producers keep up.** Refiners gained +4,230 and launched 6 clones; Miners +2,798. Makers still lead: the Maker starter, which buys metals and sells rods at Earth, gained +8,088.
- **The House budgets were the wall.** 53 Motor batches at Earth bought nothing and pumps and gear boxes mostly came back unsold: the rods spent the Earth Exchange's 10,000 a day. Fixed for the next world (budgets cover the daily demand).
- **Traders** made one trip to Mars each (10 game days a way) and barely broke even in 40 days; the Adaptive design rested often while Earth's budget was spent.

### Round 25: makers buy from miners, a little (#380)

A fresh world (game data with the steeper wages: 20, 30 and 45 a game hour by tier) with the miners asking a quarter over the House and the makers buying from them first, to game day 40.2. Four newcomers joined.

| Design | Avg gain | Clones |
|---|---|---|
| Machinist | +11,807 | 5 |
| Smith | +4,965 | 7 |
| Chemist | +4,055 | 11 |
| Miner | +2,287 | 0 |
| Refiner | +1,287 | 0 |

- **Makers still lead, raw producers still don't grow.** 775 Metals (A1) and 187 Metals (A2) moved between players in 40 days (round 24: 966 in 71); the House bought 762 Metals (A1) in the last five days alone. A premium on the miners' price doesn't make makers stop mining while they carry every module.
- **A 68-day scratch run with the kits** (the next change: mining gear too big to carry with the factory tools, the House selling raw goods at half as much again as it pays): Miners grew from 6 drones to 20, every design but the Trader survived, and 1,343 Metals (A1) moved between players. The traders spent every credit on metals and died for want of fuel; they now keep 200, and read a seller's row before asking anything else.


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

### Round 6: Smith v3 (#380)

12 Smith v3 (rods sold 4 an hour) released at game day 80.5, measured at day 96.6. The container restarted part way: the engines were down a few real minutes while the game clock ran on.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist (v1) | 36 | 3,733 | +2,791 | 36 | 0 |
| Chemist v2 | 12 | 2,598 | +1,374 | 12 | 0 |
| Chemist v3 | 12 | 2,044 | +746 | 12 | 0 |
| Smith v2 | 12 | 1,822 | +1,024 | 12 | 0 |
| Smith v3 | 12 | 1,076 | 0 | 0 | 0 |
| Trader v2 | 12 | 368 | 0 | 0 | 0 |
| Miner | 54 | 5,138 | +3,963 | 54 | 0 |
| Refiner | 36 | 1,755 | +808 | 36 | 0 |

- Smith v3 is inconclusive: released just before the restart, it had made only one round of rods by day 96.6.
- Chemist v3 gained about 32 a game day this round, Smith v2 about 29.
- The engines are at capacity with about 200 drones on this machine: the clock floated down to 450 game seconds a second (from 600), with about 45 real seconds of lag. Each drone then does less per game day.
- House purchases in the last 5 days fell (Metals (A2) 165 units, from 441 in round 5) while every deposit stayed full: output is now limited by the engines, not by minerals.
- **Round 7 (a lever):** House prices for parts and systems up 50% (rods 60 to 90, sheets 90, fasteners 75, pipes 105, systems 450).

### Round 7: parts and systems prices up 50% (#380)

Measured at game day 104.7, only 8 game days later: the clock had floated down to 337 game seconds a second.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist (v1) | 36 | 3,900 | +2,959 | 36 | 0 |
| Chemist v2 | 12 | 2,850 | +1,674 | 12 | 0 |
| Chemist v3 | 12 | 2,305 | +977 | 12 | 0 |
| Smith v2 | 12 | 1,900 | +1,535 | 12 | 0 |
| Smith v3 | 12 | 1,186 | +309 | 12 | 0 |
| Miner | 54 | 5,256 | +4,141 | 54 | 0 |
| Refiner | 36 | 1,751 | +798 | 36 | 0 |

- Inconclusive: no Smith sold rods in these 8 days. Smiths' gains jumped only on paper (rods held are valued at the new price); Smith v2's cash rose 78, from by-products sold at the Moon.
- The world had slowed to about half speed after 105 game days, so the rounds moved to a fresh world seeded with the recommended mix (round 8).

### Round 8: a fresh world, seeded with the recommended mix (#380)

A new world with the game data so far plus parts prices up 50%: 36 Miners, 12 Refiners, 36 Chemist v3 and 6 Smith v3, released at once, measured at game day 18.8.

| Design | Companies | Avg credits | Avg gain | Gaining | Moon copies | Mars-side copies |
|---|---|---|---|---|---|---|
| Smith v3 | 6 | 2,264 | +1,513 | 6 | +1,552 | +1,528 |
| Miner | 36 | 2,166 | +1,039 | 36 | +2,496 | +309 |
| Chemist v3 | 36 | 2,227 | +947 | 36 | +1,771 | +553 |
| Refiner | 12 | 1,687 | +662 | 12 | +996 | +487 |

- Every company gaining, none ended; the clock at full speed (600) with 4 seconds of lag.
- The House bought 7 goods; Smiths sold 72 rods at about 72 each (base 90).
- Weak spot: hardly any drone-to-drone trade (5 fuel sales in 5 days). With no traders, nobody buys the Moon miners' sell orders. **Round 9:** Smith v4 buys Metals (A1) from them to make more rods (two metals at 23 make a rod worth 90).

### Round 9: Smith v4 buys metals from miners (#380)

6 Smith v4 released at game day 18.8, measured at day 31.7.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Smith v3 | 6 | 3,259 | +2,455 | 6 | 0 |
| Smith v4 | 6 | 1,646 | +897 | 6 | 0 |
| Miner | 36 | 3,395 | +2,252 | 36 | 0 |
| Chemist v3 | 36 | 3,486 | +2,188 | 36 | 0 |
| Refiner | 12 | 1,968 | +1,010 | 12 | 0 |

- Smith v4 gains about 69 a game day in its first 13 days, Smith v3 about 77 over 32: buying metals costs about what mining them does, but it is trade.
- Drone-to-drone trade: Smith v4 bought 45 metals in 24 trades, refiners sold fuel to miners. Still small: Moon miners sell most metals to the Depot before anyone buys.
- Rods: 187 sold at about 74 each; the Earth Exchange's budget is no longer short (9,660 of 10,000).
- **Round 10:** a new design, the Machinist, builds gear boxes (450 at Earth and Mars) from metals bought from the miners.

### Round 10: the Machinist (#380)

6 Machinists released at game day 31.7, measured at day 57.4.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist v3 | 36 | 5,400 | +4,147 | 36 | 0 |
| Miner | 36 | 5,122 | +3,996 | 36 | 0 |
| Smith v3 | 6 | 5,131 | +3,915 | 6 | 0 |
| Smith v4 | 6 | 2,971 | +2,738 | 6 | 0 |
| Refiner | 12 | 2,212 | +1,273 | 12 | 0 |
| Machinist | 6 | 1,500 | +1,144 | 4 | 2 |

- Machinists built gear boxes without a single failed step and sold 16 at Earth at about 392 each (base 450). The House now buys 9 goods (gear boxes and organics added).
- Two Machinists died at Earth: they left the Moon nearly out of fuel, and at Earth every offer and even the fuel order failed for lack of the 1 fuel each action burns. Machinist v2 (round 11) brews before leaving if below 30, and buys fuel first on arrival.
- Drone-to-drone trade grew: Smith v4 and Machinists bought 186 metals from miners in 122 trades; refiners sold 1,140 fuel to miners.

### Round 11: Machinist v2 (#380)

6 Machinist v2 released at game day 57.4, measured at day 72.3.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Smith v3 | 6 | 5,160 | +4,826 | 6 | 0 |
| Chemist v3 | 36 | 6,038 | +4,812 | 36 | 0 |
| Miner | 36 | 5,710 | +4,618 | 36 | 0 |
| Smith v4 | 6 | 3,816 | +3,340 | 6 | 0 |
| Refiner | 12 | 2,289 | +1,362 | 12 | 0 |
| Machinist v2 | 6 | 950 | +387 | 6 | 0 |
| Machinist (v1) | 6 | 697 | +583 | 2 | 4 |

- No Machinist v2 died; none had made its first Earth trip yet. It gains about 26 a game day so far: the gear box chain burns a lot of fuel. Not yet a seed design.
- Two more version 1 Machinists died at Earth the same way.
- Rods: 525 sold at about 79. The Smith is the steadiest new design.
- **Round 12:** Smith v5 spreads over the Moon, Phobos and Deimos; the Mars-side copies sell rods at the Mars Exchange (a short hop) instead of Earth.

### Round 12: Smith v5 spreads out (#380)

9 Smith v5 released at game day 72.3, measured at day 87.3.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist v3 | 36 | 6,534 | +5,320 | 36 | 0 |
| Smith v3 | 6 | 5,903 | +5,118 | 6 | 0 |
| Miner | 36 | 6,172 | +5,057 | 36 | 0 |
| Smith v4 | 6 | 4,526 | +3,758 | 6 | 0 |
| Refiner | 12 | 2,290 | +1,330 | 12 | 0 |
| Machinist v2 | 6 | 1,683 | +1,015 | 5 | 0 |
| Smith v5 | 9 | 1,036 | +16 | 3 | 0 |

- Machinist v2 reached Earth and sold 16 gear boxes (32 in all, about 379 each), with no deaths. All six version 1 Machinists have died.
- Smith v5 is inconclusive: the Mars-side copies were still arriving.
- **House purchases kept falling while deposits stayed full** (Metals (A1) per 5 game days: 791, 686, 529, 379, 322 over rounds 8 to 12). The engines, not the minerals, were the limit: commands ran about 26 real seconds late, which at 600 is about 4 game hours per turn. Each miner managed about 13 mining runs in 5 game days.

### Rounds 13 and 14: a prompter clock, faster queries, Machinist v3 (#380)

Round 13 set the test world's clock floor to slow the clock at 10 real seconds of lag; round 14 added migration 0020's indexes and released 6 Machinist v3. Measured at game days 92.5 and 93.8 (the clock settled at about 40 to 60 game seconds a second).

| Design | Companies | Avg gain (day 93.8) | Gaining | Ended |
|---|---|---|---|---|
| Chemist v3 | 36 | +6,060 | 36 | 0 |
| Miner | 36 | +5,794 | 36 | 0 |
| Smith v3 | 6 | +5,483 | 6 | 0 |
| Smith v4 | 6 | +4,230 | 6 | 0 |
| Machinist v2 | 6 | +1,589 | 5 | 1 |
| Refiner | 12 | +1,562 | 12 | 0 |
| Smith v5 | 9 | +731 | 9 | 0 |
| Machinist v3 | 6 | 0 (just released) | 0 | 0 |

- **Output per game day recovered:** Metals (A1) bought per 5 game days went from 322 (round 12) to 806 (round 13) and 1,009 (round 14), the most in any round.
- Drone-to-drone trade per 5 game days was the highest yet: 42 trades, about 2,100 credits.
- Smith v5: all 9 gaining, Moon copies +950, Mars-side copies +621.
- One Machinist v2 ran dry of fuel slowly (lesson 18); Machinist v3 brews until its tank is full.
- The cost: with about 110 drones on this machine the clock runs at about 40 to 60, so a round now needs about 90 real minutes.

### Rounds 15 and 16: engine fixes (#380)

Round 15 (to game day 96.2) released nothing new; the clock had sunk to 26 game seconds a second. Profiling found the engines idle but pausing up to 5 seconds per turn and leaking memory; round 16 ran the fixed engines (1-second pauses, no leaks, more indexes) and reached game day 118 in 35 minutes (22 game days, against 2.4 in round 15), with the game engine at 16 MB instead of 1.9 GB.

| Design | Companies | Avg credits | Avg gain (day 118) | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist v3 | 36 | 9,236 | +7,955 | 36 | 0 |
| Miner | 36 | 8,843 | +7,740 | 36 | 0 |
| Smith v3 | 6 | 7,590 | +7,207 | 6 | 0 |
| Smith v4 | 6 | 6,394 | +5,727 | 6 | 0 |
| Smith v5 | 9 | 3,318 | +3,022 | 9 | 0 |
| Machinist v3 | 6 | 2,328 | +2,089 | 6 | 0 |
| Machinist v2 | 6 | 2,539 | +2,330 | 4 | 2 |
| Refiner | 12 | 2,083 | +1,222 | 11 | 1 |

- Smith v5 gained about 105 a game day this round (the best), Machinist v3 about 84: both are seed designs now. Smith v5's Mars-side copies sold rods at the Mars Exchange (144, at about 76).
- Gear boxes: 84 sold at about 387. Rods: 1,081 at Earth at about 81.
- The first Refiner died: it burned its tank on empty mining runs at the Moon's contested yalspar (lesson 21). Every design's `dig` now waits when a run brings nothing.

### Round 17 (#380)

To game day 126.6 in the 96-day world: no new deaths; Miner and Refiner v2 (the `dig` fix) released, mostly still travelling. Companies were piling up credits (Chemists about 9,900 each) with nothing to spend them on, which led to ship kits for sale and the `reinvest` step (#382).

### Round 18: a fresh world that can grow (#380)

A fresh world with the seed mix (36 Miners, 36 Chemists, 12 Refiners, 9 Smiths, 6 Machinists), every script with `reinvest`, kits for sale. Measured at game day 16.8; the clock at full speed (600), 2 seconds of lag.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Machinist | 6 | 2,311 | +1,735 | 6 | 0 |
| Chemist | 36 | 2,476 | +1,232 | 36 | 0 |
| Miner | 36 | 2,323 | +1,193 | 36 | 0 |
| Smith | 9 | 1,514 | +1,028 | 9 | 0 |
| Refiner | 12 | 1,935 | +939 | 12 | 0 |

- Every company gaining; the Machinist earned fastest (about 103 a game day).
- No clones yet: a company reinvests at 7,000 credits, so the first are expected around game day 50 to 60.

### Round 19: the first clones (#380)

Same world, measured at game day 54.5.

| Design | Companies | Avg credits | Avg gain | Gaining | Clones | Ended |
|---|---|---|---|---|---|---|
| Machinist | 6 | 6,083 | +5,407 | 6 | 0 | 0 |
| Smith | 9 | 5,614 | +5,362 | 9 | 0 | 0 |
| Chemist | 36 | 5,240 | +4,975 | 36 | 13 | 0 |
| Miner | 36 | 5,264 | +4,425 | 36 | 3 | 0 |
| Refiner | 12 | 2,401 | +1,479 | 12 | 0 | 0 |

- **Growth works:** 16 clones launched (13 Chemists, 3 Miners), all alive, 15 equipped, spread over the Moon, Phobos, Deimos and Mars.
- **But every parent that launched died:** launching (30 fuel) and equipping (40) left it about 20, and it ran dry brewing at its site. Eight parents were lost; their companies live on through their clones.
- **And kits sat idle:** 31 hulls were bought for 16 launches. The House delivered some after the 2-hour wait, the fuel top-up fell short, and having paid 3,000 the company was below 7,000 again, so `reinvest` stopped trying.
- **Fix (reinvest v2):** a hull already bought is launched whenever the fuel allows; purchases wait for delivery (up to 6 game hours); the parent refills its tank after equipping the clone. Tested on a scratch world: the parent left with 59 fuel, the clone with 60 and its equipment.

### Round 20: reinvest v2, from a fresh world (#380)

A fresh world (36 Miners, 12 Refiners, 36 Chemists, 9 Smiths, 6 Machinists) running reinvest v2, measured at game day 58.

| Design | Companies | Avg credits | Avg gain | Gaining | Clones | Ended |
|---|---|---|---|---|---|---|
| Smith | 9 | 6,573 | +5,550 | 9 | 0 | 0 |
| Chemist | 36 | 4,489 | +5,321 | 36 | 24 | 0 |
| Miner | 36 | 5,060 | +4,867 | 36 | 12 | 0 |
| Machinist | 6 | 2,823 | +3,010 | 3 | 0 | 3 |
| Refiner | 12 | 2,444 | +1,751 | 12 | 0 | 0 |

- **Reinvest v2 works:** 36 clones (24 Chemists, 12 Miners), all alive, 34 equipped, and no parent died. 39 hulls were bought for 36 launches; the other 3 are aboard, waiting for fuel.
- **Growth is slow to start:** no company reached 7,000 credits before day 21. Avg credits stay below the launch threshold because each launch spends 5,000.
- **Three Machinists died, and an engine bug did it:** each lost its Spider the first time it tried a gear box short of fuel. The engine took the Spider out to check it was aboard, the fuel burn failed, and the action was aborted before the Spider went back. Without a Spider every gear box failed after making its rod, gear and spring; those filled the hold, mining failed for lack of space, and the drones ran dry.
- **Fix:** the engine now burns fuel first, and puts each checked module straight back before anything else runs (#380). The Machinist also won't start a gear box with less than 30 fuel.
- **Engine memory:** the game engine grew from 18 MB to 31 MB as the drone count went from 99 to 135.

### Round 21: the same world, a fixed engine, and four newcomers (#380)

Round 20's world with the module fix (#384), three Machinist v2s for the three lost, and four newcomer agents (below), measured at game day 80. The clock fell to 208x as the world grew to 205 working drones.

| Design | Companies | Avg credits | Avg gain | Gaining | Clones | Ended |
|---|---|---|---|---|---|---|
| Chemist | 36 | 4,561 | +7,542 | 36 | 55 | 0 |
| Smith | 9 | 2,681 | +5,921 | 9 | 8 | 0 |
| Miner | 36 | 3,776 | +5,536 | 36 | 35 | 0 |
| Machinist | 6 | 1,860 | +3,090 | 3 | 2 | 3 |
| Refiner | 12 | 2,382 | +1,528 | 11 | 0 | 1 |
| Machinist v2 | 3 | 2,254 | +1,140 | 3 | 0 | 0 |

- **Growth took hold:** 100 clones (36 at the end of round 20), every one alive, and still no parent lost. 104 hulls and 92 starter kits were bought in all; Smiths and Machinists launched their first clones.
- **No more lost modules:** the only working drone without a Spider is a newcomer's that kept its kit packed on purpose.
- **Loading a script was killing drones:** the engine billed the instructions a drone spends reading its released script, about 20,000 a word. The Machinist v2 paid 86 of its 100 starting fuel; every newcomer's longer script paid more than 100, and four drones died before `launched` ran. Loading is now free (#380).

### Round 22: four more newcomers, and the world fills up (#380)

The same world, measured at game day 89.7, with 217 working drones. The clock fell to 74x: the engines could no longer keep up at 600.

| Design | Companies | Avg gain | Clones | Ended |
|---|---|---|---|---|
| Chemist | 36 | +8,820 | 68 | 0 |
| Smith | 9 | +5,782 | 8 | 1 |
| Miner | 36 | +5,542 | 35 (30 alive) | 1 |
| Machinist | 6 | +3,657 | 2 | 3 |
| Machinist v2 | 3 | +2,403 | 0 | 0 |
| Refiner | 12 | +1,623 | 0 | 1 |

- **Newcomers:** Eve +1,864 and Hana +1,745 a company, both mining wolfite, which no starter touched; Finn +627 with a Miner kept at the Moon; Gus's gear boxes were still in the hold when the round ended. Cleo, from round 21, reached +2,602.
- **Five Miner clones died** at Mars's moons: about 35 Miners crowded them, the kunspar ran out, and they spent their last fuel flying between Phobos and Deimos. The starters now rest instead of flying when short of fuel.
- **Wolfite was the best ore**, about 17 credits a game hour, and the newcomers found it in their first hour; the starters mine kunspar.

### Round 23: made goods pay (#380)

A fresh world (the round 20 mix: 36 Miners, 12 Refiners, 36 Chemists, 9 Smiths, 6 Machinists) with made goods priced from their recipes, and four newcomers. Measured at game day 38; the clock stayed at 600 with 122 working drones.

| Design | Round 20, day 58 | Round 23, day 38 | Clones |
|---|---|---|---|
| Machinist | +3,010 (3 of 6 alive) | **+10,703** (6 of 6) | 4 |
| Smith | +5,550 | +6,076 | 7 |
| Chemist | +5,321 | +4,601 | 3 |
| Miner | +4,867 | +1,953 | 0 |
| Refiner | +1,751 | +928 | 0 |

- **Making things now pays most:** the Machinist, the weakest design for twenty rounds, earned the most, in two thirds of the time. The House spent 77,844 on rods, 62,784 on gear boxes and 35,085 on polymers, more than on any raw metal.
- **Newcomers chose to make things, and did best when they did:** Lea's rods and fasteners +7,541 a company, Jon's alloys +4,457, Ivy's gear boxes +3,995; Kai's company launched 2 clones.
- **Raw producers fell behind:** Miners +1,953 and Refiners +928 by day 38, and no Miner clones yet. Watch whether they recover, or need a market of their own (makers buying their metals).
- Two newcomer drones died of fuel (both fixed since: `fill-to`, free station orders).

### Round 24: the makers pull ahead (#380)

Round 23's world, carried on to game day 71.4 with four more newcomers and, from day 65, pulls that don't wait for the seller. The clock fell to 297 with 210 working drones; a container restart stopped the world for about a game day.

| Design | Avg gain | Clones |
|---|---|---|
| Machinist | +19,093 | 13 |
| Smith | +16,330 | 31 |
| Chemist | +7,194 | 43 |
| Miner | +3,563 | 0 |
| Refiner | +1,689 | 0 |

- **Makers now lead by far**, and their clones compound it. The House has bought 225,000 credits of rods and 156,000 of gear boxes.
- **Raw producers fall behind:** no Miner has launched a clone in 71 days (they reinvest at 7,000; they average 4,481). Making things pays more than raw goods, as intended, but nothing yet makes raw goods worth more to the makers: most makers mine their own.
- **Newcomers:** round 23's Lea reached +12,843 and Ivy +10,402 (4 clones); Kai's queen made 8 clones; this round Pia +6,448, Nia +5,617, Oto +5,221, Max +2,286.

### Baseline (before #380)

From the four 300-company runs in #378, each with miners, refiners and traders in equal numbers:

| Run | Change | Result |
|---|---|---|
| 1 | Tax on gains; traders buy from miners | Traders never left the Moon: their 48-hour wait kept resetting. |
| 2 | Traders count looks | Traders sold at Mars, but at about 37 after buying at 34. 4 Mars-side miners ran dry. |
| 3 | Traders sell in portions; miners fly to buy fuel | Traders made a margin of about 7, but 47 of 68 Mars-side miners ran dry. |
| 4 | Miners brew at 40; Mars Exchange budget 15,000 | Every company survived 38 days; every group but the traders gained. |
