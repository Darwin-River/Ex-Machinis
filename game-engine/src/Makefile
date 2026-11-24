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
# A check build of ENGINE_OWNS_EVENTS (#694 stage 4 step 6, event_ids.h): make
# CHECK_EVENT_IDS=1 all (after make clean) checks every reservation and insert
ifeq ($(CHECK_EVENT_IDS),1)
CFLAGS += -DEVENT_IDS_CHECK
endif

#---------------------------------------------------------------------------
# Program sources and name
#---------------------------------------------------------------------------
EVENTS_ENGINE_SRCS=static_cache.c shares.c db_shares.c compute.c db_compute.c main_e.c engine_e.c config_e.c db_e.c db_common.c trace.c event.c orbits.c game_time.c station.c db_station.c wreck.c db_wreck.c startup.c db_startup.c ee_bindings.c settle_clock.c event_heap.c wake_heap.c event_ids.c
# The game engine carries the events engine too (#694 stage 4 step 2: with
# EVENTS_IN_ENGINE=1 it settles between turns, settle.c): every events-engine
# source but its main and its bindings to the shared modules
GAME_ENGINE_SRCS=$(sort static_cache.c query_cache.c lua_drone.c lua_library.c config.c db.c db_common.c email.c embed.c embed_image.c embed_util.c engine.c main.c orbits.c protocol.c trace.c vm.c vm_extension.c vm_meter.c wrap.c game_time.c crash.c db_launch.c vm_library.c clock_floor.c event_order.c settle.c settle_clock.c db_wake.c db_state.c holding_state.c $(filter-out main_e.c ee_bindings.c,$(EVENTS_ENGINE_SRCS)))
OBJS_PATH=objs
GAME_ENGINE_OBJS=$(patsubst %.c,$(OBJS_PATH)/%.o,$(GAME_ENGINE_SRCS))
EVENTS_ENGINE_OBJS=$(patsubst %.c,$(OBJS_PATH)/%.o,$(EVENTS_ENGINE_SRCS))
# Every object of both engines in one program (#694 stage 4): all but the
# events engine's main (recompiled as ee_main) and its bindings to the shared
# modules (in one program those are the game engine's)
LINK_CHECK_OBJS=$(sort $(filter-out $(OBJS_PATH)/main_e.o $(OBJS_PATH)/ee_bindings.o,$(GAME_ENGINE_OBJS) $(EVENTS_ENGINE_OBJS)))

GAME_ENGINE_EXE_NAME=game-engine
EVENTS_ENGINE_EXE_NAME=events-engine
EXE_PATH=bin

game-engine : CFLAGS += -DUSE_VM

#---------------------------------------------------------------------------
# Rules
#---------------------------------------------------------------------------
all: $(GAME_ENGINE_EXE_NAME) $(EVENTS_ENGINE_EXE_NAME) $(EXE_PATH)/eforth.blk $(EXE_PATH)/lua-check $(EXE_PATH)/lua-trade $(EXE_PATH)/lua-drive

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

# Runs a player's trading script once, in a sandbox (#768: the website's keeper)
$(EXE_PATH)/lua-trade: lua_trade.c $(LUA_OBJS)
	@mkdir -p $(EXE_PATH)
	$(CXX) -std=c99 -Wall -Werror -O2 -I$(LUA_DIR) -o $@ lua_trade.c $(LUA_OBJS) -lm

# A test drive of a drone's script against a snapshot of the world (#1007: the website's Test drive)
$(EXE_PATH)/lua-drive: lua_drive.c $(LUA_OBJS)
	@mkdir -p $(EXE_PATH)
	$(CXX) -std=c99 -Wall -Werror -O2 -I$(LUA_DIR) -o $@ lua_drive.c $(LUA_OBJS) -lm

# Both engines link into one program with no clashing names (#694 stage 4:
# the events engine's copies carry an ee_ prefix)
link-check: $(LINK_CHECK_OBJS) $(LUA_OBJS) main_e.c
	@mkdir -p $(OBJS_PATH)
	$(CXX) $(CFLAGS) -Dmain=ee_main -c main_e.c -o $(OBJS_PATH)/link_check_main_e.o
	$(CXX) -o $(OBJS_PATH)/link-check $(LINK_CHECK_OBJS) $(OBJS_PATH)/link_check_main_e.o $(LUA_OBJS) $(LDFLAGS)
	@echo "link-check: both engines link into one program"

