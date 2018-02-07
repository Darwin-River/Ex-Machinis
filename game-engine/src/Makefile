#---------------------------------------------------------------------------
# GAME-ENGINE makefile 
#---------------------------------------------------------------------------

#---------------------------------------------------------------------------
# Compiler/linker flags
#---------------------------------------------------------------------------
CXX=gcc
CFLAGS=-std=c99 -Werror -Wall -O2 -I./include -I/usr/include/mysql
LDFLAGS=-lmysqlclient -lconfig

#---------------------------------------------------------------------------
# Program sources and name
#---------------------------------------------------------------------------
SRCS=main.c libforth.c db.c trace.c engine.c config.c
OBJS_PATH=objs
OBJS=$(patsubst %.c,$(OBJS_PATH)/%.o,$(SRCS))

EXE_NAME=game-engine
EXE_PATH=bin

#---------------------------------------------------------------------------
# Rules
#---------------------------------------------------------------------------
all: $(EXE_NAME)

$(OBJS_PATH)/%.o: %.c
	@mkdir -p $(OBJS_PATH)
	$(CXX) $(CFLAGS) -c $< -o $@

$(EXE_NAME): $(OBJS)
	$(CXX) -o $(EXE_NAME) $(OBJS) $(LDFLAGS)
	@mkdir -p $(EXE_PATH)
	mv $(EXE_NAME) $(EXE_PATH)

clean:
	$(RM) -rf $(OBJS_PATH) $(EXE_PATH)
