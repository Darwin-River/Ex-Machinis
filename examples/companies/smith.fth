\ Smith: a company design for Public Forge (economy rounds 2, 4, 6, 9, 12).
\ Copies spread out: a third work the Moon and sell rods at Earth; the rest
\ work Phobos and Deimos and sell rods (and by-products) at the Mars
\ Exchange, a short hop away. Below, "Earth" means the rod market.
\ It works the Moon's yalspar and vladnium like the Chemist, but turns its
\ Metals (A1) into rods (2 metals make an alloy, an alloy makes a rod):
\ two metals the Moon Depot pays 40 for become a rod the Earth Exchange
\ pays 60 for. Everything else it sells to the Moon Depot as it goes, so
\ its hold never fills (round 3 lost every Smith to a full hold: no room
\ to brew fuel). With a load of rods it flies to Earth, sells them 4 an
\ hour (the Exchange wants about 50 a day, and a fuller batch pays less:
\ round 5's Smiths sold 144 at once for about 33 each, not 60), buys fuel
\ and comes back. It buys Metals (A1) from other drones' sell orders
\ before mining its own (#380): up to 30 at the Moon (the Miner asks 25)
\ and 40 at Mars's moons (it asks 36). Two metals make a rod, and every
\ hour not spent mining is an hour making.

2051 constant polymers   2021 constant alloys-a1   3011 constant rods
2032 constant inorganics-a2
12 constant rod-load     \ rods it hauls to Earth (4 cargo each)
40 constant low-fuel     \ brew fuel below this
200 constant cash-floor  \ credits it keeps (for fuel at Earth)
30 constant trip-fuel    \ fuel it buys at Earth

\ The site: 0 the Moon, 1 Phobos, 2 Deimos (by drone number); its markets
variable site#
: site ( -- place )
  site# @ dup 0= if drop moon exit then 1 = if phobos else deimos then ;
: rod-market ( -- place ) site moon = if earth else mars then ;
\ The most it pays other drones for a Metals (A1) where it works
: metal-price ( -- price ) site moon = if 30 else 40 then ;

: dig ( mineral n -- )
  begin over units over < while
    over deposit 5 < if 60 wait drop else
      \ A run that brings nothing (others took it first) still burns fuel:
      \ wait as if the deposit were dry
      over units >r  over mine drop  over units r> = if 60 wait drop then
    then
  repeat 2drop ;

: harvest ( -- )
  yalspar 16 dig yalspar refine drop
  vladnium 12 dig vladnium refine drop ;

\ Buy up to 20 Metals (A1) from the cheapest seller here, at metal-price
\ or less, keeping cash-floor credits
: buy-metals ( -- )
  credits cash-floor 0 d< if exit then
  metals-a1 610 ask 0> 0= if exit then
  answer 2@ drop metal-price > if exit then
  answer 6 + @ answer 4 + @ - 0 max 20 min ?dup 0= if exit then
  metals-a1 answer 8 + @ pull drop ;

\ Metals (A1) into rods, two at a time; stop at the first failure
: forge-rods ( -- )
  begin metals-a1 units 1 > while
    alloys-a1 make if exit then  rods make if exit then
  repeat ;

: sell-goods ( -- )
  site moon = 0= if exit then   \ Mars-side copies sell them on the haul
  polymers sell-all metals-a2 sell-all
  inorganics-a1 sell-all inorganics-a2 sell-all ;

\ Sell at most 4 rods an hour, for up to 6 hours; the rest go next time
: sell-rods ( -- )
  6 begin dup 0> rods units 0> and while
    rods units 4 min rods sell-to-station drop 60 wait drop 1-
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
\ (Waits for the delivery, not for n: computing burns a unit or two just
\ after it arrives, and waiting for n then sat out all 6 hours)
: fill-to ( n -- )
  fuel units tuck - dup 0> if
    fuel buy-from-station drop
    6 begin over fuel units < 0= over 0> and while 60 wait drop 1- repeat
  then 2drop ;
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

\ Fuel first: every action at the market burns a unit
: haul ( -- )
  rod-market go drop
  fuel units 60 < if trip-fuel fuel buy-from-station drop 120 wait drop then
  sell-rods
  site moon = 0= if
    polymers sell-all metals-a2 sell-all
    inorganics-a1 sell-all inorganics-a2 sell-all
  then
  reinvest site go drop ;

: work ( -- )
  \ Mine only when short of metals, or of the minerals that brew fuel
  buy-metals  metals-a1 units 10 <  fuel units low-fuel < or if harvest then
  fuel units low-fuel < if fuel make drop else polymers make drop then
  forge-rods sell-goods
  rods units rod-load < 0= if haul then ;

: launched ( -- )
  await-kit unpack drop me 3 mod site# ! site go drop begin work again ;
