\ Maker: a starter script for Public Forge. Release it with the
\ Factory Kit (Engine, Smelter, Chemical Reactor and Spider).
\ It can't mine: it buys Metals (A1) at the Moon, wherever they are
\ cheapest (any drone's sell orders there: the miners', the Moon Depot's),
\ makes them into alloys and the alloys into rods, and flies a load of
\ rods to Earth, where the Earth Exchange's drone buys them. Two metals
\ make an alloy and an alloy makes a rod, which has sold for several times
\ its metals (check the price board, query 680). It buys its fuel wherever
\ it is cheapest too.

\ Settings: change these numbers to make it your own
12 constant rod-load     \ rods it takes to Earth (4 cargo each)
40 constant low-fuel     \ buy fuel below this
20 constant batch        \ metals it buys at a time
300 constant cash-floor  \ credits it keeps (for fuel)
3011 constant rods   2021 constant alloys-a1

\ Buy up to n of a resource here, cheapest first: buy-here pulls from the
\ cheapest sell orders here (any drone's, the station drone's too),
\ one after another until it has n or nobody sells more
: buy-cheapest ( n resource -- ) buy-here drop ;

\ Sell up to n of a resource here, best price first: sell-here pushes into
\ the best buy orders here (any drone's, the station drone's too)
: sell-best ( n resource -- ) sell-here drop ;

\ Fuel when low, from whoever sells it cheapest here
: refuel ( -- )
  fuel units low-fuel < if 30 fuel buy-cheapest 60 wait drop then ;

\ Credits above cash-floor, at most 32,000 (credits are a double)
: spare ( -- n )
  credits cash-floor 0 d- dup 0< if 2drop 0 exit then
  if drop 32000 exit then  dup 0< if drop 32000 then ;

\ Metals for a batch, as many as the spare credits pay for (at most 30 a
\ unit), from the cheapest sellers here
: stock-up ( -- )
  metals-a1 units 2 < 0= if exit then
  batch spare 30 / min ?dup if metals-a1 buy-cheapest 60 wait drop then ;

\ Out of metals and of money to buy them, with rods aboard: go and sell
\ them, however few (else it would wait for a full load for ever)
: stuck? ( -- f ) metals-a1 units 2 <  spare 60 <  and  rods units 0> and ;

\ Metals into rods, two at a time; stop at the first failure
: forge-rods ( -- )
  begin metals-a1 units 1 > fuel units 10 > and while
    alloys-a1 make if exit then  rods make if exit then
  repeat ;

\ Sell at most 4 rods an hour, for up to 6 hours: buyers here tend to
\ bid less as they stock up, and bid again later.
\ The rest go next time
: sell-rods ( -- )
  6 begin dup 0> rods units 0> and while
    rods units 4 min rods sell-best 60 wait drop 1-
  repeat drop ;

: haul ( -- )
  earth go drop
  fuel units 60 < if 30 fuel buy-from-station drop 120 wait drop then
  sell-rods moon go drop ;

\ Every 8th round, send a line home. What a drone prints reaches you only
\ when its script ends or calls report, and launched never ends
variable tells
: tell ( -- )
  1 tells +!  tells @ 8 mod if exit then
  ." credits " credits d. ." rods " rods units . ."  fuel " fuel units . report ;

: work ( -- )
  refuel stock-up forge-rods
  rods units rod-load < 0=  stuck? or if haul then tell ;

: launched ( -- ) unpack drop moon go drop begin work again ;
