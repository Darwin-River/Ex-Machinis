# Lua drones: a proof of concept (#380)

Round 34's newcomers asked for a language other than FORTH, and all four chose Lua first (economy playbook, "Language survey"). This folder tests whether Lua can do what a drone's language must do, and at what cost to the server. It is not wired into the engine.

```
make run    # builds lua54/ (Lua 5.4.8, unchanged, MIT licence), the host and two benchmarks, and runs them
./host maker.lua 100 10    # 100 Lua Makers for 10 game days in a mock world
./host heavy.lua 100 2     # drones that think hard: slices run out
./host runaway.lua 5 1     # an endless loop that eats memory: the cap stops it
./bench_lua; ./bench_forth # the same scoring work in Lua and on the engine's FORTH VM
make clones && ./clones_memory 10000 && ./clones_apart   # clones sharing their script
```

## What a drone needs, and how Lua does it

| Need | How (host.c) | Result |
|---|---|---|
| A sandbox | Each drone has its own `lua_State` with only the base library (less `load`, `loadfile`, `dofile`, `require`, `collectgarbage`), `string`, `table` and `math`, plus the game words. No `io`, `os` or `debug`. | A script reaches only the game. |
| A memory cap | The drone's own allocator counts its bytes and refuses past the cap (256 KB here). | `runaway.lua` stops with "not enough memory" at 262,085 bytes; the host and other drones carry on. |
| Metering | A count hook every 1,000 Lua instructions charges them; the slice's last few are read from the hook's counter, so the count is exact. A fuel per full slice of thinking. | Exact counts. The hook doubles the time of tight loops (6.4 ms against 3.1 ms unmetered): Lua 5.4 checks for hooks on every instruction once one is set. |
| Slices | The hook yields once a slice's 100,000 instructions are used up, as the engine's FORTH slices end. | `heavy.lua` (112,000 instructions a round) takes two slices a round: 4,800 slices used up, 4,800 on actions. |
| Pausing on an action | Game words that take time (`make`, `buy_here`, `sell_here`, `go`, `wait`) yield from C; the host carries the action out and resumes the script with its result (true or false). | A script reads top to bottom, as a FORTH one does: `if not make(alloys_a1) then return end`. |
| Surviving a restart | Lua can't save a paused coroutine. The host saves a drone's plain-data globals (numbers, strings, booleans and tables of them) as Lua source; a restored drone reloads its script, gets them back and runs `launched()` again. A save runs in a protected call, so a drone at its cap fails to save instead of taking the host down. | 187 bytes and 20 to 30 us a Maker; restore 0.1 to 0.9 ms. |

## Measurements

The same work in both languages: score 8 products from a price table and a bill of goods and pick the best, as the Systems shop does each round, 1,000 times (`score.fth`, `score.lua`).

