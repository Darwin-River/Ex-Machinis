\ The House station drone (#380): the program the House's trading station at
\ each exchange runs. It trades by the players' own rules (sell and buy
\ orders, pulls and pushes, the sales tax), from a Drop Station hull with two
\ modules no player can get: an Import Station brings goods up from the
\ planet surface (N R 5911 perform) and an Export Station sends them down
\ (N R 5921 perform). What it trades and at what prices comes from the
\ exchange's game data (query 630, the station_goods table).
\ - What the exchange buys: a buy order for up to a day's demand. Its price
\   falls from the listed price to half as the stock fills, and once a game
\   day the stock goes down to the surface (but for what it keeps to sell,
\   when the exchange sells the same good).
\ - What the exchange sells: stock brought up to a quarter of a day's
\   supply, on a sell order at the listed price.

\ The goods: query 630's rows (resource, side, price 2 cells, daily units,
\ units waiting 2 cells), 14 bytes each, by resource and then side. Every
\ exchange trades every product, about 205 rows: room for 250
create goods 3500 allot
variable #goods
: row ( i -- addr ) 14 * goods + ;
: good ( i -- resource ) row @ ;
: buys? ( i -- f ) row 2 + @ 1 = ;
: listed ( i -- price ) row 4 + 2@ drop ;
: daily ( i -- n ) row 8 + @ 1 max ;
: load-goods ( -- ) goods 3500 630 query 0 max #goods ! ;

\ The price each good's order was last posted at: an order is posted only
\ when its price changes (each posting is an action, and takes game time)
create posted 500 allot
: forget-orders ( -- )
  250 begin dup while 1- 0 over 2 * posted + ! repeat drop ;
: changed? ( price i -- f ) 2 * posted + dup @ rot tuck <> if swap ! -1 else 2drop 0 then ;

: import ( n resource -- ) over 0> if 5911 perform drop else 2drop then ;
: export ( n resource -- ) over 0> if 5921 perform drop else 2drop then ;

500 constant reserve     \ fuel it keeps for itself (orders and thinking)

\ Selling: a quarter of a day's supply in stock, at the listed price (fuel
\ keeps the reserve back)
: stock ( i -- n ) daily 4 / 1 max ;

\ The stock it keeps for selling a resource it also buys (most goods): 0
\ when it doesn't sell it. Only what is above that counts as bought, for its
\ price and for shipping down. A good's sell row follows its buy row
: sells-too? ( i -- f )
  dup 1+ #goods @ < 0= if drop 0 exit then
  dup 1+ buys? if drop 0 exit then
  dup good swap 1+ good = ;
: keep ( i -- n ) dup sells-too? if 1+ stock else drop 0 then ;
: excess ( i -- n ) dup good units swap keep - 0 max ;

\ Buying: from the listed price at no stock bought to half of it at a full
\ day's demand; the order stays open for up to a day's demand
\ (*/ keeps the product in two cells: a dear good times its units would
\ overflow one; what it bought counts up to a day's demand, so the bid
\ never falls below half)
: bid ( i -- price )
  dup listed swap dup excess over daily min swap daily 2 *
  >r over swap r> */ - 1 max ;
: post-bid ( i -- )
  dup bid over changed? 0= if drop exit then
  dup bid  over daily  2 pick keep +  rot good  buy-order drop ;
: restock ( i -- ) dup stock over good units - swap good import ;
: post-ask ( i -- )
  dup listed over changed? 0= if drop exit then
  dup listed  over good fuel = if reserve else 0 then  rot good  sell-order drop ;

\ Buy orders first: at the start of a world they are up before the stock
\ it sells is brought up, one good at a time
: bids ( -- )
  #goods @ begin dup while 1- dup buys? if dup post-bid then repeat drop ;
: asks ( -- )
  #goods @ begin dup while 1- dup buys? 0= if dup restock dup post-ask then
  repeat drop ;

\ Once a game day, what it bought goes down to the surface
variable shipped
: ship ( -- )
  today shipped @ = if exit then  today shipped !
  #goods @ begin dup while 1- dup buys? if dup excess over good export then
  repeat drop ;

\ Its own fuel
: fuel-up ( -- ) reserve 2 *  fuel units -  fuel import ;

: round ( -- )
  fuel-up load-goods bids asks ship ;

: launched ( -- ) -1 shipped !  forget-orders  begin round 60 wait drop again ;
