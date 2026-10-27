\ Chemist: a company design for Public Forge (economy rounds 1, 4 and 5).
\ It works yalspar and vladnium, which the starters hardly touch while
\ kunspar runs dry. It refines them, makes polymers from the organics and
\ inorganics, and sells metals, polymers and spare inorganics to the House.
\ Copies spread out: a third work the Moon (selling to the Moon Depot), the
\ rest Phobos and Deimos (selling at the Mars Exchange, which pays more for
\ metals). When low on fuel it brews its own from the same minerals.
\ It sells every by-product too, Inorganics (A2) included: a hold that
\ fills up can't brew fuel (round 3 lost drones that way). Each moon's
\ yalspar supports about 14 Chemists; more just wait for it to refill.

2051 constant polymers   2032 constant inorganics-a2
40 constant low-fuel     \ brew fuel below this

\ The site: 0 the Moon, 1 Phobos, 2 Deimos (by drone number); its market
variable site#
: site ( -- place )
  site# @ dup 0= if drop moon exit then 1 = if phobos else deimos then ;
: market ( -- place ) site moon = if moon else mars then ;

\ Mine until n units of a mineral are aboard; wait while its deposit refills
: dig ( mineral n -- )
  begin over units over < while
    over deposit 5 < if 60 wait drop else
      \ A run that brings nothing (others took it first) still burns fuel:
      \ wait as if the deposit were dry
      over units >r  over mine drop  over units r> = if 60 wait drop then
    then
  repeat 2drop ;

\ One round: 16 yalspar and 12 vladnium give 3 Metals (A1), 1 Metals (A2),
\ 1 Organics (A1), 2 Inorganics (A1) and 1 Inorganics (A2)
: harvest ( -- )
  yalspar 16 dig yalspar refine drop
  vladnium 12 dig vladnium refine drop ;

: sell-goods ( -- )
  market go drop
  metals-a1 sell-all metals-a2 sell-all
  polymers sell-all inorganics-a1 sell-all inorganics-a2 sell-all
  site go drop ;

\ Fuel takes 1 organics and 2 inorganics; a polymer 1 of each
: work ( -- )
  harvest
  fuel units low-fuel < if fuel make drop else polymers make drop then
  sell-goods ;

: launched ( -- )
  unpack drop me 3 mod site# ! site go drop begin work again ;
