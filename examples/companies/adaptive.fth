\ Adaptive: a market-aware company design for Public Forge (#380), written
\ as a state machine. Release it with the Factory Kit. The players' manual
\ walks through it (Chapter 9, "Market-aware drones").
\ It turns Metals (A1) into rods at the Moon, like the Smith, but it reads
\ the market every round and decides what to do:
\ - it buys metals only while two of them cost well under what a rod
\   fetched at Earth yesterday (the House's price board), and from whoever
\   sells them cheapest, a miner or the House;
\ - it sells alloys at the Moon instead of making rods when the Moon pays
\   nearly as much for an alloy as Earth pays for a rod;
\ - when nothing pays, it rests, and after a while it sells what it has.
\ A state machine keeps one mode at a time (shopping, making, hauling,
\ resting). Each round it does that mode's work, and the market decides
\ which mode comes next.

2021 constant alloys-a1   3011 constant rods
300 constant cash-floor  \ credits it keeps (for fuel)
12 constant rod-load     \ rods it takes to Earth (4 cargo each)
40 constant low-fuel     \ buy fuel below this
40 constant overheads    \ fuel and trip costs per rod, roughly

\ The modes. The game words become, in?, tick and ticks keep track of them
0 constant shopping   1 constant making   2 constant hauling   3 constant resting

\ --- Reading the market --------------------------------------------------
\ Buy up to n of a resource here where it is cheapest: another drone's sell
\ order (best-offer) or the House station (house-price, side 2: what it
\ asks). The House delivers when its batch clears, within the game hour
: buy-cheapest ( n resource -- )
  >r  r@ 2 house-price  r@ best-offer         ( n house price drone avail )
  dup 0> 3 pick 0> and  3 pick 5 pick > 0=  5 pick 0= or  and if
    rot drop rot drop rot min  r> rot pull drop exit
  then
  2drop drop  if r> buy-from-station drop else r> 2drop then ;

\ Credits above cash-floor, at most 32,000 (credits are a double)
: spare ( -- n )
  credits cash-floor 0 d- dup 0< if 2drop 0 exit then
  if drop 32000 exit then  dup 0< if drop 32000 then ;

\ What the House paid a unit for a resource at a place over the last game
\ day (query 681, the House's price board: place, units, credits,
\ batches); 0 when it bought none there. Query 680, the players' board,
\ has the same layout
: board-price ( resource place -- price )
  swap 681 ask 0 max begin dup while 1- dup 12 * answer +
    dup @ 3 pick = if
      dup 6 + 2@ drop swap 2 + 2@ drop  ?dup if / else drop 0 then
      nip nip exit
    then drop
  repeat 2drop 0 ;

\ What a rod fetched at Earth over the last game day, or the listed 225
\ when the Earth Exchange bought none
: rod-price ( -- price ) rods earth board-price ?dup 0= if 225 then ;

\ Worth buying metals? Two make a rod: only while two, plus overheads, cost
\ less than a rod fetches
: worth-buying? ( -- f )
  metals-a1 buy-price ?dup if 2 * overheads + rod-price < else 0 then ;

\ Does a rod pay more than selling its alloy at the Moon (sell-price: the
\ best of the House and other drones' buy orders here)?
: rods-pay? ( -- f ) rod-price  alloys-a1 sell-price overheads +  > ;

\ --- The modes -----------------------------------------------------------
: shop ( -- )
  fuel units low-fuel < if 30 fuel buy-cheapest 60 wait drop then
  worth-buying? 0= if resting become exit then
  20 spare metals-a1 buy-price / min ?dup if
    metals-a1 buy-cheapest 60 wait drop
  then
  metals-a1 units 1 > if making become else resting become then ;

\ One alloy a round, then a rod or a sale, so the market is read again
\ before the next one
: make-one ( -- )
  metals-a1 units 1 > fuel units 10 > and if
    alloys-a1 make drop
    rods-pay? if rods make drop else alloys-a1 sell-all then
    exit
  then
  rods units rod-load < 0= if hauling become exit then
  shopping become ;

\ Sell at most 4 rods an hour, for up to 6 hours: a fuller batch pays less
: sell-rods ( -- )
  6 begin dup 0> rods units 0> and while
    rods units 4 min rods sell-to-station drop 60 wait drop 1-
  repeat drop ;

: haul ( -- )
  earth go drop
  fuel units 60 < if 30 fuel buy-from-station drop 120 wait drop then
  sell-rods  moon go drop  shopping become ;

\ Nothing pays: wait two game hours, then look again. After six rests,
\ sell what is aboard rather than wait for ever
: rest ( -- )
  120 wait drop
  rods units 0> ticks 5 > and if hauling become exit then
  worth-buying? if shopping become then ;

\ --- The machine ---------------------------------------------------------
: step ( -- )
  shopping in? if shop exit then
  making in? if make-one exit then
  hauling in? if haul exit then
  rest ;

\ Every 8th round, send a line home: the mode and what it sees
: tell ( -- )
  ticks 8 mod if exit then
  ." mode " mode @ . ." credits " credits d. ." rod price " rod-price .
  ." metal " metals-a1 buy-price . report ;

: launched ( -- )
  unpack drop moon go drop
  shopping become  begin step tick tell again ;
