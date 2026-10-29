\ Chemist: a company design for Public Forge (economy rounds 1, 4 and 5;
\ #380). Release it with the Factory Kit (Engine, Smelter, Chemical Reactor
\ and Spider).
\ It can't mine (#380: the mining gear is too big to carry with the
\ factory tools). It buys Organics (A1) and Inorganics (A1) wherever they
\ are cheapest (the refiners' sell orders, or the House, which asks half as
\ much again as it pays), makes polymers from them (one of each makes a
\ polymer the House pays 70 for) and sells them to the House. It brews its
\ own fuel from the same goods (one organics and two inorganics make 30)
\ and offers what it doesn't need to other drones a little under the
\ House's 5, so the miners, who can't brew, buy from it. Copies spread
\ out: a third work the Moon, the rest Mars orbit (the Mars Exchange buys
\ polymers too).

2051 constant polymers
40 constant low-fuel     \ brew fuel below this
60 constant fuel-keep    \ fuel it keeps; it offers the rest to other drones
4 constant fuel-price    \ what it asks other drones for a unit of fuel
6 constant batch         \ organics and inorganics it buys at a time
300 constant cash-floor  \ credits it keeps

\ The site: the Moon or Mars orbit (by drone number); it sells there too
variable site#
: site ( -- place ) site# @ 0= if moon else mars then ;

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

\ The goods for a batch of polymers, and for fuel when low; wait for the
\ House's delivery
: stock-up ( -- )
  credits cash-floor 0 d< if exit then
  organics-a1 units 2 < if batch organics-a1 buy-cheapest then
  inorganics-a1 units 3 < if batch inorganics-a1 buy-cheapest then
  60 wait drop ;

\ Fuel first, then polymers while the goods last (keeping enough for a brew)
: brew ( -- )
  fuel units low-fuel < if fuel make drop then
  begin organics-a1 units 1 > inorganics-a1 units 2 > and while
    polymers make if exit then
  repeat ;

\ Polymers to the House, at most 15 an hour: its station drone pays less as
\ its stock fills
: sell-polymers ( -- )
  polymers units 15 min ?dup if polymers sell-best then ;

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
: fill-to ( n -- )
  fuel units tuck - dup 0> if
    fuel buy-from-station drop
    6 begin over fuel units < 0= over 0> and while 60 wait drop 1- repeat
  then 2drop ;
\ One kit at a time. A hull already bought is launched whenever the fuel
\ allows; after equipping the clone the tank is refilled
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

: work ( -- ) stock-up brew sell-polymers reinvest ;

: launched ( -- )
  await-kit unpack drop  me 3 mod 0= if 0 else 1 then site# !  site go drop
  \ Other drones pull the fuel above fuel-keep
  fuel-price fuel-keep fuel sell-order drop
  begin work again ;
