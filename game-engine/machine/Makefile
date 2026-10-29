# The Machine view (#324 step 19): the engine's FORTH VM (../src/embed.c and
# its image, unchanged) with machine.c on top, built twice: natively, for the
# trace test, and as WebAssembly for the website (html/wasm/).
SRC=../src
VM=$(SRC)/embed.c $(SRC)/embed_image.c $(SRC)/vm_library.c $(SRC)/wrap.c machine.c
WASM_OUT=../../html/wasm/machine.wasm
EXPORTS=machine_init machine_eval machine_prepare machine_step machine_done machine_count \
        machine_pc machine_instruction machine_depth machine_stack machine_rdepth machine_rstack \
        machine_output machine_input machine_cell machine_set_live machine_query_pending machine_perform_pending machine_query_answer \
        machine_pop machine_read_byte machine_write_byte machine_wrap

all: trace_machine wasm

trace_machine: $(VM) trace_machine.c machine.h
	gcc -std=c99 -Wall -Werror -O2 -DNDEBUG -I$(SRC)/include -I. -include machine_shim.h -o trace_machine $(VM) trace_machine.c

wasm: $(WASM_OUT)

$(WASM_OUT): $(VM) wasm/libc.c machine.h Makefile
	@mkdir -p $(dir $(WASM_OUT))
	clang --target=wasm32 -std=c99 -O2 -DNDEBUG -nostdlib -ffreestanding -fno-builtin -Iwasm/include -I$(SRC)/include -I. \
	    -include machine_shim.h -Wl,--no-entry -Wl,--strip-all $(addprefix -Wl$(comma)--export=,$(EXPORTS)) \
	    -o $(WASM_OUT) $(VM) wasm/libc.c

# Runs the same words on both builds and compares their traces
test: trace_machine $(WASM_OUT)
	./trace_machine > native.trace
	node compare.mjs $(WASM_OUT) > wasm.trace
	diff native.trace wasm.trace && echo "machine: the WebAssembly build matches the engine's VM, step by step"
	node check_starters.mjs $(WASM_OUT) ../../examples/starters/*.fth ../../examples/companies/*.fth ../../documents/blog/*.md
	node words.mjs $(WASM_OUT) | diff - ../../resources/drone-words.txt && echo "machine: resources/drone-words.txt lists every word a drone knows"

clean:
	rm -f trace_machine native.trace wasm.trace

comma:=,
