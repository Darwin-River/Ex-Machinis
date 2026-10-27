\ Miner: a starter script for Public Forge (#324 step 15).
\ It mines kunspar, refines it and sells the metals to the House. Copies
\ spread out: a third work the Moon and sell at the Moon Depot; the rest
\ work Phobos and Deimos (Mars's moons), moving between them as deposits run
\ low, and sell at the Mars Exchange, which pays half as much again. At the
\ Moon it first offers its metals to other drones (traders, who haul them to
\ Mars) a little over the Depot's price. For fuel it buys from another
\ drone's sell order when one is cheap, and otherwise brews its own from
\ yalspar and vladnium. Change the numbers to make it your own.

40 constant low-fuel     \ get fuel when below this (brewing burns ~15)
4 constant fuel-price    \ most it pays a unit for fuel
240 constant rest        \ game minutes to rest when both Mars moons are dry
16 constant hold-cap     \ at the Moon, metals it keeps on offer to traders

\ The site it works: 0 the Moon, 1 Phobos, 2 Deimos. The drone you release
\ works the Moon. A third of its copies (by drone number) work the Moon too;
\ the rest make the long trip to Mars (about 10 game days) once, then work
\ Phobos and Deimos, which are close together.
variable site#   variable misses
\ 0 in the drone you release, which works the Moon so it earns from the
\ start; it is 1 by the time it launches a copy, so copies spread out
variable copy
: site ( -- place )
  site# @ dup 0= if drop moon exit then 1 = if phobos else deimos then ;
\ Where it sells: the Moon Depot at the Moon, else the Mars Exchange
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

\ Fuel from yalspar (organics) and vladnium (inorganics): 30 units
: brew-fuel ( -- )
  yalspar 16 dig yalspar refine drop
  vladnium 12 dig vladnium refine drop
  fuel make drop ;

\ The cheapest fuel for sale here: the seller and its price (0 0 if none)
: fuel-seller ( -- drone price )
  fuel 610 ask 0> if answer 8 + @ answer 2@ drop else 0 0 then ;

\ Buy 30 fuel if someone here sells it cheaply, else brew it
: refuel ( -- )
  fuel-seller fuel-price > over 0= or if drop brew-fuel exit then
  >r 30 fuel r> pull if brew-fuel then ;

: metals ( -- n ) metals-a1 units metals-a2 units + metals-a3 units + ;

\ At the Moon, standing sell orders offer its metals to traders at 3 over
\ the Depot's price (23, 33 and 43); orders stay open as the hold changes
: offer ( -- )
  23 0 metals-a1 sell-order drop  33 0 metals-a2 sell-order drop
  43 0 metals-a3 sell-order drop ;

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

\ Sell the metals at the market, top up the tank from the House there (5 a
\ unit, delivered when its batch clears), then go back to the site. At the
\ Moon, only once hold-cap are aboard: until then traders may buy them
: sell-metals ( -- )
  site# @ 0= if metals hold-cap < if exit then then
  market go drop
  metals-a1 sell-all metals-a2 sell-all metals-a3 sell-all
  fuel units 50 < if 30 fuel buy-from-station drop then
  reinvest site go drop ;

\ Every 8th round, send a line home. What a drone prints reaches you only
\ when its script ends or calls report, and launched never ends
variable tells
: tell ( -- )
  1 tells +!  tells @ 8 mod if exit then
  ." credits " credits d. ." fuel " fuel units . report ;

: work ( -- )
  fuel units low-fuel < if refuel then
  kunspar 20 dig kunspar refine drop sell-metals tell ;

: launched ( -- )
  await-kit unpack drop
  copy @ if me 3 mod else 0 then site# !  1 copy !  site go drop
  site# @ 0= if offer then begin work again ;
