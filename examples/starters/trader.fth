\ Trader: a starter script for Public Forge (#324 step 15).
\ It gathers metals and hauls them to Mars, where the House (the Mars
\ Exchange) pays half as much again as at the Moon. It buys metals from
\ other drones' sell orders where it is when they are cheaper than the Mars
\ price, and otherwise mines and refines kunspar itself. Copies spread out:
\ a third work the Moon, the rest Phobos and Deimos (Mars's moons). It brews the
\ fuel for the trip from yalspar and vladnium.

40 constant cargo-goal   \ metals it gathers before setting off
60 constant trip-fuel    \ fuel it wants aboard to go there and back
30 constant max-price    \ most it pays a unit for metals
240 constant rest        \ game minutes to rest when both Mars moons are dry

\ The site it works: 0 the Moon, 1 Phobos, 2 Deimos. A third of the copies
\ (by drone number) work the Moon; the rest make the long trip to Mars (about
\ 10 game days) once, then work Phobos and Deimos, which are close together.
variable site#   variable misses
: site ( -- place )
  site# @ dup 0= if drop moon exit then 1 = if phobos else deimos then ;

\ A low deposit: at the Moon, wait for it to refill; at Mars, try the other
\ moon, and rest after both are mined out
: move-on ( -- )
  site# @ 0= if 60 wait drop exit then
  site# @ 3 xor site# !  1 misses +!
  misses @ 2 mod 0= if rest wait drop then
  site go drop ;

: dig ( mineral n -- )
  begin over units over < while
    over deposit 5 < if move-on else over mine drop 0 misses ! then
  repeat 2drop ;

: brew-fuel ( -- )
  yalspar 16 dig yalspar refine drop
  vladnium 12 dig vladnium refine drop
  fuel make drop ;

: metals ( -- n ) metals-a1 units metals-a2 units + metals-a3 units + ;

\ Buy what another drone here sells of one metal, at max-price or less
: buy ( metal -- )
  dup 610 ask 0> 0= if drop exit then
  answer 2@ drop max-price > if drop exit then
  answer 6 + @ answer 4 + @ - ( metal n ) 0 max ?dup 0= if drop exit then
  swap answer 8 + @ pull drop ;

: gather ( -- )
  metals-a1 buy metals-a2 buy metals-a3 buy
  metals cargo-goal < if kunspar 20 dig kunspar refine drop then ;

: sell-metals ( -- ) metals-a1 sell-all metals-a2 sell-all metals-a3 sell-all ;

: haul ( -- ) mars go drop sell-metals site go drop ;

: work ( -- )
  fuel units trip-fuel < if brew-fuel exit then
  metals cargo-goal < if gather exit then
  haul ;

: launched ( -- ) unpack drop me 3 mod site# ! site go drop begin work again ;
