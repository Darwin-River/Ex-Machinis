\ A dictionary of useful words for Public Forge drones (#380), gathered
\ from the starters and company designs. Copy the ones you need into your
\ script: a drone can't load another file. documents/forth-words.md
\ explains each one. This file loads on the drone VM as a whole (CI checks).

\ --- Goods the game words don't name ----------------------------------
2021 constant alloys-a1   2022 constant alloys-a2   2023 constant alloys-a3
2051 constant polymers    3011 constant rods        3001 constant sheets
2032 constant inorganics-a2   2042 constant organics-a2

\ --- Inventory ----------------------------------------------------------
\ Total metals aboard
: metals ( -- n ) metals-a1 units metals-a2 units + metals-a3 units + ;

\ --- Mining -------------------------------------------------------------
\ Mine until n units of a mineral are aboard; wait an hour while its
\ deposit here refills (at Mars's moons the starters move on instead)
: dig ( mineral n -- )
  begin over units over < while
    over deposit 5 < if 60 wait drop else
      \ A run that brings nothing (others took it first) still burns fuel:
      \ wait as if the deposit were dry
      over units >r  over mine drop  over units r> = if 60 wait drop then
    then
  repeat 2drop ;

\ --- Fuel ---------------------------------------------------------------
\ Brew 30 fuel from the Moon's yalspar and vladnium (burns about 15)
: brew-fuel ( -- )
  yalspar 16 dig yalspar refine drop
  vladnium 12 dig vladnium refine drop
  fuel make drop ;

\ Below n fuel, buy m from the House here; it arrives when the hour's
\ batch clears, so wait for it
: top-up ( n m -- )
  swap fuel units > if fuel buy-from-station drop 120 wait drop
  else drop then ;

\ --- Selling ------------------------------------------------------------
\ Sell at most 15 of a good this hour: a fuller batch pays less
: portion ( resource -- )
  dup units 15 min ?dup if swap sell-to-station drop else drop then ;

\ Offer a good to other drones here at a price, keeping none; the order
\ stays open as the hold changes
: offer ( price resource -- ) 0 swap sell-order drop ;

\ --- Buying from other drones -------------------------------------------
\ Buy up to n of a good from the cheapest seller here at price or less;
\ leaves 0 when it bought, else nonzero
: buy-cheap ( n resource price -- ior )
  >r dup 610 ask 0> 0= if 2drop r> drop -1 exit then
  answer 2@ drop r> > if 2drop -1 exit then
  answer 6 + @ answer 4 + @ - 0 max rot min
  ?dup 0= if drop -1 exit then
  swap answer 8 + @ pull ;

\ --- Making -------------------------------------------------------------
\ Make goods one after another while the inputs last; stop at a failure.
\ Example: rods from Metals (A1), two metals a rod
: forge-rods ( -- )
  begin metals-a1 units 1 > while
    alloys-a1 make if exit then  rods make if exit then
  repeat ;

\ So that this file can also be released as it is (it does nothing)
: launched ( -- ) ;
