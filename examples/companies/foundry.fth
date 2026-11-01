\ Foundry: a company design for Public Forge, from newcomer Ines's first company in economy round 29 (#380).
\ Release it with the Factory Kit. In the starter-library race (60 starters
\ and the library designs, 40 game days) it gained 9,516 over its starter
\ package, against 6,425 for the Machinist, the best design before it.

\ Ines Foundry v1: a market-aware factory (Factory Kit), based at the Moon.
\ Every round it prices every recipe it knows from the live order book here
\ (what the inputs cost to buy, what the product fetches at the best bid),
\ picks the one that earns most per game hour, buys a small batch of inputs,
\ makes one, and sells finished goods in lots. It brews its own fuel when
\ that is cheaper than buying it, and launches clones with spare cash.

2021 constant alloys-a1  2022 constant alloys-a2  2023 constant alloys-a3
2051 constant polymers   3011 constant rods       3101 constant springs
3111 constant gears      4111 constant gear-boxes 6001 constant cruiser-kit
2033 constant inorganics-a3  2043 constant organics-a3
300 constant cash-floor
20 constant min-gain     \ profit per 10 game hours below which it rests

\ --- recipes: product out fuel hours a1n a2n in1 q1 in2 q2 in3 q3 (24 bytes)
create rec
2051 , 1 , 2 , 2 , 0 , 0 , 2031 , 1 , 2041 , 1 , 0 , 0 ,
2021 , 1 , 2 , 2 , 0 , 0 , 2011 , 2 , 0 , 0 , 0 , 0 ,
2022 , 1 , 2 , 2 , 0 , 0 , 2011 , 1 , 2012 , 1 , 0 , 0 ,
2023 , 2 , 2 , 2 , 0 , 0 , 2011 , 1 , 2012 , 1 , 2013 , 1 ,
2052 , 2 , 2 , 2 , 0 , 0 , 2031 , 1 , 2041 , 1 , 2042 , 1 ,
2053 , 2 , 2 , 2 , 0 , 0 , 2031 , 1 , 2042 , 1 , 2043 , 1 ,
2042 , 2 , 2 , 2 , 0 , 0 , 2032 , 1 , 2041 , 2 , 0 , 0 ,
2043 , 2 , 2 , 2 , 0 , 0 , 2033 , 1 , 2042 , 2 , 0 , 0 ,
\ parts: a1n Alloys (A1) and a2n Alloys (A2), then the part
3011 , 1 , 5 , 6 , 1 , 0 , 2011 , 2 , 0 , 0 , 0 , 0 ,
3001 , 1 , 7 , 8 , 2 , 0 , 2011 , 4 , 0 , 0 , 0 , 0 ,
3201 , 1 , 7 , 8 , 2 , 0 , 2011 , 4 , 0 , 0 , 0 , 0 ,
3021 , 1 , 7 , 8 , 2 , 0 , 2011 , 4 , 0 , 0 , 0 , 0 ,
3111 , 1 , 7 , 8 , 2 , 0 , 2011 , 4 , 0 , 0 , 0 , 0 ,
3121 , 1 , 7 , 8 , 2 , 0 , 2011 , 4 , 0 , 0 , 0 , 0 ,
3311 , 1 , 9 , 10 , 3 , 0 , 2011 , 6 , 0 , 0 , 0 , 0 ,
3211 , 1 , 5 , 6 , 0 , 1 , 2011 , 1 , 2012 , 1 , 0 , 0 ,
3101 , 1 , 7 , 8 , 0 , 2 , 2011 , 2 , 2012 , 2 , 0 , 0 ,
3031 , 1 , 7 , 8 , 0 , 2 , 2011 , 2 , 2012 , 2 , 0 , 0 ,
3331 , 1 , 11 , 12 , 1 , 3 , 2011 , 5 , 2012 , 3 , 0 , 0 ,
\ the gear box (last): rod, gear and spring
4111 , 1 , 23 , 30 , 0 , 0 , 2011 , 8 , 2012 , 2 , 0 , 0 ,
20 constant nrec   19 constant gb#   8 constant parts#