| | FORTH (the engine's VM) | Lua 5.4 |
|---|---|---|
| Instructions a round | 2,149 | 374 (5.7 times fewer) |
| Time for 1,000 rounds | about 27 ms at the engine's 80 M instructions a second (82 ms stepped one at a time, as the Machine page runs it) | 6.4 ms metered, 3.1 ms unmetered |
| Instructions a second | about 80 M | 58 to 86 M metered |
| A drone's state | a 64 KB image (`agents.vm`), written to the database after every slice | about 20 KB in the engine's memory; a 187-byte snapshot (a Maker) to save |
| Loading a script | about 80,000 instructions a line (a 100-line script took 80 slices before #403) | 0.1 ms a drone, all of it, in C |

100 Lua Makers for 10 game days: 8,700 slices, 0.6 to 0.9 us each (a Maker thinks for about 11 Lua instructions between actions), 20 KB a drone. 1,000 Makers: 1.6 us a slice, the same 20 KB. 100 drones thinking hard: 654 us a full slice.

## Clones sharing one script

A company's clones run the same script, so they can share one `lua_State`: the script is compiled once, and each clone is a coroutine with its own globals. Every function of a script reaches globals through one shared `_ENV` upvalue, so the host points it at the clone's own table (which falls back to the shared functions and game words) before each resume (`clones_memory.c`, `clones_apart.c`).

| | Memory |
|---|---|
| A `lua_State` each (host.c) | 18,900 bytes a Maker |
| One state a script, a coroutine a clone | 20,070 bytes once, then about 1,500 bytes a Maker |

Interleaved clones keep their own variables (`clones_apart`: one clone counted 5 rounds, the other 3, the shared table 0). What they share: the compiled code, the game words, the memory cap and the garbage collector (so the cap becomes the company's, and grows with its drones), and any `local` at the top of the script, which is one variable for all of them (fine for settings, wrong for a counter: the docs would say so, or the loader could refuse top-level locals that are assigned). Only a company's own drones share a state: two companies never do, even with the same script.

## How many drones fit

- **Memory is not the limit.** A Maker uses 1,500 bytes as a clone and 19 KB on its own: 10,000 drones are 15 to 190 MB. The worst case is set by the cap (10,000 drones at 64 KB each is 640 MB), so the cap should be modest, a drone's or a company's.
- **Thinking is cheap.** The Maker thinks about 11 instructions between actions. A drone that thinks a full 100,000-instruction slice takes 0.65 ms, about 1,500 such slices a second a core; at 600 game seconds a real second (a game hour every 6 real seconds), a slice a game hour each is about 9,000 drones a core.
- **The database is the limit.** Today each FORTH slice reads and writes a 64 KB image and runs the queries its words ask; in round 34's race MySQL, not the VM, held the engine to under 100 slices a minute until #404. Lua drones held in memory skip the image, so what is left is the game itself: actions, queries and events. That is where to measure next, with the real game words.
- **A restart** rebuilds every drone from its script and snapshot: 0.1 to 0.9 ms each, about a second or ten for 10,000.

## What it means for the game

- **The server does less.** For the same decisions, Lua runs a sixth of the instructions in about a quarter of the time, holds a third of the memory, and never writes a 64 KB image to the database between slices. Loading is free.
- **Fuel for thinking needs a new rate.** A Lua instruction does about 5.7 FORTH instructions' work, so a fuel per 100,000 Lua instructions would make thinking about 6 times dearer than now; a fuel per about 570,000 keeps today's prices. Instructions of one kind still vary (a table lookup against a string built in C), so a cap on memory, and a charge for it, belongs with the rate.
- **Where a paused drone lives changes the engine.** FORTH drones live in the database between slices, so any engine can run any drone and a crash loses nothing but the slice. Lua drones live in the engine's memory between slices (a saved coroutine needs a library such as Eris, which is not available here and has no maintained Lua 5.4 release). So: one engine process owns a world's drones (the engine lock of #380 round 31 already means one game engine a world); the plain-data snapshot is saved after each action, or every few, so a restart or deploy restores every drone's variables and runs `launched()` again. That is what a crash does to a FORTH drone today (it goes back to its `release_vm` and starts `launched` again), but a FORTH drone keeps its variables across a deploy, and a Lua drone keeps only what the snapshot holds: its plain data, not where it was in its script. Scripts written as "a loop of rounds, with state in variables" (as every starter and library design is) lose nothing.
- **Clones** run the parent's script from the start with fresh variables, as a FORTH clone runs `launched` from its parent's `release_vm`. Nothing changes.
- **Money is a plain number.** Lua's 64-bit integers end the 16-bit overflow and double-cell credits that the newcomers tripped on (`systems.fth`'s 5,000 fallback).
- **Queries return tables.** The game words here return numbers; queries would return a list of rows with named fields, like the API's JSON, instead of the 120-byte `answer` buffer.

## Not done here

- The real game words (about 150: orders, broadcasts, queries, launch), and the engine itself: the engine would run a Lua drone where it runs a FORTH one (`engine_process_vm_command`), with `perform` and `query` as the C side of the game words.
- The Machine page and the test world for Lua (Lua builds for WebAssembly as the FORTH VM does).
- Porting the starters, the library designs, `forth-words.md` and the blog.
- A world with both languages: a drone would carry its language with its script, so FORTH companies could run on beside Lua ones while players move over.
