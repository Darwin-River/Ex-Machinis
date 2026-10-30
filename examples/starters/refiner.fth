\ Refiner: a starter script for Public Forge (#324 step 15). Release it with
\ the Mining Kit (Engine, Worm and Refinery).
\ It mines yalspar and vladnium and refines them into what the factories
\ need to brew fuel and make polymers: Organics (A1) and Inorganics (A1),
\ with Metals (A1), Metals (A2) and Inorganics (A2) besides. Copies spread
\ out: a third work the Moon, the rest Phobos and Deimos (Mars's moons),
\ selling at the Mars Exchange. Like the Miner, it offers its goods to other
\ drones first (#380), halfway between the best bid and the cheapest other
\ seller here, and sells what nobody bought into the best buy orders. Its
\ kit has no Chemical Reactor: it buys fuel wherever it is cheapest. Change
\ the numbers to make it your own.

40 constant low-fuel     \ get fuel when below this
240 constant rest        \ game minutes to rest when both Mars moons are dry
16 constant hold-cap     \ goods it keeps on offer to other drones

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

\ Buy up to n of a resource here, cheapest first: buy-here pulls from the
\ cheapest sell orders here (any drone's, the station drone's too),
\ one after another until it has n or nobody sells more
: buy-cheapest ( n resource -- ) buy-here drop ;

\ Sell up to n of a resource here, best price first: sell-here pushes into
\ the best buy orders here (any drone's, the station drone's too)
: sell-best ( n resource -- ) sell-here drop ;

\ Sell all of a resource aboard, the same way
: sell-out ( resource -- ) dup units ?dup if swap sell-best else drop then ;

\ Where nobody sells fuel, bid for it a little over the exchanges' price:
\ a fuel courier finds the bid in the market (no message needed); the bid
\ stops once the tank is filled at the market
: ask-for-fuel ( -- )
  7 60 fuel buy-order drop ;
: stop-fuel-bid ( -- ) 1 0 fuel buy-order drop ;

\ Fuel when low, from whoever sells it cheapest here; where nobody does
\ (Phobos and Deimos have no exchange), call for it, then fetch it from the
\ market if nobody has come
: refuel ( -- )
  fuel units low-fuel < 0= if exit then
  30 fuel buy-cheapest  60 wait drop
  fuel units low-fuel < if
    ask-for-fuel  120 wait drop
    fuel units low-fuel < if
      market go drop  30 fuel buy-from-station drop  120 wait drop  site go drop
    then
    stop-fuel-bid
  then ;

\ A low deposit: at the Moon, wait for it to refill; at Mars, try the other
\ moon, and rest after both are mined out. Short of fuel, rest instead of
\ flying: in round 22 crowded Mars moons ran dry and five miners spent their
\ last fuel flying between them
: move-on ( -- )
  \ At the Moon, wait for the deposit to refill. After 12 dry tries in a
  \ row (round 28: crowded by copies of this starter), try Phobos, if fewer
  \ drones work there (round 29: six tries sent it on a 10-day trip early)
  site# @ 0= if
    1 misses +!
    misses @ 12 < fuel units 60 < or phobos crowd moon crowd < 0= or
    if 60 wait drop exit then
    1 site# !  0 misses !  site go drop exit
  then
  fuel units 15 < if rest wait drop exit then
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

: goods ( -- n ) metals-a1 units metals-a2 units + organics-a1 units +
  inorganics-a1 units + inorganics-a2 units + ;

\ Its asking price, from the market here: halfway between the best buy
\ order (sell-price) and the cheapest other sell order (buy-price); with
\ no buy order, a unit under the cheapest sell order; with no sell order,
\ half as much again as the best buy order; 0 where nobody trades it
: ask-price ( good -- price )
  dup sell-price swap buy-price                ( bid offer )
  over 0= if nip 1- 0 max exit then
  dup 0= if drop dup 2/ + exit then
  + 2/ ;
\ Standing sell orders for its goods, set where the market has prices (the
\ Moon, Mars); they stay open as the hold changes, and go with the drone
: offer-one ( good -- ) dup ask-price ?dup if 0 rot sell-order drop else drop then ;
: offer ( -- ) metals-a1 offer-one metals-a2 offer-one organics-a1 offer-one
  inorganics-a1 offer-one inorganics-a2 offer-one ;

\ Growth (#380): with 7,000 credits or more, at an exchange, buy a Mining
\ Cruiser kit and a Mining Kit I, launch a clone of this drone (it runs
\ this same program), and hand it the kit and 40 fuel
6001 constant cruiser-kit
\ Buy one of a good here unless one is aboard, and wait (up to 6 game
\ hours) for it, in case nobody here has one for sale yet
: get-one ( resource -- )
  dup units if drop exit then
  dup 1 swap buy-from-station drop
  6 begin over units 0= over 0> and while 60 wait drop 1- repeat 2drop ;
\ Hand the newest clone a mining kit and 40 fuel
: equip-clone ( -- )
  670 ask ?dup 0= if exit then
  1- 4 * answer + @ >r
  1 mining-kit r@ push drop  40 fuel r> push drop ;
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
  mining-kit units if equip-clone 60 fill-to exit then
  cruiser-kit units 0= if
    credits 7000 0 d< if exit then
    cruiser-kit get-one  cruiser-kit units 0= if exit then
  then
  90 fill-to  fuel units 80 < if exit then
  me cruiser-kit launch if exit then
  mining-kit get-one  mining-kit units if equip-clone then
  60 fill-to ;
\ A clone starts with no equipment: wait for the kit
: await-kit ( -- ) begin mining-kit units 0= while 60 wait drop repeat ;

\ Sell the goods at the market, top up the tank there (cheapest first),
\ then go back to the site, only
\ once hold-cap are aboard: until then other drones may buy them
: sell-goods ( -- )
  goods hold-cap < if exit then
  market go drop  offer
  metals-a1 sell-out metals-a2 sell-out organics-a1 sell-out
  inorganics-a1 sell-out inorganics-a2 sell-out
  \ Waits for the fuel: refuel, next round, would buy it again
  fuel units 50 < if 80 fill-to then
  reinvest site go drop ;

\ Every 8th round, send a line home. What a drone prints reaches you only
\ when its script ends or calls report, and launched never ends
variable tells
: tell ( -- )
  1 tells +!  tells @ 8 mod if exit then
  ." credits " credits d. ." fuel " fuel units . report ;

: work ( -- )
  refuel
  yalspar 16 dig yalspar refine drop
  vladnium 12 dig vladnium refine drop  sell-goods tell ;

: launched ( -- )
  await-kit unpack drop
  copy @ if me 3 mod else 0 then site# !  1 copy !  site go drop
  offer begin work again ;
