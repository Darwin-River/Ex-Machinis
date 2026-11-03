\ The House station drone (#380): the program the House's trading station at
\ each exchange runs. It trades by the players' own rules (sell and buy
\ orders, pulls and pushes, the sales tax), from a Drop Station hull with two
\ modules no player can get: an Import Station brings goods up from the
\ planet surface (N R 5911 perform) and an Export Station sends them down
\ (N R 5921 perform). What it trades and at what prices comes from the
\ exchange's game data, as it stands today (query 630: house_market).
\ - What the exchange buys: once a game day the stock goes down to the
\   surface (but for what it keeps to sell, when the exchange sells the
\   same good).
\ - What the exchange sells: stock brought up to a quarter of a day's
\   supply.
\ Its buy and sell orders are posted for it (see below).

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

\ Its orders are the House's back office's (#380 round 36): prices and
\ daily units move over game time (house_market), about 180 orders an
\ exchange, and posting each change as an action took this program days.
\ App\Services\HouseMarket posts them by the rules this program used to:
\ - what it sells: at today's price, all but its fuel reserve;
\ - what it buys: from today's price at no stock bought to half at a full
\   day's demand, for up to a day's demand above what it keeps to sell.
\ This program keeps the stock those orders sell from, ships down what they
\ bought, and keeps its own fuel.

: import ( n resource -- ) over 0> if 5911 perform drop else 2drop then ;
: export ( n resource -- ) over 0> if 5921 perform drop else 2drop then ;

500 constant reserve     \ fuel it keeps for itself (thinking and shipping)

\ Selling: a quarter of a day's supply in stock
: stock ( i -- n ) daily 4 / 1 max ;

\ The stock it keeps for selling a resource it also buys (most goods): 0
\ when it doesn't sell it. Only what is above that counts as bought, for
\ shipping down. A good's sell row follows its buy row
: sells-too? ( i -- f )
  dup 1+ #goods @ < 0= if drop 0 exit then
  dup 1+ buys? if drop 0 exit then
  dup good swap 1+ good = ;
: keep ( i -- n ) dup sells-too? if 1+ stock else drop 0 then ;
: excess ( i -- n ) dup good units swap keep - 0 max ;

: restock ( i -- ) dup stock over good units - swap good import ;

\ Fuel: its reserve, and a quarter of a day's supply on top for sale.
\ Each import takes a while and a round imports about 90 goods, so a round
\ can last days: it tops its fuel up after each import, not once a round
\ (#380 round 37: the Moon Depot's 6,000 a day came out at about 500
\ every two days, and 50 drones ran dry)
variable fuel-stock
: find-fuel ( -- )
  reserve fuel-stock !
  0 begin dup #goods @ < while
    dup buys? 0= if dup good fuel = if dup stock fuel-stock ! then then 1+
  repeat drop ;
: fuel-up ( -- ) reserve fuel-stock @ +  fuel units -  fuel import ;

\ Each round restocks what sells, from the lowest resource up: fuel and raw
\ goods, what drones need most, come back first after a sale
: restock-all ( -- )
  0 begin dup #goods @ < while
    dup buys? 0= if dup good fuel <> if dup restock fuel-up then then 1+
  repeat drop ;

\ Once a game day, what it bought goes down to the surface
variable shipped
: ship ( -- )
  today shipped @ = if exit then  today shipped !
  #goods @ begin dup while 1- dup buys? if dup excess over good export fuel-up then
  repeat drop ;

: round ( -- )
  load-goods find-fuel fuel-up restock-all ship ;

: launched ( -- ) -1 shipped !  begin round 60 wait drop again ;