: rec-addr ( i -- a ) 24 * rec + ;
: r-prod ( i -- p ) rec-addr @ ;
: r-out ( i -- n ) rec-addr 2 + @ ;
: r-fuel ( i -- n ) rec-addr 4 + @ ;
: r-hours ( i -- n ) rec-addr 6 + @ ;
: r-a1n ( i -- n ) rec-addr 8 + @ ;
: r-a2n ( i -- n ) rec-addr 10 + @ ;
: in-res ( i k -- res ) 4 * 12 + swap rec-addr + @ ;
: in-qty ( i k -- q ) 4 * 14 + swap rec-addr + @ ;

\ --- the hold, read once a round (query 210: resource, units) ------------
create hbuf 160 allot  variable hold#
: snap ( -- ) me hbuf 160 210 query 0 max hold# ! ;
: have ( res -- n )
  hold# @ begin dup while 1- dup 4 * hbuf + dup @ 3 pick = if
    2 + @ nip nip exit then drop
  repeat 2drop 0 ;

\ --- money ----------------------------------------------------------------
: spare ( -- n )
  credits cash-floor 0 d- dup 0< if 2drop 0 exit then
  if drop 32000 exit then  dup 0< if drop 32000 then ;

\ --- pricing --------------------------------------------------------------
\ prices are read once a round into two tables (each query costs 1,000
\ instructions, and a drone pays fuel for thinking): what each input costs
\ here, and the best bid here for each product (parts every 4th round)
variable rounds
create inp 18 allot   create bids 40 allot
: slot ( res -- n )
  dup 2040 > if 2041 - 6 + exit then dup 2030 > if 2031 - 3 + exit then 2011 - ;
: ip ( res -- price ) slot 2* inp + @ ;
: bid ( i -- a ) 2* bids + ;
: refresh ( -- )
  2011 buy-price 0 inp + !  2012 buy-price 2 inp + !  2013 buy-price 4 inp + !
  2031 buy-price 6 inp + !  2032 buy-price 8 inp + !  2033 buy-price 10 inp + !
  2041 buy-price 12 inp + ! 2042 buy-price 14 inp + ! 2043 buy-price 16 inp + !
  nrec 1- for
    r@ parts# < rounds @ 4 mod 1 = or if r@ r-prod sell-price r@ bid ! then
  next ;

variable acc  variable bad
: add-in ( i k -- )
  2dup in-qty >r in-res r> swap  ( q res )
  dup 0= if 2drop exit then
  ip dup 0= if 2drop 1 bad ! exit then
  * acc +! ;
\ inputs for one batch: acc = cost, bad = 1 if one can't be bought here
: in-cost ( i -- ) 0 acc ! 0 bad !  dup 0 add-in dup 1 add-in 2 add-in ;

\ profit per 10 game hours of making recipe i here, now; -1 if impossible
: gain ( i -- n )
  dup bid @ ?dup 0= if drop -1 exit then
  over r-out * 95 100 */          ( i revenue )
  over in-cost bad @ if 2drop -1 exit then
  acc @ -  over r-fuel 2* -       ( i profit )
  10 rot r-hours */ ;

variable best  variable best-g
: choose ( -- )
  -1 best !  min-gain best-g !
  nrec 1- for r@ gain dup best-g @ > if best-g ! r@ best ! else drop then next ;

\ --- inputs ---------------------------------------------------------------
: enough ( i k -- f )
  2dup in-res ?dup 0= if 2drop -1 exit then have >r in-qty r> > 0= ;
: ready? ( i -- f ) dup 0 enough over 1 enough and swap 2 enough and ;

\ buy what input j of recipe i lacks for k batches
: buy-in ( i j k -- )
  >r 2dup in-res ?dup 0= if 2drop r> drop exit then
  >r in-qty r> swap r> *        ( res need )
  over have - dup 0> if swap buy-here drop else 2drop then ;
: batch ( i -- k ) in-cost acc @ 1 max spare swap / 4 min ;
: stock ( i -- )
  dup batch ?dup 0= if drop exit then
  >r dup 0 r@ buy-in dup 1 r@ buy-in 2 r> buy-in snap ;

