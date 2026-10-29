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

/* The game words (#324 step 15): names for places, resources, actions and
   queries, so a script reads "kunspar mine" rather than "11100 perform".
   Every action word leaves perform's result (0 when it worked). They need
   the perform and query callbacks, which the VM gets first. */
const char* vm_library_game_source =
    ": 2* ( n -- n*2 ) 1 lshift ;\n"
    ": 2/ ( n -- n/2 ) 2 / ;\n"
    ": */ ( n1 n2 n3 -- n1*n2/n3 ) 2dup xor 3 pick xor >r\n"
    "  abs >r abs swap abs um* r> um/mod nip r> 0< if negate then ;\n"
    "3 constant earth 10 constant moon 4 constant mars\n"
    "11 constant phobos 12 constant deimos\n"
    "2061 constant fuel 1000 constant magmite 1100 constant kunspar\n"
    "1110 constant yalspar 1120 constant vladnium 1130 constant wolfite\n"
    "5901 constant mining-kit 5902 constant factory-kit\n"
    "2011 constant metals-a1 2012 constant metals-a2 2013 constant metals-a3\n"
    "2031 constant inorganics-a1 2041 constant organics-a1\n"
    "2032 constant inorganics-a2 2042 constant organics-a2\n"
    ": travel ( place -- ior ) 5001 perform ;\n"
    ": mine ( mineral -- ior ) 10000 + perform ;\n"
    ": refine ( mineral -- ior ) 20000 + perform ;\n"
    ": make ( resource -- ior ) 10000 + perform ;\n"
    ": wait ( minutes -- ior ) 3 perform ;\n"
    ": sell-order ( price keep resource -- ior ) 7 perform ;\n"
    ": buy-order ( price want resource -- ior ) 8 perform ;\n"
    ": push ( n resource drone -- ior ) 10 perform ;\n"
    ": pull ( n resource drone -- ior ) 11 perform ;\n"
    ": launch ( drone kit -- ior ) 12 perform ;\n"
    ": plunder ( wreck -- ior ) 34 perform ;\n"
    ": jettison ( n resource -- ior ) 1 perform ;\n"
    "create answer 120 allot\n"
    ": ask ( [n] id -- rows ) >r answer 120 r> query ;\n"
    ": me ( -- drone ) 205 ask drop answer @ ;\n"
    ": broadcast ( caddr -- ior ) 40 perform ;\n"
    ": heard ( n -- f ) 690 ask 0> ;\n"
    ": heard-company ( n -- f ) 691 ask 0> ;\n"
    ": heard-from ( -- drone ) answer @ ;\n"
    ": heard-text ( -- caddr ) answer 8 + ;\n"
    ": location ( -- place ) me 202 ask drop answer @ ;\n"
    ": units ( resource -- n ) 212 ask drop answer @ ;\n"
    ": unpack ( -- ior ) 5902 units if 25902 else 25901 then perform ;\n"
    ": company ( -- id ) me 201 ask drop answer @ ;\n"
    ": credits ( -- d ) company 101 ask drop answer 2@ ;\n"
    ": deposit ( mineral -- n ) 640 ask 0 max begin dup while 1- dup 12 * answer +\n"
    "  dup @ 3 pick = if 2 + 2@ drop nip nip exit then drop repeat 2drop 0 ;\n"

    ": go ( place -- ior ) dup location = if drop 0 else travel then ;\n"
    ": today ( -- day ) 207 ask drop answer @ ;\n"
    ": trip ( place -- hours ) 704 ask 0> if answer 8 + @ else -1 then ;\n"
    ": best-offer ( resource -- price drone n ) me >r 610 ask 0 max 0\n"
    "  begin 2dup > while dup 10 * answer + dup 8 + @ r@ <> if\n"
    "  nip nip dup 2@ drop over 8 + @ rot dup 6 + @ swap 4 + @ - 0 max\n"
    "  r> drop exit then drop 1+ repeat 2drop r> drop 0 0 0 ;\n"
    ": best-bid ( resource -- price drone n ) me >r 600 ask 0 max 0\n"
    "  begin 2dup > while dup 10 * answer + dup 8 + @ r@ <> if\n"
    "  nip nip dup 2@ drop over 8 + @ rot dup 4 + @ swap 6 + @ - 0 max\n"
    "  r> drop exit then drop 1+ repeat 2drop r> drop 0 0 0 ;\n"
    ": sell-price ( resource -- price ) best-bid 0= if 2drop 0 else drop then ;\n"
    ": buy-price ( resource -- price ) best-offer 0= if 2drop 0 else drop then ;\n"
    "variable mode variable #ticks\n"
    ": become ( mode -- ) mode ! 0 #ticks ! ;\n"
    ": in? ( mode -- f ) mode @ = ;\n"
    ": tick ( -- ) 1 #ticks +! ;\n"
    ": ticks ( -- n ) #ticks @ ;\n"
    ": sold ( resource days-ago place -- units ) >r 682 ask 0 max\n"
    "  begin dup while 1- dup 8 * answer + dup @ r@ = if\n"
    "  2 + 2@ drop nip r> drop exit then drop\n"
    "  repeat r> 2drop 0 ;\n"
    "variable trade-n variable trade-r\n"
    ": trade-took ( ior t k -- 0 t ) negate trade-n +! nip 0 swap ;\n"
    ": trade-less ( ior t k -- ior t ) 2/ dup if trade-n ! else 2drop 0 then ;\n"
    ": buy-here ( n resource -- ior ) trade-r ! trade-n ! -12 6\n"
    "  begin dup 0> trade-n @ 0> and while 1- trade-r @ best-offer rot drop\n"
    "  dup 0= if 2drop drop exit then trade-n @ min tuck trade-r @ rot pull\n"
    "  if trade-less else trade-took then repeat drop ;\n"
    ": sell-here ( n resource -- ior ) trade-r ! trade-n ! -12 6\n"
    "  begin dup 0> trade-n @ 0> and while 1- trade-r @ best-bid rot drop\n"
    "  trade-n @ min trade-r @ units min dup 0= if 2drop drop exit then\n"
    "  tuck trade-r @ rot push if trade-less else trade-took then\n"
    "  repeat drop ;\n"
    ": buy-from-station ( n resource -- ior ) buy-here ;\n"
    ": sell-to-station ( n resource -- ior ) sell-here ;\n"
    ": sell-all ( resource -- ) dup units ?dup if swap sell-here then drop ;\n"
    ": stdlib ( -- version ) 15 ;\n";

int vm_library_load(embed_t* vm)
{
    return embed_eval(vm, vm_library_source);
}

int vm_library_load_game(embed_t* vm)
{
    return embed_eval(vm, vm_library_game_source);
}
