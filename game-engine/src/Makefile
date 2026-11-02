#---------------------------------------------------------------------------
# GAME-ENGINE makefile 
#---------------------------------------------------------------------------

#---------------------------------------------------------------------------
# Compiler/linker flags
#---------------------------------------------------------------------------
CXX=gcc
CFLAGS=-std=c99 -Werror -Wall -O2 -I./include -I/usr/include/mysql -D_DEFAULT_SOURCE=1 -DUSE_CUSTOM_EMBED
# Lua 5.4 (#380: Lua drones), unchanged, in ../lua54
LUA_DIR=../lua54
LUA_SRCS=$(filter-out $(LUA_DIR)/lua.c $(LUA_DIR)/luac.c,$(wildcard $(LUA_DIR)/*.c))
LUA_OBJS=$(patsubst $(LUA_DIR)/%.c,$(OBJS_PATH)/lua/%.o,$(LUA_SRCS))
LDFLAGS=-lmysqlclient -lconfig -lm

#---------------------------------------------------------------------------
# Program sources and name
#---------------------------------------------------------------------------
GAME_ENGINE_SRCS=lua_drone.c lua_library.c config.c db.c db_common.c email.c embed.c embed_image.c embed_util.c engine.c main.c orbits.c protocol.c trace.c vm.c vm_extension.c vm_meter.c wrap.c game_time.c crash.c db_launch.c vm_library.c clock_floor.c event_order.c
EVENTS_ENGINE_SRCS=main_e.c engine_e.c config_e.c db_e.c db_common.c trace.c event.c orbits.c game_time.c station.c db_station.c wreck.c db_wreck.c
OBJS_PATH=objs
GAME_ENGINE_OBJS=$(patsubst %.c,$(OBJS_PATH)/%.o,$(GAME_ENGINE_SRCS))
EVENTS_ENGINE_OBJS=$(patsubst %.c,$(OBJS_PATH)/%.o,$(EVENTS_ENGINE_SRCS))

GAME_ENGINE_EXE_NAME=game-engine
EVENTS_ENGINE_EXE_NAME=events-engine
EXE_PATH=bin

game-engine : CFLAGS += -DUSE_VM

#---------------------------------------------------------------------------
# Rules
#---------------------------------------------------------------------------
all: $(GAME_ENGINE_EXE_NAME) $(EVENTS_ENGINE_EXE_NAME) $(EXE_PATH)/eforth.blk $(EXE_PATH)/lua-check

$(OBJS_PATH)/%.o: %.c
	@mkdir -p $(OBJS_PATH)
	$(CXX) $(CFLAGS) -I$(LUA_DIR) -c $< -o $@

# Lua itself: its own warnings, not ours
$(OBJS_PATH)/lua/%.o: $(LUA_DIR)/%.c
	@mkdir -p $(OBJS_PATH)/lua
	$(CXX) -std=c99 -O2 -DLUA_USE_POSIX -c $< -o $@

# The Lua drones' standard library, from lua_library.lua
lua_library.c: lua_library.lua
	{ echo '/* Made from lua_library.lua by the Makefile: edit that */'; echo '#include "lua_drone.h"'; \
	  echo 'const char* lua_library_source ='; \
	  sed 's/\\/\\\\/g; s/"/\\"/g; s/^/"/; s/$$/\\n"/' $<; echo ';'; } > $@

$(GAME_ENGINE_EXE_NAME): $(GAME_ENGINE_OBJS) $(LUA_OBJS)
	@echo $(GAME_ENGINE_SRCS)
	$(CXX) -o $(GAME_ENGINE_EXE_NAME) $(GAME_ENGINE_OBJS) $(LUA_OBJS) $(LDFLAGS)
	@mkdir -p $(EXE_PATH)
	mv $(GAME_ENGINE_EXE_NAME) $(EXE_PATH)

$(EVENTS_ENGINE_EXE_NAME): $(EVENTS_ENGINE_OBJS)
	@echo $(GAME_ENGINE_SRCS)
	$(CXX) -o $(EVENTS_ENGINE_EXE_NAME) $(EVENTS_ENGINE_OBJS) $(LDFLAGS)
	@mkdir -p $(EXE_PATH)
	mv $(EVENTS_ENGINE_EXE_NAME) $(EXE_PATH)

# Compiles a Lua drone's script without running it (#380: the website's check)
$(EXE_PATH)/lua-check: lua_check.c $(LUA_OBJS)
	@mkdir -p $(EXE_PATH)
	$(CXX) -std=c99 -Wall -Werror -O2 -I$(LUA_DIR) -o $@ lua_check.c $(LUA_OBJS) -lm

# The FORTH image each drone's VM starts from (FORTH_IMAGE_PATH)
$(EXE_PATH)/eforth.blk: dump_image.c embed_image.c
	@mkdir -p $(OBJS_PATH) $(EXE_PATH)
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/dump_image dump_image.c embed_image.c
	$(OBJS_PATH)/dump_image > $@

# Orbit maths checked against real positions, long-line wrapping checked on
# the FORTH VM, VM memory bounds, the game clock, instruction metering and crash handling (need no database)
test: $(EXE_PATH)/lua-check test_orbits.c orbits.c test_wrap.c wrap.c test_vm_bounds.c embed.c test_game_time.c game_time.c test_vm_meter.c vm_meter.c test_crash.c crash.c test_double.c vm_library.c test_station.c station.c test_wreck.c wreck.c test_clock_floor.c clock_floor.c test_event_order.c event_order.c
	@mkdir -p $(OBJS_PATH)
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_orbits test_orbits.c -lm
	$(OBJS_PATH)/test_orbits
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_wrap test_wrap.c wrap.c embed.c embed_image.c embed_util.c
	$(OBJS_PATH)/test_wrap
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_vm_bounds test_vm_bounds.c embed.c embed_image.c embed_util.c
	$(OBJS_PATH)/test_vm_bounds
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_game_time test_game_time.c game_time.c -Wl,--wrap=time -lm
	$(OBJS_PATH)/test_game_time
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_vm_meter test_vm_meter.c vm_meter.c embed.c embed_image.c embed_util.c
	$(OBJS_PATH)/test_vm_meter
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_crash test_crash.c crash.c vm_meter.c embed.c embed_image.c embed_util.c
	$(OBJS_PATH)/test_crash
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_double test_double.c vm_library.c embed.c embed_image.c embed_util.c
	$(OBJS_PATH)/test_double
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_station test_station.c station.c -lm
	$(OBJS_PATH)/test_station
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_wreck test_wreck.c wreck.c
	$(OBJS_PATH)/test_wreck
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_clock_floor test_clock_floor.c clock_floor.c -lm
	$(OBJS_PATH)/test_clock_floor
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_event_order test_event_order.c event_order.c
	$(OBJS_PATH)/test_event_order
	# The Lua drones' library and the Lua starters compile (#380)
	for f in lua_library.lua ../../examples/starters/*.lua; do $(EXE_PATH)/lua-check < $$f || { echo "$$f doesn't compile"; exit 1; }; done
	@echo "lua: the library and the Lua starters compile"

clean:
	$(RM) -rf $(OBJS_PATH) $(GAME_ENGINE_EXE_NAME) $(EVENTS_ENGINE_EXE_NAME) $(EXE_PATH)/eforth.blk
