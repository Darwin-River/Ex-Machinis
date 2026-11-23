# Balance tools

The tools behind [DV-03, Tuning gameplay parameters](../../Procedures/SOPs/DV-03%20Tuning%20gameplay%20parameters.md) (#961). They measure the game against its playability objectives, so each tuning change can be checked before it ships and measured after.

| File | What it is |
|---|---|
| `objectives.json` | The playability objectives (`PO-nn`): metric, measure (static, live or playtest), target band, guard or target. The only place a band is written. |
| `static_check.py` | Works out the static objectives from the game data and writes [`documents/balance-report.md`](../../documents/balance-report.md). `--check` (run in CI) fails when a guard is broken or the report is stale. `--json` prints the figures. |
| `measure_live.py` | Works out the live objectives from a world's public API: `/clock`, `/dashboard`, `/places`, `/startups/race`. It needs no key or login. `--base URL` for another world, `--save DIR` keeps what it read, and `--from DIR` reads saved files offline. |

## Running them

```sh
python3 tools/balance/static_check.py            # after any change to game-engine/sql/game-data/
python3 tools/balance/static_check.py --check    # what CI runs
python3 tools/balance/measure_live.py            # the live game, as a Markdown table
```

## The static model

`static_check.py` imports `game-engine/sql/game-data/build_game_data.py`. Run that way, the build works the numbers out without writing anything, so the check sees exactly the prices, recipes, times and fuel costs the game will load. The model:

- **Prices** are the House's listed prices. A purchase pays the cheapest exchange's ask, and a sale gets the best bid, less the 5% sales tax.
- **Mining** at a place yields its abundance a run. One run takes 1 game hour and 1 fuel. Refining takes its recipe's time and fuel.
- **One step** means buying a recipe's inputs from the House, making the good and selling it to the House. **Whole chain** means making it from refined goods bought from the House. Far goods the House doesn't sell are priced at their value with its markup.
- **Credits a tool-hour** is (sale − inputs − fuel) ÷ the tool hours the work takes.
- **Left out:** booms and slumps, the import price and step-back, daily demand caps, crowding, and how long a drone waits to buy or sell. So the figures show the shape of the economy, not what a drone will earn. Live measures and playtests decide.

## Adding an objective

1. Add it to `objectives.json` as a `target`, with the next free ID and a `key`.
2. Work out the figure under that key: in `figures()` in `static_check.py` for a static objective, or in `measure_live.py` for a live one.
3. Run `static_check.py` and commit the report, through a tuning issue per DV-03.

A guard must hold on today's data. Promote a target to a guard only once it is met and has held through a playtest round.
