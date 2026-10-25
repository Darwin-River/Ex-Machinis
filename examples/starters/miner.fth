\ Miner: a starter script for Public Forge (#324 step 15).
\ It mines kunspar, refines it and sells the metals to the House. Copies
\ spread out: a third work the Moon and sell at the Moon Depot; the rest
\ work Phobos and Deimos (Mars's moons), moving between them as deposits run
\ low, and sell at the Mars Exchange, which pays half as much again. At the
\ Moon it first offers its metals to other drones (traders, who haul them to
\ Mars) a little over the Depot's price. For fuel it buys from another
\ drone's sell order when one is cheap, and otherwise brews its own from
\ yalspar and vladnium. Change the numbers to make it your own.

25 constant low-fuel     \ get fuel when below this
4 constant fuel-price    \ most it pays a unit for fuel
240 constant rest        \ game minutes to rest when both Mars moons are dry
16 constant hold-cap     \ at the Moon, metals it keeps on offer to traders

\ The site it works: 0 the Moon, 1 Phobos, 2 Deimos. A third of the copies
\ (by drone number) work the Moon; the rest make the long trip to Mars (about
\ 10 game days) once, then work Phobos and Deimos, which are close together.
variable site#   variable misses
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
    over deposit 5 < if move-on else over mine drop 0 misses ! then
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

\ At the Moon, standing sell orders offer its metals to traders at 5 over
\ the Depot's price (25, 35 and 45); orders stay open as the hold changes
: offer ( -- )
  25 0 metals-a1 sell-order drop  35 0 metals-a2 sell-order drop
  45 0 metals-a3 sell-order drop ;

\ Sell the metals at the market, top up the tank from the House there (5 a
\ unit, delivered when its batch clears), then go back to the site. At the
\ Moon, only once hold-cap are aboard: until then traders may buy them
: sell-metals ( -- )
  site# @ 0= if metals hold-cap < if exit then then
  market go drop
  metals-a1 sell-all metals-a2 sell-all metals-a3 sell-all
  fuel units 50 < if 30 fuel buy-from-station drop then
  site go drop ;

: work ( -- )
  fuel units low-fuel < if refuel then
  kunspar 20 dig kunspar refine drop sell-metals ;

: launched ( -- )
  unpack drop me 3 mod site# ! site go drop
  site# @ 0= if offer then begin work again ;
