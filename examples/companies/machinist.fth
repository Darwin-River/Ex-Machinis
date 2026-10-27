\ Machinist: a company design for Public Forge (economy rounds 10, 11, 14).
\ It builds gear boxes, which the Earth and Mars Exchanges buy for 1,075 (about
\ 10 a day each). A gear box takes a rod, a gear and a spring: 8 Metals (A1)
\ and 2 Metals (A2) through 5 alloys. It buys the metals from the Moon
\ miners' sell orders when it can, and otherwise mines yalspar (Metals (A1))
\ and vladnium (Metals (A2)) itself. With a few gear boxes it flies to
\ Earth, buys fuel there first, sells them 2 an hour and comes back. It
\ never leaves with less than low-fuel: every action at Earth, even
\ ordering fuel, burns a unit (round 10 lost two that arrived empty).
\ A gear box burns about 40 fuel and a brew nets only about 15, so it
\ brews until the tank is full again, and buys fuel if it runs low.

2021 constant alloys-a1  2022 constant alloys-a2  3011 constant rods
3101 constant springs    3111 constant gears      4111 constant gear-boxes
2051 constant polymers   2032 constant inorganics-a2
4 constant box-load      \ gear boxes it takes to Earth
60 constant low-fuel     \ brew below this: a box burns about 23, a brew 15
300 constant cash-floor  \ credits it keeps for fuel

: dig ( mineral n -- )
  begin over units over < while
    over deposit 5 < if 60 wait drop else
      \ A run that brings nothing (others took it first) still burns fuel:
      \ wait as if the deposit were dry
      over units >r  over mine drop  over units r> = if 60 wait drop then
    then
  repeat 2drop ;

\ Buy up to n of a metal from the cheapest seller here at price or less
: buy ( n metal price -- )
  credits cash-floor 0 d< if 2drop drop exit then
  >r dup 610 ask 0> 0= if 2drop r> drop exit then
  answer 2@ drop r> > if 2drop exit then
  answer 6 + @ answer 4 + @ - 0 max rot min ?dup 0= if drop exit then
  swap answer 8 + @ pull drop ;

\ Get the metals for one gear box: buy them, else mine them
: stock-up ( -- )
  8 metals-a1 units - 0 max ?dup if metals-a1 23 buy then
  2 metals-a2 units - 0 max ?dup if metals-a2 33 buy then
  metals-a1 units 8 < if yalspar 16 dig yalspar refine drop then
  metals-a2 units 2 < if vladnium 12 dig vladnium refine drop then ;

: brew-fuel ( -- )
  yalspar 16 dig yalspar refine drop
  vladnium 12 dig vladnium refine drop
  fuel make drop ;

\ Brew until the tank is back to low-fuel; too low to brew safely, buy 30
\ from the House here instead (it arrives when the batch clears)
: refuel ( -- )
  begin fuel units low-fuel < while
    fuel units 20 < if 30 fuel buy-from-station drop 120 wait drop exit then
    brew-fuel
  repeat ;

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

\ By-products to the Moon Depot, so the hold never fills
: tidy ( -- )
  polymers sell-all inorganics-a1 sell-all inorganics-a2 sell-all
  organics-a1 units 4 > if organics-a1 sell-all then
  metals-a3 sell-all ;

: sell-boxes ( -- )
  6 begin dup 0> gear-boxes units 0> and while
    gear-boxes units 2 min gear-boxes sell-to-station drop
    60 wait drop 1-
  repeat drop ;

\ Growth (#380): with 7,000 credits or more, at an exchange, buy a Mining
\ Cruiser kit and a Starter Kit I, launch a clone of this drone (it runs
\ this same program), and hand it the starter kit and 40 fuel
6001 constant cruiser-kit   5901 constant starter-kit
\ Buy one of a good here unless one is aboard, and wait (up to 6 game
\ hours) for it: the House delivers when the batch clears
: get-one ( resource -- )
  dup units if drop exit then
  dup 1 swap buy-from-station drop
  6 begin over units 0= over 0> and while 60 wait drop 1- repeat 2drop ;
\ Hand the newest clone a starter kit and 40 fuel
: equip-clone ( -- )
  670 ask ?dup 0= if exit then
  1- 4 * answer + @ >r
  1 starter-kit r@ push drop  40 fuel r> push drop ;
\ Top the tank up to n from the House here, waiting (up to 6 game hours)
: fill-to ( n -- )
  dup fuel units > if
    dup fuel units - fuel buy-from-station drop
    6 begin over fuel units > over 0> and while 60 wait drop 1- repeat drop
  then drop ;
\ One kit at a time: equipment fills 364 of the 500 cargo. A hull already
\ bought is launched whenever the fuel allows; after equipping the clone
\ the tank is refilled (round 19 lost 8 parents that left with 20 fuel)
: reinvest ( -- )
  starter-kit units if equip-clone 60 fill-to exit then
  cruiser-kit units 0= if
    credits 7000 0 d< if exit then
    cruiser-kit get-one  cruiser-kit units 0= if exit then
  then
  90 fill-to  fuel units 80 < if exit then
  me cruiser-kit launch if exit then
  starter-kit get-one  starter-kit units if equip-clone then
  60 fill-to ;
\ A clone starts with no equipment: wait for the starter kit
: await-kit ( -- ) begin starter-kit units 0= while 60 wait drop repeat ;

: haul ( -- )
  refuel
  earth go drop
  fuel units 60 < if 30 fuel buy-from-station drop 120 wait drop then
  sell-boxes reinvest moon go drop ;

: work ( -- )
  refuel
  stock-up build tidy
  gear-boxes units box-load < 0= if haul then ;

: launched ( -- ) await-kit unpack drop moon go drop begin work again ;
