\ Smith: a company design for Public Forge (economy rounds 2, 4, 6, 9, 12;
\ #380). Release it with the Factory Kit (Engine, Smelter, Chemical Reactor
\ and Spider).
\ It turns Metals (A1) into rods (two metals make an alloy, an alloy makes
\ a rod the House pays 225 for). It can't mine (#380: the mining gear is
\ too big to carry with the factory tools): it buys its metals, and its
\ fuel, wherever they are cheapest, from the miners' sell orders or from
\ the House, which asks half as much again as it pays. Copies spread out:
\ a third work the Moon and sell their rods at Earth, a short hop away; the
\ rest work Mars orbit, where the Mars Exchange sells metals and buys rods.
\ It sells rods 4 an hour: the Exchanges want about 50 a day each, and a
\ station drone pays less the more it holds.

2021 constant alloys-a1   3011 constant rods
12 constant rod-load     \ rods it takes to market (4 cargo each)
40 constant low-fuel     \ buy fuel below this
20 constant batch        \ metals it buys at a time
300 constant cash-floor  \ credits it keeps (for fuel)

\ The site: 0 the Moon, else Mars orbit (by drone number); its rod market
variable site#
: site ( -- place ) site# @ 0= if moon else mars then ;
: rod-market ( -- place ) site moon = if earth else mars then ;

\ Buy up to n of a resource here where it is cheapest: another drone's sell
\ order (best-offer) or the House station (house-price, side 2: what it
\ asks). Either way it pulls at once.
\ A drone selling only a unit or two can't starve it: what the drone
\ doesn't sell (or a pull that fails) it buys from the House
: buy-cheapest ( n resource -- )
  >r  r@ 2 house-price  r@ best-offer         ( n house price drone avail )
  dup 0> 3 pick 0> and  3 pick 5 pick > 0=  5 pick 0= or  and if
    rot drop  3 pick min  tuck  r@ rot pull if drop 0 then  ( n house k )
    rot swap -  swap 0<>  over 0> and                ( rest f )
  else 2drop drop 0<> then                           ( n f )
  if r> buy-from-station drop else r> 2drop then ;

\ Sell up to n of a resource here: into the best buy order (a House
\ station drone's, or another player's), at once, when it pays at least
\ half what the House lists (a station drone bids less as its stock fills,
\ down to half). What that order doesn't take it offers to the House
\ station drone here
: sell-best ( n resource -- )
  >r  r@ best-bid                               ( n price drone avail )
  dup 0>  3 pick  r@ 1 house-price 2 /  < 0=  and if
    rot drop  2 pick min  tuck  r@ rot push if drop 0 then  -   ( rest )
  else 2drop drop then
  ?dup if r> sell-to-station drop else r> drop then ;

: refuel ( -- )
  fuel units low-fuel < if 30 fuel buy-cheapest 60 wait drop then ;

\ Credits above cash-floor, at most 32,000 (credits are a double)
: spare ( -- n )
  credits cash-floor 0 d- dup 0< if 2drop 0 exit then
  if drop 32000 exit then  dup 0< if drop 32000 then ;

\ Metals for a batch, as many as the spare credits pay for (at up to 30 a
\ unit); wait for the House's delivery
: stock-up ( -- )
  metals-a1 units 2 < 0= if exit then
  batch spare 30 / min ?dup if metals-a1 buy-cheapest 60 wait drop then ;

\ Out of metals and of money to buy them, with rods aboard: go and sell
\ them, however few (else it would wait for a full load for ever)
: stuck? ( -- f ) metals-a1 units 2 <  spare 60 <  and  rods units 0> and ;

\ Metals (A1) into rods, two at a time; stop at the first failure
: forge-rods ( -- )
  begin metals-a1 units 1 > fuel units 10 > and while
    alloys-a1 make if exit then  rods make if exit then
  repeat ;

\ Sell at most 4 rods an hour, for up to 6 hours (the station drone pays
\ less as its stock fills, and bids again each hour); the rest go next time
: sell-rods ( -- )
  6 begin dup 0> rods units 0> and while
    rods units 4 min rods sell-best 60 wait drop 1-
  repeat drop ;

\ Growth (#380): with 7,000 credits or more, at an exchange, buy a Mining
\ Cruiser kit and a Factory Kit I, launch a clone of this drone (it runs
\ this same program), and hand it the kit and 40 fuel
6001 constant cruiser-kit
\ Buy one of a good here unless one is aboard, and wait (up to 6 game
\ hours) for it, in case the House's station drone has none in stock yet
: get-one ( resource -- )
  dup units if drop exit then
  dup 1 swap buy-from-station drop
  6 begin over units 0= over 0> and while 60 wait drop 1- repeat 2drop ;
\ Hand the newest clone a factory kit and 40 fuel
: equip-clone ( -- )
  670 ask ?dup 0= if exit then
  1- 4 * answer + @ >r
  1 factory-kit r@ push drop  40 fuel r> push drop ;
\ Top the tank up to n from the House here, waiting (up to 6 game hours)
\ (Waits for the delivery, not for n: computing burns a unit or two just
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

\ Fuel first: every action at the market burns a unit
: haul ( -- )
  rod-market go drop
  fuel units 60 < if 30 fuel buy-from-station drop 120 wait drop then
  sell-rods reinvest site go drop ;

: work ( -- )
  refuel stock-up forge-rods
  rods units rod-load < 0=  stuck? or if haul then ;

: launched ( -- )
  await-kit unpack drop  me 3 mod 0= if 0 else 1 then site# !
  site go drop begin work again ;
