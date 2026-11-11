# Lua drones: a proof of concept (#380). Not part of the engine build.
# lua54/ is Lua 5.4.8, unchanged (MIT licence: see lua54/lua.h).
LUA=../lua54
LUA_SRC=$(filter-out $(LUA)/lua.c $(LUA)/luac.c,$(wildcard $(LUA)/*.c))
CFLAGS=-std=c99 -O2 -Wall -DLUA_USE_POSIX -I$(LUA)

all: host bench_lua bench_forth

host: host.c $(LUA_SRC)
	gcc $(CFLAGS) -o $@ host.c $(LUA_SRC) -lm

bench_lua: bench_lua.c $(LUA_SRC)
	gcc $(CFLAGS) -o $@ bench_lua.c $(LUA_SRC) -lm

# The engine's own VM, as the Machine page builds it
SRC=../src
VM=$(SRC)/embed.c $(SRC)/embed_image.c $(SRC)/vm_library.c $(SRC)/wrap.c ../machine/machine.c
bench_forth: bench_forth.c $(VM)
	gcc -std=c99 -O2 -DNDEBUG -I$(SRC)/include -I../machine -include ../machine/machine_shim.h -o $@ $(VM) bench_forth.c

clones: clones_memory clones_apart

clones_memory: clones_memory.c $(LUA_SRC)
	gcc $(CFLAGS) -o $@ clones_memory.c $(LUA_SRC) -lm

clones_apart: clones_apart.c $(LUA_SRC)
	gcc $(CFLAGS) -o $@ clones_apart.c $(LUA_SRC) -lm

run: all
	./host maker.lua 100 10
	./bench_lua
	./bench_forth

clean:
	rm -f host bench_lua bench_forth clones_memory clones_apart
