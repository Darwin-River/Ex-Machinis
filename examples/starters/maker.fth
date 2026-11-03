\ Maker: a starter script for Public Forge. Release it with the
\ Factory Kit (Engine, Smelter, Chemical Reactor and Spider).
\ It can't mine: it buys Metals (A1) at the Moon, wherever they are
\ cheapest (any drone's sell orders there: the miners', the Moon Depot's),
\ makes them into alloys and the alloys into rods, and sells the rods
\ wherever they pay best: at the Moon if the best bid there is close to what
\ rods fetched at Earth yesterday (the price board), else it flies a load to
\ Earth (the exchanges' drones buy rods, a few a day, and so may players).
\ With grow-at credits it launches a copy of itself. Two metals make an alloy and an alloy makes a rod, which has sold
\ for several times its metals: check the order book and the price board
\ (query 680) before you release it. It buys its fuel wherever it is
\ cheapest too.

\ Settings: change these numbers to make it your own
12 constant rod-load     \ rods it takes to Earth (4 cargo each)
40 constant low-fuel     \ buy fuel below this
25 constant keep-fuel    \ it stops making rods below this much fuel
20 constant trip-margin  \ flies to Earth only if rods sold there for
                         \ this much more than the best bid here
7000 constant grow-at    \ credits at which it buys and launches a copy
                         \ (6500 at least: a hull is 3000, a kit 2000,
                         \ and 1500 left to work with)
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

\ Metals into rods, two at a time; stop at the first failure, or when the
\ tank is down to keep-fuel (round 34: forging to 10 left a Maker with 4
\ fuel and no seller near)
: forge-rods ( -- )
  begin metals-a1 units 1 > fuel units keep-fuel > and while
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

\ What rods sold for, a unit, at a place over the last game day (the
\ public price board, query 680: 12 cells a row, the resource first, the
\ units at +2 and the credits at +6, both doubles); 0 if none sold there
: board-price ( resource place -- price )
  swap 680 ask 0 max begin dup while 1- dup 12 * answer +
    dup @ 3 pick = if
      dup 6 + 2@ drop swap 2 + 2@ drop  ?dup if / else drop 0 then
      nip nip exit
    then drop
  repeat 2drop 0 ;

\ Sell here? Yes if somebody here bids for rods and Earth paid no more
\ than trip-margin over that yesterday (round 34: Makers that all flew to
\ Earth got 84 to 140 while the Moon Depot paid 158 to 168)
: sell-here? ( -- f )
  rods sell-price dup 0= if exit then
  trip-margin +  rods earth board-price  < 0= ;

\ Growth: with grow-at credits or more, buy a Mining Cruiser kit and a
\ Factory Kit from the exchange here (every exchange sells both), launch a
\ copy of this drone (it runs this same program), and hand it the kit and
\ 40 fuel. A copy costs about 5,000 and the hull and kit count for less
\ than they cost in net wealth: growing pays early, not in the last days.
6001 constant cruiser-kit
6500 constant grow-floor
\ Buy one of a good here unless one is aboard, and wait (up to 6 game
\ hours) for it, in case nobody here has one for sale yet
: get-one ( resource -- )
  dup units if drop exit then
  dup 1 swap buy-from-station drop
  6 begin over units 0= over 0> and while 60 wait drop 1- repeat 2drop ;
\ Hand the newest copy a Factory Kit and 40 fuel
: equip-clone ( -- )
  670 ask ?dup 0= if exit then
  1- 4 * answer + @ >r
  1 factory-kit r@ push drop  40 fuel r> push drop ;
\ Top the tank up to n here, cheapest first, waiting (up to 6 game hours)
: fill-to ( n -- )
  fuel units tuck - dup 0> if
    fuel buy-from-station drop
    6 begin over fuel units < 0= over 0> and while 60 wait drop 1- repeat
  then 2drop ;
\ One kit at a time; a hull already bought is launched once the fuel allows
: reinvest ( -- )
  factory-kit units if equip-clone 60 fill-to exit then
  cruiser-kit units 0= if
    credits grow-at grow-floor max 0 d< if exit then
    cruiser-kit get-one  cruiser-kit units 0= if exit then
  then
  90 fill-to  fuel units 80 < if exit then
  me cruiser-kit launch if exit then
  factory-kit get-one  factory-kit units if equip-clone then
  60 fill-to ;
\ A copy starts with no equipment: wait for its kit
: await-kit ( -- ) begin factory-kit units 0= while 60 wait drop repeat ;

\ Every 4th round, send a line home. What a drone prints reaches you only
\ when its script ends or calls report, and launched never ends
variable tells
: tell ( -- )
  1 tells +!  tells @ 4 mod if exit then
  ." credits " credits d. ." rods " rods units . ."  fuel " fuel units . report ;

: work ( -- )
  refuel stock-up forge-rods
  rods units 0> sell-here? and if sell-rods else
    rods units rod-load < 0=  stuck? or if haul then
  then reinvest tell ;

: launched ( -- ) await-kit unpack drop moon go drop begin work again ;
