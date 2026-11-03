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
    "create answer 120 allot\n"
    ": ask ( [n] id -- rows ) >r answer 120 r> query ;\n"
    ": units ( resource -- n ) 212 ask drop answer @ ;\n"
    /* A fuel floor (#380 round 36: Sven 2 sold its last fuel away in a
       deep build): once set, at or below it make (but fuel), mine, refine
       and push refuse with -30, so the fuel left is for buying fuel */
    "variable floor-fuel\n"
    ": fuel-floor ( n -- ) floor-fuel ! ;\n"
    ": floored? ( -- f ) floor-fuel @ ?dup if fuel units < 0= else 0 then ;\n"
    ": travel ( place -- ior ) 5001 perform ;\n"
    ": mine ( mineral -- ior ) floored? if drop -30 exit then 10000 + perform ;\n"
    ": refine ( mineral -- ior ) floored? if drop -30 exit then\n"
    "  20000 + perform ;\n"
    ": make ( resource -- ior ) dup fuel <> floored? and if drop -30 exit then\n"
    "  10000 + perform ;\n"
    ": wait ( minutes -- ior ) 3 perform ;\n"
    ": push ( n resource drone -- ior ) floored? if 2drop drop -30 exit then\n"
    "  10 perform ;\n"
    ": pull ( n resource drone -- ior ) 11 perform ;\n"
    ": launch ( drone kit -- ior ) 12 perform ;\n"
    ": plunder ( wreck -- ior ) 34 perform ;\n"
    ": jettison ( n resource -- ior ) 1 perform ;\n"
    ": me ( -- drone ) 205 ask drop answer @ ;\n"
    /* Orders (#380 round 31): an order stays up until the drone posts
       another for the same resource and side, and every post is an action
       that costs fuel. sell-order and buy-order note what they posted (16
       slots: resource, or minus it for a buy; limit; price), and ?sell-order
       and ?buy-order skip a post that would change nothing. A launched copy
       starts with its parent's memory but none of its orders, so the notes
       say whose they are. */
    "create my-orders 96 allot variable my-orders-of 0 my-orders-of !\n"
    ": orders-forget ( -- ) my-orders 96 + my-orders begin 2dup > while\n"
    "  0 over ! 2 + repeat 2drop ;\n"
    "orders-forget\n"
    ": order-find ( key -- addr|0 ) my-orders 96 + my-orders begin 2dup > while\n"
    "  dup @ 3 pick = if nip nip exit then 6 + repeat 2drop drop 0 ;\n"
    ": order-slot ( key -- addr|0 ) dup order-find ?dup if nip exit then\n"
    "  drop 0 order-find ;\n"
    ": order-note ( price n key -- ) dup order-slot ?dup if\n"
    "  tuck ! tuck 2 + ! 4 + ! else 2drop drop then ;\n"
    ": order-same? ( price n key -- f ) order-find ?dup if\n"
    "  dup 2 + @ rot = swap 4 + @ rot = and else 2drop 0 then ;\n"
    ": orders-mine ( -- ) me dup my-orders-of @ = if drop exit then\n"
    "  my-orders-of ! orders-forget ;\n"
    ": sell-order ( price keep resource -- ior ) >r 2dup r@ 7 perform ?dup\n"
    "  if r> drop nip nip else r> order-note 0 then ;\n"
    ": buy-order ( price want resource -- ior ) >r 2dup r@ 8 perform ?dup\n"
    "  if r> drop nip nip else r> negate order-note 0 then ;\n"
    ": ?sell-order ( price keep resource -- ior ) orders-mine >r 2dup r@ order-same?\n"
    "  if r> drop 2drop 0 else r> sell-order then ;\n"
    ": ?buy-order ( price want resource -- ior ) orders-mine\n"
    "  >r 2dup r@ negate order-same? if r> drop 2drop 0 else r> buy-order then ;\n"
    ": broadcast ( caddr -- ior ) 40 perform ;\n"
    ": location ( -- place ) me 202 ask drop answer @ ;\n"
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
    /* buy-here and sell-here go down the order book, skipping a seller
       or buyer that refuses (its stock moved since the look, or its order
       keeps more back): at most 6 tries, each an action. They tried the
       same one again (#380 round 35: factories stalled for days on a small
       miner lot). The book is read once (answer); units is read first. */
    "variable trade-n variable trade-r variable trade-me variable trade-ior\n"
    "variable trade-tries variable trade-have\n"
    ": trade-row ( i -- addr ) 10 * answer + ;\n"
    ": trade-start ( n resource -- ) trade-r ! trade-n !\n"
    "  -12 trade-ior ! 0 trade-tries ! me trade-me ! ;\n"
    ": trading? ( rows i -- rows i f )\n"
    "  2dup > trade-n @ 0> and trade-tries @ 6 < and ;\n"
    ": buy-here ( n resource -- ior ) trade-start  trade-r @ 610 ask 0 max 0\n"
    "  begin trading? while dup trade-row dup 8 + @ trade-me @ <> if\n"
    "    dup 6 + @ over 4 + @ - 0 max trade-n @ min\n"
    "    ?dup if 1 trade-tries +!  dup >r trade-r @ rot 8 + @ pull\n"
    "      0= if r> negate trade-n +! 0 trade-ior ! else r> drop then\n"
    "    else drop then else drop then 1+\n"
    "  repeat 2drop trade-ior @ ;\n"
    ": sell-here ( n resource -- ior )\n"
    "  trade-start  trade-r @ units trade-have !\n"
    "  trade-r @ 600 ask 0 max 0\n"
    "  begin trading? while dup trade-row dup 8 + @ trade-me @ <> if\n"
    "    dup 4 + @ over 6 + @ - 0 max trade-n @ min trade-have @ min\n"
    "    ?dup if 1 trade-tries +!  dup >r trade-r @ rot 8 + @ push\n"
    "      0= if r> dup negate trade-n +! negate trade-have +! 0 trade-ior !\n"
    "      else r> drop then\n"
    "    else drop then else drop then 1+\n"
    "  repeat 2drop trade-ior @ ;\n"
    ": buy-from-station ( n resource -- ior ) buy-here ;\n"
    ": sell-to-station ( n resource -- ior ) sell-here ;\n"
    ": sell-all ( resource -- ) dup units ?dup if swap sell-here then drop ;\n"
    "13 constant message-event\n"
    ": events-here ( type -- n ) >r -1 -1 r> -1 -1 -1 -1 -1 -1 803 ask 0 max ;\n"
    ": events-earth ( type -- n ) >r -1 -1 r> -1 -1 -1 -1 -1 -1 804 ask 0 max ;\n"
    ": event-id ( i -- d ) 4 * answer + 2@ ;\n"
    ": event-drone ( d -- drone ) 820 ask drop answer @ ;\n"
    ": message ( d -- f ) 844 ask 0> ;\n"
    ": crowd ( place -- n ) 721 ask drop answer @ ;\n"
    /* Recipes (#380 round 36): the first recipe for a resource, in
       answer: rows of protocol, resource, units (made: positive; used,
       fuel too: negative; the tool: 0), minutes */
    ": recipe ( resource -- rows ) 695 ask 0 max ;\n"
    ": recipe-row ( i -- addr ) 8 * answer + ;\n"
    ": stdlib ( -- version ) 18 ;\n";

int vm_library_load(embed_t* vm)
{
    return embed_eval(vm, vm_library_source);
}

int vm_library_load_game(embed_t* vm)
{
    return embed_eval(vm, vm_library_game_source);
}
