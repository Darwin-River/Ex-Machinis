\ Refiner: a starter script for Public Forge (#324 step 15).
\ It brews fuel: it mines yalspar (for organics) and vladnium (for
\ inorganics), refines them and makes fuel from the two. It keeps a reserve
\ and sells the rest to the drones around it through a standing sell order,
\ cheaper than hauling fuel from Earth. Copies spread out: a third work the
\ Moon, the rest Phobos and Deimos (Mars's moons), and the sell order goes
\ with the drone. The metals that refining gives too go to the
\ House: the Moon Depot from the Moon, the Mars Exchange from Mars's moons.

30 constant reserve      \ fuel it keeps for itself
4 constant fuel-price    \ what it asks a unit
90 constant fuel-cap     \ stops brewing at this much (the kit fills the hold)
240 constant rest        \ game minutes to rest when both Mars moons are dry

\ The site it works: 0 the Moon, 1 Phobos, 2 Deimos. A third of the copies
\ (by drone number) work the Moon; the rest make the long trip to Mars (about
\ 10 game days) once, then work Phobos and Deimos, which are close together.
variable site#   variable misses
: site ( -- place )
  site# @ dup 0= if drop moon exit then 1 = if phobos else deimos then ;
: market ( -- place ) site moon = if moon else mars then ;

\ A low deposit: at the Moon, wait for it to refill; at Mars, try the other
\ moon, and rest after both are mined out
: move-on ( -- )
  site# @ 0= if 60 wait drop exit then
  site# @ 3 xor site# !  1 misses +!
  misses @ 2 mod 0= if rest wait drop then
  site go drop ;

\ Mine until n units of a mineral are aboard, moving on when its deposit
\ here runs low
: dig ( mineral n -- )
  begin over units over < while
    over deposit 5 < if move-on else
      \ A run that brings nothing (others took it first) still burns fuel:
      \ treat the deposit as dry
      over units >r  over mine drop  over units r> = if move-on
      else 0 misses ! then
    then
  repeat 2drop ;

: brew-fuel ( -- )
  yalspar 16 dig yalspar refine drop
  vladnium 12 dig vladnium refine drop
  fuel make drop ;

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

\ Sell the metals at the market, then go back to the site
: sell-metals ( -- )
  market go drop
  metals-a1 sell-all metals-a2 sell-all metals-a3 sell-all
  reinvest site go drop ;

\ Every 8th round, send a line home. What a drone prints reaches you only
\ when its script ends or calls report, and launched never ends
variable tells
: tell ( -- )
  1 tells +!  tells @ 8 mod if exit then
  ." credits " credits d. ." fuel " fuel units . report ;

: work ( -- )
  fuel units fuel-cap < if brew-fuel sell-metals else 120 wait drop then
  tell ;

: launched ( -- )
  await-kit unpack drop me 3 mod site# ! site go drop
  \ Buyers pull what's above the reserve
  fuel-price reserve fuel sell-order drop
  begin work again ;
