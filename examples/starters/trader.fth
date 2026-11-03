\ Trader: a starter script for Public Forge. Release
\ it with the Mining Kit (it only uses the Engine).
\ It works the Moon, where the miners offer their metals to other drones.
\ The House's prices move (each exchange its own way: booms, slumps,
\ shortages and gluts), so it doesn't trade at fixed prices: for each
\ metal it looks at what it sold for at Mars and at Earth over the last
\ game day (the price board, query 680) and buys here only when that, less
\ the sales tax, beats the seller's price by enough to pay for the trip.
\ With a load aboard it flies wherever the load fetches most, sells it and
\ comes back. It buys its fuel at the exchanges, from whoever sells it
\ cheapest. (Round 36: with fixed prices, all 12 Traders ended slightly
\ down.)

\ Settings: change these numbers to make it your own
60 constant cargo-goal   \ metals it gathers before setting off
20 constant min-load     \ ...or this many, after shopping a while
24 constant patience     \ looks at the market before it goes with less
25 constant trip-fuel    \ fuel it wants aboard before a trip
300 constant trip-cost   \ what a round trip costs (fuel, time)
2 constant min-margin    \ least profit a unit, after the trip and the tax
120 constant nap         \ game minutes to rest when nothing pays

\ What a resource sold for, a unit, at a place over the last game day (the
\ public price board, query 680: 12 cells a row, the resource first, the
\ units at +2 and the credits at +6, both doubles); 0 if none sold there
: board-price ( resource place -- price )
  swap 680 ask 0 max begin dup while 1- dup 12 * answer +
    dup @ 3 pick = if
      dup 6 + 2@ drop swap 2 + 2@ drop  ?dup if / else drop 0 then
      nip nip exit
    then drop
  repeat 2drop 0 ;

\ What a metal fetches at a place, less the 5% sales tax; the best of Mars
\ and Earth; and the most it pays here, less its share of the trip and the
\ margin
: worth ( metal place -- n ) board-price 95 100 */ ;
: best-worth ( metal -- n ) dup mars worth swap earth worth max ;
: max-price ( metal -- price )
  best-worth trip-cost cargo-goal / - min-margin - ;

: metals ( -- n ) metals-a1 units metals-a2 units + metals-a3 units + ;

\ Credits above cash-floor, at most 32,000 (credits are a double). It
\ keeps cash-floor for fuel: a trader that spends every credit on metals
\ can't buy fuel, and is stranded
200 constant cash-floor
: spare ( -- n )
  credits cash-floor 0 d- dup 0< if 2drop 0 exit then
  if drop 32000 exit then  dup 0< if drop 32000 then ;

\ Buy what the cheapest seller here offers of one metal, if the price is
\ right, there is room in this load and the spare credits pay for it. It
\ reads the seller's row first: metals and spare ask the game too, and
\ every question reuses the one answer buffer
variable got   variable looks
: buy ( metal -- )
  dup 610 ask 0> 0= if drop exit then
  answer 2@ drop  answer 8 + @  answer 6 + @ answer 4 + @ - 0 max
  ( metal price seller avail )
  3 pick max-price 3 pick < if 2drop 2drop exit then
  cargo-goal metals - min  spare 3 pick / min
  rot drop  ?dup 0= if 2drop exit then
  rot rot pull 0= if 1 got ! then ;

\ Top up the tank here, from the cheapest seller
: fuel-up ( -- )
  fuel units trip-fuel < if trip-fuel fuel buy-from-station drop
  120 wait drop then ;

\ Sell up to n of a resource here, best price first: sell-here pushes into
\ the best buy orders here (any drone's, the station drone's too)
: sell-best ( n resource -- ) sell-here drop ;

\ Sell at most 15 of each metal an hour: buyers here tend to bid less as
\ they stock up. What is left after 6 hours goes on the
\ next trip
: portion ( metal -- )
  dup units 15 min ?dup if swap sell-best else drop then ;
variable rounds

\ What the load aboard fetches at a place, and where it fetches most
: load-worth ( place -- n ) >r
  metals-a1 units metals-a1 r@ board-price *
  metals-a2 units metals-a2 r@ board-price * +
  metals-a3 units metals-a3 r@ board-price * +  r> drop ;
: destination ( -- place )
  mars load-worth earth load-worth < if earth else mars then ;

\ Look for sellers; rest a while when nothing pays. Each look counts
\ toward going with a smaller load, so a trader that buys a little at a
\ time still sets off. Shopping burns fuel too (computing): keep the tank
\ up here
: shop ( -- )
  fuel-up
  0 got !  metals-a1 buy metals-a2 buy metals-a3 buy
  1 looks +!  got @ 0= if nap wait drop then ;

: sell-metals ( -- )
  0 rounds !
  begin metals 0> rounds @ 6 < and while
    metals-a1 portion metals-a2 portion metals-a3 portion
    60 wait drop 1 rounds +!
  repeat ;

: haul ( -- )
  fuel-up destination go drop sell-metals fuel-up moon go drop 0 looks ! ;

\ A full load, or a smaller one after waiting long enough, or whatever it
\ has once its money is spent
: ready? ( -- f )
  metals cargo-goal < 0=
  metals min-load < 0= looks @ patience < 0= and or
  metals 0> spare 20 < and or ;

\ Every 4th round, send a line home. What a drone prints reaches you only
\ when its script ends or calls report, and launched never ends
variable tells
: tell ( -- )
  1 tells +!  tells @ 4 mod if exit then
  ." day " today . ." credits " credits d. ." fuel " fuel units .
  ." metals " metals . report ;

: work ( -- ) ready? if haul else shop then tell ;

: launched ( -- ) unpack drop moon go drop begin work again ;
