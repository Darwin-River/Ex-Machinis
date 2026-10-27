/*
 * The Machine view (#324 step 19): the engine's FORTH VM, set up as a
 * drone's is, run one instruction at a time so a player can watch a word
 * work. The same code is built for the engine's tests and, as WebAssembly,
 * for the website (see Makefile).
 */
#ifndef MACHINE_H
#define MACHINE_H

#include <stdint.h>

/* Sets the VM up as the engine sets up a drone's: the same image, preamble,
   callback words and word library. Returns 0, or a FORTH error code. */
int machine_init(void);

/* Runs FORTH text (definitions, numbers to leave on the stack) to the end */
int machine_eval(const char *text);

/* Gets ready to run one word from the current stack, inside catch: when it
   is done, the top of the stack is 0, or the error it threw. Returns 0, or
   -13 when the word isn't defined. */
int machine_prepare(const char *word);

/* Runs up to n instructions of the prepared word; returns how many ran.
   machine_done() turns true when the word has returned. */
int machine_step(int n);
int machine_done(void);

/* What a player sees */
long machine_count(void);          /* instructions run since machine_prepare */
int machine_pc(void);              /* cell address of the next instruction */
int machine_instruction(void);     /* the next instruction */
int machine_cell(int addr);        /* any cell of the image, for listing a word's code */
int machine_depth(void);           /* data stack depth (top included) */
int machine_stack(int i);          /* data stack, 0 = top */
int machine_rdepth(void);          /* return stack depth */
int machine_rstack(int i);         /* return stack, 0 = top */
const char *machine_output(void);  /* what the word printed */

/* Live data (#380). With live on, a query while stepping stops the word:
   machine_query_pending() is then the query's id (-1 for id 0), taken off
   the stack, and the rest of its arguments are still there. The page takes
   them (machine_pop), runs the query on the game's data, writes the rows
   (machine_write_byte) and leaves the result (machine_query_answer), as the
   engine does; then stepping goes on. Off (the default), query leaves -3. */
void machine_set_live(int on);
int machine_query_pending(void);
/* Likewise perform (live on, while stepping): the protocol id, taken off
   the stack; the page takes its arguments and answers -11 */
int machine_perform_pending(void);
void machine_query_answer(int code);
int machine_pop(void);
int machine_read_byte(int addr);
void machine_write_byte(int addr, int value);
char *machine_input(void);         /* a buffer for the text to pass in */

#endif
