#---------------------------------------------------------------------------
# GAME-ENGINE makefile 
#---------------------------------------------------------------------------

#---------------------------------------------------------------------------
# Compiler/linker flags
#---------------------------------------------------------------------------
CXX=gcc
CFLAGS=-std=c99 -Werror -Wall -O2 -I./include -I/usr/include/mysql -D_DEFAULT_SOURCE=1 -DUSE_CUSTOM_EMBED
LDFLAGS=-lmysqlclient -lconfig -lm

#---------------------------------------------------------------------------
# Program sources and name
#---------------------------------------------------------------------------
GAME_ENGINE_SRCS=config.c db.c db_common.c email.c embed.c embed_image.c embed_util.c engine.c main.c orbits.c protocol.c trace.c vm.c vm_extension.c vm_meter.c wrap.c game_time.c crash.c db_launch.c vm_library.c
EVENTS_ENGINE_SRCS=main_e.c engine_e.c config_e.c db_e.c db_common.c trace.c event.c orbits.c game_time.c station.c db_station.c
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
all: $(GAME_ENGINE_EXE_NAME) $(EVENTS_ENGINE_EXE_NAME) $(EXE_PATH)/eforth.blk

$(OBJS_PATH)/%.o: %.c
	@mkdir -p $(OBJS_PATH)
	$(CXX) $(CFLAGS) -c $< -o $@

$(GAME_ENGINE_EXE_NAME): $(GAME_ENGINE_OBJS)
	@echo $(GAME_ENGINE_SRCS)
	$(CXX) -o $(GAME_ENGINE_EXE_NAME) $(GAME_ENGINE_OBJS) $(LDFLAGS)
	@mkdir -p $(EXE_PATH)
	mv $(GAME_ENGINE_EXE_NAME) $(EXE_PATH)

$(EVENTS_ENGINE_EXE_NAME): $(EVENTS_ENGINE_OBJS)
	@echo $(GAME_ENGINE_SRCS)
	$(CXX) -o $(EVENTS_ENGINE_EXE_NAME) $(EVENTS_ENGINE_OBJS) $(LDFLAGS)
	@mkdir -p $(EXE_PATH)
	mv $(EVENTS_ENGINE_EXE_NAME) $(EXE_PATH)

# The FORTH image each drone's VM starts from (FORTH_IMAGE_PATH)
$(EXE_PATH)/eforth.blk: dump_image.c embed_image.c
	@mkdir -p $(OBJS_PATH) $(EXE_PATH)
	$(CXX) $(CFLAGS) -o $(OBJS_PATH)/dump_image dump_image.c embed_image.c
	$(OBJS_PATH)/dump_image > $@

# Orbit maths checked against real positions, long-line wrapping checked on
# the FORTH VM, VM memory bounds, the game clock, instruction metering and crash handling (need no database)
test: test_orbits.c orbits.c test_wrap.c wrap.c test_vm_bounds.c embed.c test_game_time.c game_time.c test_vm_meter.c vm_meter.c test_crash.c crash.c test_double.c vm_library.c test_station.c station.c
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

clean:
	$(RM) -rf $(OBJS_PATH) $(GAME_ENGINE_EXE_NAME) $(EVENTS_ENGINE_EXE_NAME) $(EXE_PATH)/eforth.blk
