\ Trader: a starter script for Public Forge (#324 step 15; #367). Release
\ it with the Mining Kit (it only uses the Engine).
\ It works the Moon, where the Miner offers its metals to other drones
\ between what the Moon Depot pays and what it asks (#380). It buys them, hauls a load to Mars,
\ where the Mars Exchange pays half as much again, sells it and comes back.
\ Its fuel it buys from the House at the exchanges. Copies compete for the
\ miners' metals: the first to buy gets them.

60 constant cargo-goal   \ metals it gathers before setting off
20 constant min-load     \ ...or this many, after shopping a while
24 constant patience     \ looks at the market before it goes with less
20 constant trip-fuel    \ fuel it wants aboard before a trip
120 constant nap         \ game minutes to rest when nobody sells

\ The most it pays a unit: the Mars Exchange's price less 5 (the Miner asks
\ 25, 37 and 50 at the Moon)
: max-price ( metal -- price )
  dup metals-a1 = if drop 25 exit then metals-a2 = if 40 else 55 then ;

: metals ( -- n ) metals-a1 units metals-a2 units + metals-a3 units + ;

\ Credits above cash-floor, at most 32,000 (credits are a double). It
\ keeps cash-floor for fuel: round 25's traders spent every credit on
\ metals, couldn't buy fuel at the Moon, and died there (#380)
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

\ Top up the tank from the House here
: fuel-up ( -- )
  fuel units trip-fuel < if trip-fuel fuel buy-from-station drop
  120 wait drop then ;

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

\ Sell at most 15 of each metal an hour: the more the Exchange's station
\ drone holds, the less it pays. What is left after 6 hours goes on the
\ next trip
: portion ( metal -- )
  dup units 15 min ?dup if swap sell-best else drop then ;
variable rounds

\ Look for sellers; rest a while when nobody sells. Each look counts
\ toward going with a smaller load, so a trader that buys a little at a
\ time still sets off. Shopping burns fuel too (computing): keep the tank
\ up from the House here
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

: haul ( -- ) fuel-up mars go drop sell-metals fuel-up moon go drop 0 looks ! ;

\ A full load, or a smaller one after waiting long enough, or whatever it
\ has once its money is spent
: ready? ( -- f )
  metals cargo-goal < 0=
  metals min-load < 0= looks @ patience < 0= and or
  metals 0> spare 20 < and or ;

\ Every 8th round, send a line home. What a drone prints reaches you only
\ when its script ends or calls report, and launched never ends
variable tells
: tell ( -- )
  1 tells +!  tells @ 8 mod if exit then
  ." credits " credits d. ." fuel " fuel units . report ;

: work ( -- ) ready? if haul else shop then tell ;

: launched ( -- ) unpack drop moon go drop begin work again ;
