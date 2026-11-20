# Game engine sources

The C sources of the `game-engine` and `events-engine` (see [the engine's README](../README.md)).

```sh
make all     # builds bin/game-engine, bin/events-engine, bin/lua-check and eforth.blk
make test    # unit tests, link-check, and checks the Lua library, examples, manual and blog code all compile
make link-check  # links every object of both engines into one program, to prove no names clash
```

- `lua_drone.c`, `lua_library.c`, `lua_library.lua`: how a drone's Lua program runs, and the game functions it can call (listed in [lua-functions.md](../../documents/lua-functions.md)).
- `engine.c`, `main.c`: the game engine's loop; `engine_e.c`, `main_e.c`, `event*.c`: the events engine. Where its names would clash with the game engine's they carry an `ee_` prefix (`ee_init`, `ee_pass`, `ee_run`, `db_e.h`), so both can link into one program (#694 stage 4); `ee_bindings.c` gives the shared modules its trace and database connection in `bin/events-engine`.
- `settle.c`, `settle_clock.c`, `ee_embed.h`: with `EVENTS_IN_ENGINE=1` (default 0), shard 0's game engine settles events between turns by running `ee_pass` on a second database connection of its own, under the events engine's lock, and `bin/events-engine` rests (#694 stage 4 step 2). While a pass runs, `engine_get_db_connection()` returns the settlement connection and log lines start `[events]`. `settle_clock.c` says when a pass is due: about every 50 ms, at once after a full batch, and never so often that turns starve (`test_settle_clock.c`).
- `protocol.c`, `shares.c`, `station.c`, `crash.c`, `orbits.c`, `game_time.c`: the game's rules.
- `embed/`, `embed*.c`: the FORTH VM for drones released before FORTH was retired ([its own MIT licence](embed/LICENSE)).
- `TODO.md`: open engine work.
