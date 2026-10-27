\ Machinist: a company design for Public Forge (economy rounds 10 and 11).
\ It builds gear boxes, which the Earth and Mars Exchanges buy for 450 (about
\ 10 a day each). A gear box takes a rod, a gear and a spring: 8 Metals (A1)
\ and 2 Metals (A2) through 5 alloys. It buys the metals from the Moon
\ miners' sell orders when it can, and otherwise mines yalspar (Metals (A1))
\ and vladnium (Metals (A2)) itself. With a few gear boxes it flies to
\ Earth, buys fuel there first, sells them 2 an hour and comes back. It
\ never leaves with less than trip-fuel: every action at Earth, even
\ ordering fuel, burns a unit (round 10 lost two that arrived empty).

2021 constant alloys-a1  2022 constant alloys-a2  3011 constant rods
3101 constant springs    3111 constant gears      4111 constant gear-boxes
2051 constant polymers   2032 constant inorganics-a2
4 constant box-load      \ gear boxes it takes to Earth
60 constant low-fuel     \ brew below this: a box burns about 23, a brew 15
300 constant cash-floor  \ credits it keeps for fuel
30 constant trip-fuel    \ fuel it leaves with, at least

: dig ( mineral n -- )
  begin over units over < while
    over deposit 5 < if 60 wait drop else over mine drop then
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

\ One gear box from 8 Metals (A1) and 2 Metals (A2); stops at a failure
: build ( -- )
  metals-a1 units 8 < metals-a2 units 2 < or if exit then
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

: haul ( -- )
  fuel units trip-fuel < if brew-fuel then
  earth go drop
  fuel units 60 < if 30 fuel buy-from-station drop 120 wait drop then
  sell-boxes moon go drop ;

: work ( -- )
  fuel units low-fuel < if brew-fuel then
  stock-up build tidy
  gear-boxes units box-load < 0= if haul then ;

: launched ( -- ) unpack drop moon go drop begin work again ;
