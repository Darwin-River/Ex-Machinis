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
- `event_heap.c`: with `EVENT_HEAP=1` as well (default 0; it needs `EVENTS_IN_ENGINE=1`), settlement takes due events from a min-heap in memory keyed as the batch query's `ORDER BY` (game_t, action, id), not from that query (#694 stage 4 step 3, #432). `db_e.c` loads it at the first pass, reads a batch's rows by id, drops events no longer pending and puts back those still pending after the batch; `db_insert_event` and `db_insert_event_e` stage the events they insert and the heap takes them when the connection commits; each pass reads the ids past the highest seen and the ids it skipped (not committed yet) for 10 s, a sweep each second the ids of the last 10-20 s, and a full check every five minutes, so what other shards insert gets in too (`test_event_heap.c`).
- `protocol.c`, `shares.c`, `station.c`, `crash.c`, `orbits.c`, `game_time.c`: the game's rules.
- `embed/`, `embed*.c`: the FORTH VM for drones released before FORTH was retired ([its own MIT licence](embed/LICENSE)).
- `TODO.md`: open engine work.
