\ Miner: a starter script for Public Forge (#324 step 15).
\ It mines kunspar, refines it and sells the metals to the House. It works
\ three sites: the Moon, and Phobos and Deimos (Mars's moons). Each copy
\ starts at a different one (by its drone number), and moves on to the next
\ when a deposit runs low, so copies spread out instead of crowding one
\ place. It sells at the Moon Depot from the Moon, and at the Mars Exchange
\ (which pays half as much again) from Phobos and Deimos. For fuel it buys
\ from another drone's sell order when one is cheap, and otherwise brews
\ its own from yalspar and vladnium. Change the numbers to make it your own.

25 constant low-fuel     \ get fuel when below this
4 constant fuel-price    \ most it pays a unit for fuel
240 constant rest        \ game minutes to wait when every site is mined out

\ The site it works: 0 the Moon, 1 Phobos, 2 Deimos
variable site#   variable misses
: site ( -- place )
  site# @ 3 mod dup 0= if drop moon exit then 1 = if phobos else deimos then ;
\ Where it sells: the Moon Depot at the Moon, else the Mars Exchange
: market ( -- place ) site moon = if moon else mars then ;

\ On to the next site; after a whole round with nothing to mine, rest
: move-on ( -- )
  1 site# +! 1 misses +!
  misses @ 3 mod 0= if rest wait drop then
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

\ Sell the metals at the market, then go back to the site
: sell-metals ( -- )
  market go drop
  metals-a1 sell-all metals-a2 sell-all metals-a3 sell-all
  site go drop ;

: work ( -- )
  fuel units low-fuel < if refuel then
  kunspar 20 dig kunspar refine drop sell-metals ;

: launched ( -- ) unpack drop me 3 mod site# ! site go drop begin work again ;
