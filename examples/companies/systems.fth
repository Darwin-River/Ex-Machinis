\ Systems shop: a company design for Public Forge, from newcomer Farid's second company in economy round 33 (#380).
\ Release it with the Factory Kit. In the starter-library race (60 starters
\ and the library designs, 40 game days) it gained 14,380 over its starter
\ package, against 6,425 for the Machinist, the best design before it.

\ Farid 2: a systems shop (Factory Kit): pumps, pistons and valves.
\ Market study: every exchange bids 712-757 for them and nobody makes
\ them. A pump or a piston is a rod and a hose: 2 Metals (A1), 3
\ Organics (A1) and 3 Inorganics (A1), about 150 credits of goods at the
\ exchange's prices. A valve is a rod and a pipe: 8 Metals (A1).
\ Each round it prices the three from the live market where it is, builds
\ one of the best, sells it and looks again. It brews its own fuel from
\ the same organics and inorganics.

2021 constant alloys-a1  2051 constant polys
3011 constant rodz  3301 constant hoses  3311 constant pipes
4311 constant pumps  4321 constant pistons  4301 constant valves
300 constant cash-floor   \ credits it keeps
50 constant low-fuel      \ brew or buy fuel below this
60 constant min-score     \ least profit per system worth building
2 constant batch          \ systems' worth of goods it buys at a time

: spare ( -- n )
  credits cash-floor 0 d- dup 0< if 2drop 0 exit then
  if drop 32000 exit then  dup 0< if drop 32000 then ;
\ No seller: price it out, but small enough that 8 of them fit in a cell
\ (8 x 5000 wrapped negative and made a product with no inputs look best;
\ found by Kofi, round 34)
: cost ( res -- price ) buy-price ?dup 0= if 999 then ;
variable c-met  variable c-org  variable c-ino
: read-costs ( -- )
  metals-a1 cost c-met !  organics-a1 cost c-org !  inorganics-a1 cost c-ino ! ;
: net ( product -- n ) sell-price 95 100 */ ;

\ Profit per system, from the live prices here
: hose-cost ( -- n ) c-met @ 2*  c-org @ 3 * +  c-ino @ 3 * + ;
: pump-score ( -- n ) pumps net hose-cost - ;
: piston-score ( -- n ) pistons net hose-cost - ;
: valve-score ( -- n ) valves net c-met @ 8 * - ;

variable best  variable best-score
: consider ( score product -- )
  over best-score @ > if best ! best-score ! else 2drop then ;
: choose ( -- product|0 )
  read-costs  0 best !  min-score best-score !
  pump-score pumps consider  piston-score pistons consider
  valve-score valves consider  best @ ;

\ Goods one system needs: metals, organics, inorganics
variable n1  variable n2  variable n3
: needs ( product -- )
  valves = if 8 0 0 else 2 3 3 then  n3 ! n2 ! n1 ! ;

\ With fewer than per of a good aboard, buy up to batch systems' worth
: stock ( per res -- )
  over 0= if 2drop exit then
  2dup units > 0= if 2drop exit then
  >r batch * r@ units -  spare r@ cost / min
  dup 0> if r@ buy-here drop else drop then r> drop ;
: have? ( -- f )
  metals-a1 units n1 @ < 0=  organics-a1 units n2 @ < 0= and
  inorganics-a1 units n3 @ < 0= and ;

\ Make something until n are aboard; false if a step fails
: ensure ( n res -- f )
  begin 2dup units > while dup make if 2drop 0 exit then repeat 2drop -1 ;
: rod-ok ( -- f ) rodz units if -1 exit then 1 alloys-a1 ensure 0= if 0 exit then rodz make 0= ;
: hose-ok ( -- f ) hoses units if -1 exit then 3 polys ensure 0= if 0 exit then hoses make 0= ;
: pipe-ok ( -- f ) pipes units if -1 exit then 3 alloys-a1 ensure 0= if 0 exit then pipes make 0= ;
: build ( product -- )
  rod-ok 0= if drop exit then
  dup valves = if pipe-ok else hose-ok then
  0= if drop exit then  make drop ;

: sell-out ( res -- ) dup units ?dup if swap sell-here drop else drop then ;
: sell-goods ( -- ) pumps sell-out pistons sell-out valves sell-out ;

\ Fuel: brew it (1 organics + 2 inorganics make 30) when the goods can be
\ had; else buy it here under 20; else fly to the Moon, where chemists sell
: brew ( -- f )
  organics-a1 units 1 < if 2 organics-a1 buy-here drop then
  inorganics-a1 units 2 < if 3 inorganics-a1 buy-here drop then
  organics-a1 units 0> inorganics-a1 units 1 > and 0= if 0 exit then
  fuel make 0= ;
: refuel ( -- )
  fuel units low-fuel < 0= if exit then
  credits 60 0 d< 0= if brew if exit then then
  fuel buy-price dup 0> swap 20 < and if 75 fuel units - fuel buy-here drop exit then
  location moon = if 12 60 fuel ?buy-order drop 120 wait drop exit then
  fuel units 12 > if moon go drop then ;

variable idle
: other-site ( -- place ) location earth = if moon else earth then ;
: rest ( -- )
  1 idle +!
  idle @ 4 > fuel units 25 > and if 0 idle ! other-site go drop exit then
  120 wait drop ;

\ Growth: with 7,000 credits before day 24, at an exchange, buy a hull and
\ a Factory Kit, launch a copy of this drone and equip it
6001 constant cruiser-kit
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
: fuel-to ( n -- ) begin fuel units over < while brew 0= if fill-to exit then repeat drop ;
: reinvest ( -- )
  factory-kit units if equip-clone 60 fuel-to exit then
  cruiser-kit units 0= if
    today 24 < 0= if exit then
    credits 7000 0 d< if exit then
    cruiser-kit get-one  cruiser-kit units 0= if exit then
  then
  90 fuel-to  fuel units 80 < if exit then
  me cruiser-kit launch if exit then
  factory-kit get-one  factory-kit units if equip-clone then
  60 fuel-to ;
: await-kit ( -- ) begin factory-kit units 0= while 60 wait drop repeat ;

variable rounds
: tell ( product -- )
  1 rounds +!  rounds @ 3 mod if drop exit then
  ." day " today . ." at " location . ." making " . ." score " best-score @ .
  ." credits " credits d. ." fuel " fuel units . report ;

: work ( -- )
  refuel
  choose ?dup 0= if 0 tell rest exit then
  dup needs  n1 @ metals-a1 stock  n2 @ organics-a1 stock  n3 @ inorganics-a1 stock
  have? 0= if tell rest exit then
  0 idle !  dup build  sell-goods  tell  reinvest ;

: launched ( -- ) await-kit unpack drop begin work again ;