# The FORTH image each drone's VM starts from (FORTH_IMAGE_PATH)
$(EXE_PATH)/eforth.blk: dump_image.c embed_image.c
	@mkdir -p $(OBJS_PATH) $(EXE_PATH)
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/dump_image dump_image.c embed_image.c
	$(OBJS_PATH)/dump_image > $@

# Orbit maths checked against real positions, long-line wrapping checked on
# the FORTH VM, VM memory bounds, the game clock, instruction metering and crash handling (need no database)
test: link-check $(EXE_PATH)/lua-check $(EXE_PATH)/lua-drive test_lua_drive.sh check_doc_lua.sh test_orbits.c orbits.c test_wrap.c wrap.c test_vm_bounds.c embed.c test_game_time.c game_time.c test_vm_meter.c vm_meter.c test_crash.c crash.c test_double.c vm_library.c test_station.c station.c test_wreck.c wreck.c test_startup.c startup.c test_clock_floor.c clock_floor.c test_event_order.c event_order.c test_static_cache.c static_cache.c test_query_cache.c query_cache.c test_shares.c shares.c test_compute.c compute.c test_settle_clock.c settle_clock.c test_event_heap.c event_heap.c test_wake_heap.c wake_heap.c test_holding_state.c holding_state.c test_event_ids.c event_ids.c test_lua_equip.c lua_library.lua $(LUA_OBJS)
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
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_startup test_startup.c startup.c
	$(OBJS_PATH)/test_startup
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_clock_floor test_clock_floor.c clock_floor.c -lm
	$(OBJS_PATH)/test_clock_floor
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_event_order test_event_order.c event_order.c
	$(OBJS_PATH)/test_event_order
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_static_cache test_static_cache.c static_cache.c
	$(OBJS_PATH)/test_static_cache
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_query_cache test_query_cache.c query_cache.c
	$(OBJS_PATH)/test_query_cache
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_shares test_shares.c shares.c
	$(OBJS_PATH)/test_shares
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_compute test_compute.c compute.c -lm
	$(OBJS_PATH)/test_compute
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_settle_clock test_settle_clock.c settle_clock.c
	$(OBJS_PATH)/test_settle_clock
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_event_heap test_event_heap.c event_heap.c
	$(OBJS_PATH)/test_event_heap
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_wake_heap test_wake_heap.c wake_heap.c event_heap.c
	$(OBJS_PATH)/test_wake_heap
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_holding_state test_holding_state.c holding_state.c
	$(OBJS_PATH)/test_holding_state
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/test_event_ids test_event_ids.c event_ids.c
	$(OBJS_PATH)/test_event_ids
	# equip() in the Lua library against a pretend drone (#726)
	$(CXX) -std=c99 -Wall -Werror -O2 -I$(LUA_DIR) -o $(OBJS_PATH)/test_lua_equip test_lua_equip.c $(LUA_OBJS) -lm
	$(OBJS_PATH)/test_lua_equip
	# A drone script's test drive against a hand-written snapshot (#1007)
	./test_lua_drive.sh $(EXE_PATH)/lua-drive lua_library.lua
	# The Lua drones' library and the Lua starters compile (#380)
	for f in lua_library.lua ../../resources/house/station.lua ../../examples/starters/*.lua ../../examples/companies/*.lua ../../examples/traders/*.lua; do $(EXE_PATH)/lua-check < $$f || { echo "$$f doesn't compile"; exit 1; }; done
	@echo "lua: the library, the Lua starters and designs compile"
	./check_doc_lua.sh ../../documents/players-manual.md ../../documents/lua-functions.md ../../documents/blog/*.md

.PHONY: all test clean link-check

clean:
	$(RM) -rf $(OBJS_PATH) $(GAME_ENGINE_EXE_NAME) $(EVENTS_ENGINE_EXE_NAME) $(EXE_PATH)/eforth.blk
