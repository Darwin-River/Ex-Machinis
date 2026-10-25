\ Miner: a starter script for Public Forge (#324 step 15).
\ It flies to the Moon, mines kunspar, refines it and sells the metals to
\ the Moon Depot (the House station there). For fuel it buys from another
\ drone's sell order on the Moon when one is cheap, and otherwise brews its
\ own from yalspar and vladnium. When a deposit runs low it waits for it
\ to refill. Change the numbers to make it your own.

25 constant low-fuel     \ get fuel when below this
4 constant fuel-price    \ most it pays a unit for fuel

\ Mine until n units of a mineral are aboard, waiting while its deposit
\ refills
: dig ( mineral n -- )
  begin over units over < while
    over deposit 5 < if 60 wait drop else over mine drop then
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

: sell-metals ( -- ) metals-a1 sell-all metals-a2 sell-all metals-a3 sell-all ;

: work ( -- )
  fuel units low-fuel < if refuel then
  kunspar 20 dig kunspar refine drop sell-metals ;

: launched ( -- ) unpack drop moon travel drop begin work again ;
