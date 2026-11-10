# Game engine

The server side of the game, written in C. Two programs run all the time:

- **`game-engine`** runs every drone's program: Lua for every release, and the FORTH VM for the few drones released before FORTH was retired. Each drone gets a slice of instructions per turn, paid for in fuel.
- **`events-engine`** settles what the drones do (travel, mining, manufacturing, trades, shares, launches), turning their actions into the events their pages and the API show.

| Folder | What it holds |
|---|---|
| `src/` | The engines' sources, their unit tests (`make test`) and the Lua library every drone loads |
| `lua54/` | Lua 5.4.8, unchanged (MIT licence) |
| `sql/` | The database schema, numbered migrations (`migrate.sh`) and the game data built from the design spreadsheets |
| `db-backup/` | The base schema the migrations start from |
| `conf/`, `scripts/` | Engine settings and start scripts; secrets go in environment variables, not the `.conf` files |
| `lua-poc/` | The proof of concept that chose Lua (#380) |
| `doc/`, `test/`, `tools/`, `machine/` | Older notes and tools, kept for reference |

Building, configuring and testing the engines is covered in the [main README](../README.md#build-and-configure-the-game-engines); the rules they implement are in the [design manual](../documents/design-manual.md). The engines are part of Ex Machinis and MIT licensed like the rest of it.
