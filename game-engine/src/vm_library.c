/*
 * The standard word library (#324 step 9). See vm_library.h.
 *
 * Doubles are two cells, the high cell on top: ( d -- ) is ( low high -- ).
 * um* and um+ come with the VM, among its system words that players don't
 * see: the first two lines make them visible. The rest are the standard
 * definitions.
 */
#include <string.h>

#include "vm_library.h"
#include "embed_util.h"

const char* vm_library_source =
    ": um* ( u1 u2 -- ud ) um* ;\n"
    ": um+ ( u1 u2 -- ud ) um+ ;\n"
    ": s>d ( n -- d ) dup 0< ;\n"
    ": dnegate ( d -- -d ) invert >r invert 1 um+ r> + ;\n"
    ": d+ ( d1 d2 -- d ) >r swap >r um+ r> r> + + ;\n"
    ": d- ( d1 d2 -- d ) dnegate d+ ;\n"
    ": dabs ( d -- ud ) dup 0< if dnegate then ;\n"
    ": d0= ( d -- f ) or 0= ;\n"
    ": d0< ( d -- f ) nip 0< ;\n"
    ": d= ( d1 d2 -- f ) d- d0= ;\n"
    ": d< ( d1 d2 -- f ) rot 2dup = if 2drop u< else swap < nip nip then ;\n"
    ": du< ( ud1 ud2 -- f ) rot 2dup = if 2drop u< else swap u< nip nip then ;\n"
    ": 2swap ( a b c d -- c d a b ) rot >r rot r> ;\n"
    ": 2over ( a b c d -- a b c d a b ) >r >r 2dup r> r> 2swap ;\n"
    ": d> ( d1 d2 -- f ) 2swap d< ;\n"
    ": dmax ( d1 d2 -- d ) 2over 2over d< if 2swap then 2drop ;\n"
    ": dmin ( d1 d2 -- d ) 2over 2over d> if 2swap then 2drop ;\n"
    ": m* ( n1 n2 -- d ) 2dup xor >r abs swap abs um* r> 0< if dnegate then ;\n"
    ": m+ ( d n -- d ) s>d d+ ;\n"
    ": ud. ( ud -- ) <# #s #> type space ;\n"
    ": d. ( d -- ) tuck dabs <# #s rot sign #> type space ;\n";

int vm_library_load(embed_t* vm)
{
    return embed_eval(vm, vm_library_source);
}
