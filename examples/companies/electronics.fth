\ Electronics: a company design for Public Forge, from newcomer Elif's second company in economy round 33 (#380).
\ Release it with the Factory Kit. In the starter-library race (60 starters
\ and the library designs, 40 game days) it gained 9,524 over its starter
\ package, against 6,425 for the Machinist, the best design before it.

\ Elif 2: Moon electronics factory (transceivers, solenoids, computers). Readable source: notes.
2021 constant al1   2022 constant al2   2023 constant al3
3011 constant rods   3201 constant conds   3211 constant semis   3221 constant supers
4241 constant xcvrs  4271 constant solens  4261 constant comps
500 constant cash-floor
350 constant min-sale
4 constant max-stock
variable product   variable nap   30 nap !
: rich? credits cash-floor 0 d< 0= ;
: bid
  >r r@ sell-price 1+ rot min 1 max swap r> ?buy-order drop ;
: sync-bids
  rich? if 34 4 metals-a2 bid  46 2 metals-a3 bid exit then
  1 0 metals-a2 ?buy-order drop  1 0 metals-a3 ?buy-order drop ;
: buy-under
  rich? 0= if drop 2drop exit then
  over buy-price dup 0= if 2drop 2drop exit then
  < if 2drop exit then  buy-here drop ;
: top-to >r swap over units - dup 0> if swap r> buy-under else 2drop r> drop then ;
: fuel-step
  fuel units 45 < 0= if 0 exit then
  organics-a1 units 0> inorganics-a1 units 1 > and if fuel make drop -1 exit then
  40 fuel 9 buy-under 0 ;
: needs
  product @ xcvrs = if 1 1 1 0 exit then
  product @ solens = if 1 2 0 0 exit then
  0 1 2 1 ;
: score >r swap 95 100 */ swap - 10 r> */ ;
variable best
: consider over best @ > if product ! best ! else 2drop then ;
: choose
  -32000 best !
  xcvrs sell-price 271 28 score xcvrs consider
  solens sell-price 312 30 score solens consider
  comps sell-price 402 34 score comps consider ;
: stock xcvrs units solens units + comps units + ;
: sell-one
  dup units 0= if drop exit then
  dup sell-price min-sale < if drop exit then
  dup units swap sell-here drop ;
: sell-step xcvrs sell-one solens sell-one comps sell-one ;
: try make 0= ;
: mk-al1 metals-a1 units 1 > if al1 try else 0 then ;
: mk-al2 metals-a1 units 0> metals-a2 units 0> and if al2 try else 0 then ;
: mk-al3
  metals-a1 units 0> metals-a2 units 0> and metals-a3 units 0> and
  if al3 try else 0 then ;
: mk-rod al1 units 0> if rods try exit then mk-al1 ;
: mk-cond al1 units 1 > if conds try exit then mk-al1 ;
: mk-semi al2 units 0> if semis try exit then mk-al2 ;
: mk-super al3 units 1 > if supers try exit then mk-al3 ;
: make-step
  needs                              
  supers units > if 2drop drop mk-super exit then
  semis units > if 2drop mk-semi exit then
  conds units > if drop mk-cond exit then
  rods units > if mk-rod exit then
  product @ try ;
: shop
  product @ xcvrs = if 7 metals-a1 32 top-to 1 metals-a2 40 top-to exit then
  product @ solens = if 10 metals-a1 32 top-to exit then
  7 metals-a1 32 top-to 3 metals-a2 40 top-to 1 metals-a3 50 top-to ;
: wip
  rods units conds units + semis units + supers units +
  al1 units + al2 units + al3 units + ;
6001 constant cruiser-kit
: get-one
  dup units if drop exit then
  dup 1 swap buy-here drop
  6 begin over units 0= over 0> and while 60 wait drop 1- repeat 2drop ;
: equip-clone
  670 ask ?dup 0= if exit then
  1- 4 * answer + @ >r
  1 factory-kit r@ push drop  40 fuel r> push drop ;
: reinvest
  factory-kit units if equip-clone exit then
  today 24 > if exit then
  cruiser-kit units 0= if
    credits 6500 0 d< if exit then
    cruiser-kit get-one  cruiser-kit units 0= if exit then
  then
  fuel units 110 < if 80 fuel 9 buy-under exit then
  me cruiser-kit launch if exit then
  factory-kit get-one  factory-kit units if equip-clone then ;
: await-kit begin factory-kit units 0= while 60 wait drop repeat ;
variable rounds
: tell
  1 rounds +!  rounds @ 6 mod if exit then
  ." day " today . ." cr " credits d. ." fuel " fuel units .
  ." make " product @ . ." best " best @ . ." stock " stock . ." wip " wip .
  ." m1 " metals-a1 units . ." m2 " metals-a2 units . ." m3 " metals-a3 units . report ;
: busy 30 nap ! ;
: quiet nap @ wait drop  nap @ 2* 480 min nap ! ;
: round
  sync-bids
  fuel-step if busy exit then
  sell-step
  stock max-stock < if
    make-step if busy exit then
    shop  make-step if busy exit then
  then
  reinvest quiet ;
: launched
  await-kit unpack drop  moon go drop  choose
  begin round  wip 0= if choose then  tell again ;
