\ Refiner: a starter script for Public Forge (#324 step 15).
\ It flies to the Moon and brews fuel: it mines yalspar (for organics) and
\ vladnium (for inorganics), refines them and makes fuel from the two. It
\ keeps a reserve and sells the rest to other drones on the Moon through a
\ standing sell order, cheaper than hauling fuel from Earth. The metals
\ that refining gives too go to the Moon Depot.

30 constant reserve      \ fuel it keeps for itself
4 constant fuel-price    \ what it asks a unit
90 constant fuel-cap     \ stops brewing at this much (the kit fills most of the hold)

: dig ( mineral n -- )
  begin over units over < while
    over deposit 5 < if 60 wait drop else over mine drop then
  repeat 2drop ;

: brew-fuel ( -- )
  yalspar 16 dig yalspar refine drop
  vladnium 12 dig vladnium refine drop
  fuel make drop ;

: sell-metals ( -- ) metals-a1 sell-all metals-a2 sell-all metals-a3 sell-all ;

: work ( -- )
  fuel units fuel-cap < if brew-fuel sell-metals else 120 wait drop then ;

: launched ( -- )
  unpack drop moon travel drop
  fuel-price reserve fuel sell-order drop   \ buyers pull what's above the reserve
  begin work again ;
