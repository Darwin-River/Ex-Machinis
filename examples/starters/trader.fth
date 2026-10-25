\ Trader: a starter script for Public Forge (#324 step 15).
\ It gathers metals on the Moon and hauls them to Mars, where the House
\ (the Mars Exchange) pays half as much again as at the Moon. It buys
\ metals from other drones' sell orders on the Moon when they are cheaper
\ than the Mars price, and otherwise mines and refines kunspar itself. It
\ brews the fuel for the trip from yalspar and vladnium.

40 constant cargo-goal   \ metals it gathers before setting off
60 constant trip-fuel    \ fuel it wants aboard to go there and back
30 constant max-price    \ most it pays a unit for metals on the Moon

: dig ( mineral n -- )
  begin over units over < while
    over deposit 5 < if 60 wait drop else over mine drop then
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

: haul ( -- ) mars travel drop sell-metals 60 wait drop moon travel drop ;

: work ( -- )
  fuel units trip-fuel < if brew-fuel exit then
  metals cargo-goal < if gather exit then
  haul ;

: launched ( -- ) unpack drop moon travel drop begin work again ;
