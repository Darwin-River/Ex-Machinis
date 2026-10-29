\ Machinist: a company design for Public Forge (economy rounds 10, 11, 14;
\ #380). Release it with the Factory Kit (Engine, Smelter, Chemical Reactor
\ and Spider).
\ It builds gear boxes, which station drones at Earth and Mars buy (check
\ the price board, query 680, for what they fetch). A gear box takes a
\ rod, a gear and a spring: 8 Metals (A1) and 2 Metals (A2) through 5
\ alloys. It can't mine (#380: the mining gear is too big to carry with
\ the factory tools): it buys the metals at the Moon, and its fuel,
\ wherever they are cheapest, from the miners' sell orders or the Moon
\ Depot's. With a few gear boxes it flies to Earth, sells them 2 an hour
\ and comes back. A gear box burns about 23 fuel: it never starts one with
\ less than 30, and never leaves with less than low-fuel.

2021 constant alloys-a1  2022 constant alloys-a2  3011 constant rods
3101 constant springs    3111 constant gears      4111 constant gear-boxes
4 constant box-load      \ gear boxes it takes to Earth
60 constant low-fuel     \ buy fuel below this
300 constant cash-floor  \ credits it keeps for fuel

\ Buy up to n of a resource here, cheapest first: buy-here pulls from the
\ cheapest sell orders here (any drone's, the station drone's too),
\ one after another until it has n or nobody sells more
: buy-cheapest ( n resource -- ) buy-here drop ;

\ Sell up to n of a resource here, best price first: sell-here pushes into
\ the best buy orders here (any drone's, the station drone's too)
: sell-best ( n resource -- ) sell-here drop ;

: refuel ( -- )
  fuel units low-fuel < if 30 fuel buy-cheapest 60 wait drop then ;

\ Credits above cash-floor, at most 32,000 (credits are a double)
: spare ( -- n )
  credits cash-floor 0 d- dup 0< if 2drop 0 exit then
  if drop 32000 exit then  dup 0< if drop 32000 then ;

\ The metals for two gear boxes, as many as the spare credits pay for (at
\ most 30 and 45 a unit), from the cheapest sellers here; then wait an hour
: short? ( -- f ) metals-a1 units 8 < metals-a2 units 2 < or ;
: stock-up ( -- )
  short? 0= if exit then
  16 metals-a1 units - 0 max spare 30 / min ?dup if metals-a1 buy-cheapest then
  4 metals-a2 units - 0 max spare 45 / min ?dup if metals-a2 buy-cheapest then
  60 wait drop ;

\ Short of metals and of money to buy them, with gear boxes aboard: go and
\ sell them, however few (else it would wait for a full load for ever)
: stuck? ( -- f ) short? spare 400 < and gear-boxes units 0> and ;

\ One gear box from 8 Metals (A1) and 2 Metals (A2); stops at a failure.
\ It burns about 23 fuel: never start one with less than 30 (round 20)
: build ( -- )
  metals-a1 units 8 < metals-a2 units 2 < or if exit then
  fuel units 30 < if exit then
  alloys-a1 make if exit then  alloys-a1 make if exit then
  alloys-a1 make if exit then
  alloys-a2 make if exit then  alloys-a2 make if exit then
  rods make if exit then  gears make if exit then
  springs make if exit then  gear-boxes make drop ;

: sell-boxes ( -- )
  6 begin dup 0> gear-boxes units 0> and while
    gear-boxes units 2 min gear-boxes sell-best
    60 wait drop 1-
  repeat drop ;

\ Growth (#380): with 7,000 credits or more, at an exchange, buy a Mining
\ Cruiser kit and a Factory Kit I, launch a clone of this drone (it runs
\ this same program), and hand it the kit and 40 fuel
6001 constant cruiser-kit
\ Buy one of a good here unless one is aboard, and wait (up to 6 game
\ hours) for it, in case nobody here has one for sale yet
: get-one ( resource -- )
  dup units if drop exit then
  dup 1 swap buy-from-station drop
  6 begin over units 0= over 0> and while 60 wait drop 1- repeat 2drop ;
\ Hand the newest clone a factory kit and 40 fuel
: equip-clone ( -- )
  670 ask ?dup 0= if exit then
  1- 4 * answer + @ >r
  1 factory-kit r@ push drop  40 fuel r> push drop ;
\ Top the tank up to n here, cheapest first, waiting (up to 6 game hours)
\ (Waits for the fuel, not for n: computing burns a unit or two just
\ after it arrives, and waiting for n then sat out all 6 hours)
: fill-to ( n -- )
  fuel units tuck - dup 0> if
    fuel buy-from-station drop
    6 begin over fuel units < 0= over 0> and while 60 wait drop 1- repeat
  then 2drop ;
\ One kit at a time. A hull already bought is launched whenever the fuel
\ allows; after equipping the clone the tank is refilled (round 19 lost 8
\ parents that left with 20 fuel)
: reinvest ( -- )
  factory-kit units if equip-clone 60 fill-to exit then
  cruiser-kit units 0= if
    credits 7000 0 d< if exit then
    cruiser-kit get-one  cruiser-kit units 0= if exit then
  then
  90 fill-to  fuel units 80 < if exit then
  me cruiser-kit launch if exit then
  factory-kit get-one  factory-kit units if equip-clone then
  60 fill-to ;
\ A clone starts with no equipment: wait for the kit
: await-kit ( -- ) begin factory-kit units 0= while 60 wait drop repeat ;

: haul ( -- )
  refuel
  earth go drop
  fuel units 60 < if 30 fuel buy-from-station drop 120 wait drop then
  sell-boxes reinvest moon go drop ;

: work ( -- )
  refuel stock-up build
  gear-boxes units box-load < 0=  stuck? or if haul then ;

: launched ( -- ) await-kit unpack drop moon go drop begin work again ;
