\ Adaptive: a market-aware company design for Public Forge (#380), written
\ as a state machine. Release it with the Factory Kit. The players' manual
\ walks through it (Chapter 9, "Market-aware drones").
\ It turns Metals (A1) into rods at the Moon, like the Smith, but it reads
\ the market every round and decides what to do:
\ - it buys metals only while two of them cost well under what a rod
\   fetched at Earth yesterday (the price board, query 680), and from
\   whoever sells them cheapest;
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
\ Buy up to n of a resource here, cheapest first: buy-here pulls from the
\ cheapest sell orders here (any drone's, the station drone's too),
\ one after another until it has n or nobody sells more
: buy-cheapest ( n resource -- ) buy-here drop ;

\ Sell up to n of a resource here, best price first: sell-here pushes into
\ the best buy orders here (any drone's, the station drone's too)
: sell-best ( n resource -- ) sell-here drop ;

\ Sell all of a resource aboard, the same way
: sell-out ( resource -- ) dup units ?dup if swap sell-best else drop then ;

\ Credits above cash-floor, at most 32,000 (credits are a double)
: spare ( -- n )
  credits cash-floor 0 d- dup 0< if 2drop 0 exit then
  if drop 32000 exit then  dup 0< if drop 32000 then ;

\ What a resource traded for a unit at a place over the last game day,
\ all trades (query 680, the price board: place, units, credits, trades;
\ 12 bytes a row); 0 when none traded there
: board-price ( resource place -- price )
  swap 680 ask 0 max begin dup while 1- dup 12 * answer +
    dup @ 3 pick = if
      dup 6 + 2@ drop swap 2 + 2@ drop  ?dup if / else drop 0 then
      nip nip exit
    then drop
  repeat 2drop 0 ;

\ What a rod fetched at Earth over the last game day, or a guess of 225
\ when none traded there
: rod-price ( -- price ) rods earth board-price ?dup 0= if 225 then ;

\ Worth buying metals? Two make a rod: only while two, plus overheads, cost
\ less than a rod fetches
: worth-buying? ( -- f )
  metals-a1 buy-price ?dup if 2 * overheads + rod-price < else 0 then ;

\ Does a rod pay more than selling its alloy at the Moon (sell-price: the
\ best buy order here, whoever placed it)?
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
    rods-pay? if rods make drop else alloys-a1 sell-out then
    exit
  then
  rods units rod-load < 0= if hauling become exit then
  shopping become ;

\ Sell at most 4 rods an hour, for up to 6 hours: buyers here tend to
\ bid less as they stock up, and bid again later
: sell-rods ( -- )
  6 begin dup 0> rods units 0> and while
    rods units 4 min rods sell-best 60 wait drop 1-
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
  ." mode " mode @ . ."  credits " credits d. ." rod price " rod-price .
  ."  metal " metals-a1 buy-price . report ;

: launched ( -- )
  unpack drop moon go drop
  shopping become  begin step tick tell again ;
