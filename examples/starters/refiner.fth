\ Refiner: a starter script for Public Forge (#324 step 15).
\ It brews fuel: it mines yalspar (for organics) and vladnium (for
\ inorganics), refines them and makes fuel from the two. It keeps a reserve
\ and sells the rest to the drones around it through a standing sell order,
\ cheaper than hauling fuel from Earth. It works three sites, the Moon,
\ Phobos and Deimos: each copy starts at a different one (by its drone
\ number) and moves on when a deposit runs low, so copies spread out, and
\ its sell order goes with it. The metals that refining gives too go to the
\ House: the Moon Depot from the Moon, the Mars Exchange from Mars's moons.

30 constant reserve      \ fuel it keeps for itself
4 constant fuel-price    \ what it asks a unit
90 constant fuel-cap     \ stops brewing at this much (the kit fills most of the hold)
240 constant rest        \ game minutes to wait when every site is mined out

\ The site it works: 0 the Moon, 1 Phobos, 2 Deimos
variable site#   variable misses
: site ( -- place )
  site# @ 3 mod dup 0= if drop moon exit then 1 = if phobos else deimos then ;
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

: brew-fuel ( -- )
  yalspar 16 dig yalspar refine drop
  vladnium 12 dig vladnium refine drop
  fuel make drop ;

\ Sell the metals at the market, then go back to the site
: sell-metals ( -- )
  market go drop
  metals-a1 sell-all metals-a2 sell-all metals-a3 sell-all
  site go drop ;

: work ( -- )
  fuel units fuel-cap < if brew-fuel sell-metals else 120 wait drop then ;

: launched ( -- )
  unpack drop me 3 mod site# ! site go drop
  fuel-price reserve fuel sell-order drop   \ buyers pull what's above the reserve
  begin work again ;
