\ Smith: a company design for Public Forge (economy rounds 2, 4 and 6).
\ It works the Moon's yalspar and vladnium like the Chemist, but turns its
\ Metals (A1) into rods (2 metals make an alloy, an alloy makes a rod):
\ two metals the Moon Depot pays 40 for become a rod the Earth Exchange
\ pays 60 for. Everything else it sells to the Moon Depot as it goes, so
\ its hold never fills (round 3 lost every Smith to a full hold: no room
\ to brew fuel). With a load of rods it flies to Earth, sells them 4 an
\ hour (the Exchange wants about 50 a day, and a fuller batch pays less:
\ round 5's Smiths sold 144 at once for about 33 each, not 60), buys fuel
\ and comes back.

2051 constant polymers   2021 constant alloys-a1   3011 constant rods
2032 constant inorganics-a2
12 constant load         \ rods it hauls to Earth (4 cargo each)
40 constant low-fuel     \ brew fuel below this
30 constant trip-fuel    \ fuel it buys at Earth

: dig ( mineral n -- )
  begin over units over < while
    over deposit 5 < if 60 wait drop else over mine drop then
  repeat 2drop ;

: harvest ( -- )
  yalspar 16 dig yalspar refine drop
  vladnium 12 dig vladnium refine drop ;

\ Metals (A1) into rods, two at a time; stop at the first failure
: forge-rods ( -- )
  begin metals-a1 units 1 > while
    alloys-a1 make if exit then  rods make if exit then
  repeat ;

: sell-goods ( -- )
  polymers sell-all metals-a2 sell-all
  inorganics-a1 sell-all inorganics-a2 sell-all ;

\ Sell at most 4 rods an hour, for up to 6 hours; the rest go next time
: sell-rods ( -- )
  6 begin dup 0> rods units 0> and while
    rods units 4 min rods sell-to-station drop 60 wait drop 1-
  repeat drop ;

: haul ( -- )
  earth go drop sell-rods
  fuel units 60 < if trip-fuel fuel buy-from-station drop 120 wait drop then
  moon go drop ;

: work ( -- )
  harvest
  fuel units low-fuel < if fuel make drop else polymers make drop then
  forge-rods sell-goods
  rods units load < 0= if haul then ;

: launched ( -- ) unpack drop moon go drop begin work again ;
