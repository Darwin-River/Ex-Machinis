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

\ Growth (#380): with 7,000 credits or more, at an exchange, buy a Mining
\ Cruiser kit and a Starter Kit I, launch a clone of this drone (it runs
\ this same program), and hand it the starter kit and 40 fuel
6001 constant cruiser-kit   5901 constant starter-kit
: get-one ( resource -- )
  dup units if drop exit then 1 swap buy-from-station drop ;
\ Hand the newest clone a starter kit and 40 fuel
: equip-clone ( -- )
  670 ask ?dup 0= if exit then
  1- 4 * answer + @ >r
  1 starter-kit r@ push drop  40 fuel r> push drop ;
\ One kit at a time: equipment fills 364 of the 500 cargo
: reinvest ( -- )
  starter-kit units if equip-clone exit then  \ a kit left from last time
  credits 7000 0 d< if exit then
  fuel units 90 < if 90 fuel units - fuel buy-from-station drop then
  cruiser-kit get-one  120 wait drop
  cruiser-kit units 0= fuel units 80 < or if exit then
  me cruiser-kit launch if exit then
  starter-kit get-one  120 wait drop
  starter-kit units if equip-clone then ;
\ A clone starts with no equipment: wait for the starter kit
: await-kit ( -- ) begin starter-kit units 0= while 60 wait drop repeat ;

: sell-goods ( -- )
  market go drop
  metals-a1 sell-all metals-a2 sell-all
  polymers sell-all inorganics-a1 sell-all inorganics-a2 sell-all
  reinvest site go drop ;

\ Fuel takes 1 organics and 2 inorganics; a polymer 1 of each
: work ( -- )
  harvest
  fuel units low-fuel < if fuel make drop else polymers make drop then
  sell-goods ;

: launched ( -- )
  await-kit unpack drop me 3 mod site# ! site go drop begin work again ;
