\ Chemist: a company design for Public Forge (economy round 1).
\ It works the Moon's yalspar and vladnium, which the starters hardly touch
\ while kunspar runs dry. It refines them, makes polymers from the organics
\ and inorganics, and sells metals, polymers and spare inorganics to the
\ Moon Depot. When low on fuel it brews its own from the same minerals.

2051 constant polymers
40 constant low-fuel     \ brew fuel below this

\ Mine until n units of a mineral are aboard; wait while its deposit refills
: dig ( mineral n -- )
  begin over units over < while
    over deposit 5 < if 60 wait drop else over mine drop then
  repeat 2drop ;

\ One round: 16 yalspar and 12 vladnium give 3 Metals (A1), 1 Metals (A2),
\ 1 Organics (A1), 2 Inorganics (A1) and 1 Inorganics (A2)
: harvest ( -- )
  yalspar 16 dig yalspar refine drop
  vladnium 12 dig vladnium refine drop ;

: sell-goods ( -- )
  metals-a1 sell-all metals-a2 sell-all
  polymers sell-all inorganics-a1 sell-all ;

\ Fuel takes 1 organics and 2 inorganics; a polymer 1 of each
: work ( -- )
  harvest
  fuel units low-fuel < if fuel make drop else polymers make drop then
  sell-goods ;

: launched ( -- ) unpack drop moon go drop begin work again ;
