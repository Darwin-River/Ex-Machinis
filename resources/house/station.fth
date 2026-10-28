\ The House station drone (#380): the program the House's trading station at
\ each exchange runs. It trades by the players' own rules (sell and buy
\ orders, pulls and pushes, the sales tax), from a Drop Station hull with two
\ modules no player can get: an Import Station brings goods up from the
\ planet surface (N R 5911 perform) and an Export Station sends them down
\ (N R 5921 perform). What it trades and at what prices comes from the
\ exchange's game data (query 630, the station_goods table).
\ - What the exchange buys: a buy order for up to a day's demand. Its price
\   falls from the listed price to half as the stock fills, and once a game
\   day the stock goes down to the surface.
\ - What the exchange sells: stock brought up to a quarter of a day's
\   supply, on a sell order at the listed price.

\ The goods: query 630's rows (resource, side, price 2 cells, daily units,
\ units waiting 2 cells), 14 bytes each
create goods 1400 allot
variable #goods
: row ( i -- addr ) 14 * goods + ;
: good ( i -- resource ) row @ ;
: buys? ( i -- f ) row 2 + @ 1 = ;
: listed ( i -- price ) row 4 + 2@ drop ;
: daily ( i -- n ) row 8 + @ 1 max ;
: load-goods ( -- ) goods 1400 630 query 0 max #goods ! ;

\ The price each good's order was last posted at: an order is posted only
\ when its price changes (each posting is an action, and takes game time)
create posted 200 allot
: forget-orders ( -- ) 100 begin dup while 1- 0 over 2 * posted + ! repeat drop ;
: changed? ( price i -- f ) 2 * posted + dup @ rot tuck <> if swap ! -1 else 2drop 0 then ;

: import ( n resource -- ) over 0> if 5911 perform drop else 2drop then ;
: export ( n resource -- ) over 0> if 5921 perform drop else 2drop then ;

500 constant reserve     \ fuel it keeps for itself (orders and thinking)

\ Buying: from the listed price at no stock to half of it at a full day's
\ stock; the order stays open for up to a day's demand
: bid ( i -- price ) dup listed  over good units over *  rot daily 2 * /  - 1 max ;
: post-bid ( i -- )
  dup bid over changed? 0= if drop exit then
  dup bid  over daily  rot good  buy-order drop ;

\ Selling: a quarter of a day's supply in stock, at the listed price (fuel
\ keeps the reserve back)
: stock ( i -- n ) daily 4 / 1 max ;
: restock ( i -- ) dup stock over good units - swap good import ;
: post-ask ( i -- )
  dup listed over changed? 0= if drop exit then
  dup listed  over good fuel = if reserve else 0 then  rot good  sell-order drop ;

: tend ( i -- ) dup buys? if post-bid else dup restock post-ask then ;

\ Once a game day, what it bought goes down to the surface
variable shipped
: ship ( -- )
  today shipped @ = if exit then  today shipped !
  #goods @ begin dup while 1- dup buys? if dup good units over good export then
  repeat drop ;

\ Its own fuel
: fuel-up ( -- ) reserve 2 *  fuel units -  fuel import ;

: round ( -- )
  fuel-up load-goods
  #goods @ begin dup while 1- dup tend repeat drop
  ship ;

: launched ( -- ) -1 shipped !  forget-orders  begin round 60 wait drop again ;
