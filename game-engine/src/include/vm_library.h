/*
 * The standard word library every drone's VM gets (#324 step 9): the
 * standard Forth double-cell words, so drones can count money and volumes
 * past 32,767 (signed) and 65,535 (unsigned). These are ordinary Forth
 * definitions; the language itself doesn't change.
 */
#ifndef __VM_LIBRARY_H__
#define __VM_LIBRARY_H__

#include "embed.h"

/** The library's source, one definition per line */
extern const char* vm_library_source;

/** A word the library defines, to tell whether a VM has it already */
#define VM_LIBRARY_MARKER "d."

/** Defines the library's words in the VM (the current search order must
    include the system words: um* um+). Returns embed_eval's result. */
int vm_library_load(embed_t* vm);

/** The game words (#324 step 15): constants for places and resources, a word
    for each action and the common queries. Needs the perform and query
    callbacks. */
extern const char* vm_library_game_source;
#define VM_LIBRARY_GAME_MARKER "stdlib"
int vm_library_load_game(embed_t* vm);

#endif
