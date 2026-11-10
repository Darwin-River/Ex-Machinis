# Game engine sources

The C sources of the `game-engine` and `events-engine` (see [the engine's README](../README.md)).

```sh
make all     # builds bin/game-engine, bin/events-engine, bin/lua-check and eforth.blk
make test    # unit tests, and checks the Lua library, examples, manual and blog code all compile
```

- `lua_drone.c`, `lua_library.c`, `lua_library.lua`: how a drone's Lua program runs, and the game functions it can call (listed in [lua-functions.md](../../documents/lua-functions.md)).
- `engine.c`, `main.c`: the game engine's loop; `engine_e.c`, `main_e.c`, `event*.c`: the events engine.
- `protocol.c`, `shares.c`, `station.c`, `crash.c`, `orbits.c`, `game_time.c`: the game's rules.
- `embed/`, `embed*.c`: the FORTH VM for drones released before FORTH was retired ([its own MIT licence](embed/LICENSE)).
- `TODO.md`: open engine work.