\ --- making ---------------------------------------------------------------
: n-make ( n res -- ior )
  swap begin dup 0> while over make ?dup if nip nip exit then 1- repeat
  2drop 0 ;
: make-box ( -- ior )
  3 alloys-a1 n-make ?dup if exit then
  2 alloys-a2 n-make ?dup if exit then
  rods make ?dup if exit then  gears make ?dup if exit then
  springs make ?dup if exit then  gear-boxes make ;
: make-one ( i -- ior )
  fuel units 30 < if drop -2 exit then
  dup parts# < if r-prod make exit then
  dup gb# = if drop make-box exit then
  dup r-a1n alloys-a1 n-make ?dup if nip exit then
  dup r-a2n alloys-a2 n-make ?dup if nip exit then
  r-prod make ;

\ a recipe whose inputs are already aboard and still pays
: leftover ( -- i|-1 )
  nrec 1- for r@ ready? if r@ gain 0> if r> exit then then next -1 ;

\ --- selling: everything in the recipe list, in lots of 4 or more,
\ or whatever is aboard every 12th round
: sell-goods ( -- )
  nrec 1- for
    r@ r-prod dup have ( res n )
    dup 3 > rounds @ 12 mod 0= or over 0> and
    if swap sell-here drop else 2drop then
  next ;

\ --- fuel: brew it from 1 organics and 2 inorganics when that is cheaper
: can-brew? ( -- f ) organics-a1 have 0> inorganics-a1 have 1 > and ;
\ buying the inputs and brewing is cheaper than buying 30 fuel?
: brew-pays? ( -- f )
  organics-a1 buy-price inorganics-a1 buy-price  2dup and 0= if 2drop 0 exit then
  2* +  fuel buy-price ?dup 0= if drop -1 exit then 30 * < ;
: refuel ( -- )
  fuel units 50 < 0= if exit then
  can-brew? 0= brew-pays? and if
    1 organics-a1 buy-here drop  2 inorganics-a1 buy-here drop snap
  then
  can-brew? if fuel make 0= if snap exit then then
  30 fuel buy-here drop snap ;

\ --- growth: copied from the Smith design, stops after day 24 ---------
: get-one ( resource -- )
  dup units if drop exit then
  dup 1 swap buy-here drop
  6 begin over units 0= over 0> and while 60 wait drop 1- repeat 2drop ;
: equip-clone ( -- )
  670 ask ?dup 0= if exit then
  1- 4 * answer + @ >r
  1 factory-kit r@ push drop  40 fuel r> push drop ;
: fill-to ( n -- )
  fuel units tuck - dup 0> if
    fuel buy-here drop
    6 begin over fuel units < 0= over 0> and while 60 wait drop 1- repeat
  then 2drop ;
: reinvest ( -- )
  factory-kit units if equip-clone 60 fill-to exit then
  cruiser-kit units 0= if
    today 24 > if exit then
    credits 7500 0 d< if exit then
    cruiser-kit get-one  cruiser-kit units 0= if exit then
  then
  90 fill-to  fuel units 80 < if exit then
  me cruiser-kit launch if exit then
  factory-kit get-one  factory-kit units if equip-clone then
  60 fill-to ;
: await-kit ( -- ) begin factory-kit units 0= while 60 wait drop repeat ;

: tell ( -- )
  rounds @ 6 mod if exit then
  ." day " today . ." credits " credits d. ." fuel " fuel units .
  ." best " best @ dup 0< if . else r-prod . then ." gain " best-g @ .
  report ;

: round ( -- )
  1 rounds +!  snap refuel sell-goods refresh
  leftover dup 0< 0= if make-one drop exit then drop
  choose tell
  best @ 0< if 120 wait drop exit then
  best @ ready? 0= if best @ stock then
  best @ ready? if best @ make-one drop else 60 wait drop then
  reinvest ;

: launched ( -- ) await-kit unpack drop moon go drop begin round again ;
