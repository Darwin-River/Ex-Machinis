\ A dictionary of useful words for Public Forge drones (#380), gathered
\ from the starters and company designs. Copy the ones you need into your
\ script: a drone can't load another file. documents/forth-words.md
\ explains each one. This file loads on the drone VM as a whole (CI checks).

\ --- Goods the game words don't name ----------------------------------
2021 constant alloys-a1   2022 constant alloys-a2   2023 constant alloys-a3
2051 constant polymers    3011 constant rods        3001 constant sheets

\ --- Inventory ----------------------------------------------------------
\ Total metals aboard
: metals ( -- n ) metals-a1 units metals-a2 units + metals-a3 units + ;

\ --- Mining -------------------------------------------------------------
\ Mine until n units of a mineral are aboard; wait an hour while its
\ deposit here refills (at Mars's moons the starters move on instead)
: dig ( mineral n -- )
  begin over units over < while
    over deposit 5 < if 60 wait drop else
      \ A run that brings nothing (others took it first) still burns fuel:
      \ wait as if the deposit were dry
      over units >r  over mine drop  over units r> = if 60 wait drop then
    then
  repeat 2drop ;

\ --- Fuel ---------------------------------------------------------------
\ Brew 30 fuel from 1 Organics (A1) and 2 Inorganics (A1) (a Factory Kit's
\ Chemical Reactor; a Mining Kit can't brew). Leaves the ior
: brew-fuel ( -- ior ) fuel make ;

\ Below n fuel, buy m from the House here; it arrives when the hour's
\ batch clears, so wait for it
: top-up ( n m -- )
  swap fuel units > if fuel buy-from-station drop 120 wait drop
  else drop then ;

\ --- Selling ------------------------------------------------------------

\ Sell up to n of a resource here: into the best buy order (a House
\ station drone's, or another player's), at once, when it pays at least
\ half what the House lists (a station drone bids less as its stock fills,
\ down to half). What that order doesn't take goes to the station's hourly
\ batch, while there is one
: sell-best ( n resource -- )
  >r  r@ best-bid                               ( n price drone avail )
  dup 0>  3 pick  r@ 1 house-price 2 /  < 0=  and if
    rot drop  2 pick min  tuck  r@ rot push if drop 0 then  -   ( rest )
  else 2drop drop then
  ?dup if r> sell-to-station drop else r> drop then ;
\ Sell at most 15 of a good this hour: a station drone pays less as its
\ stock fills
: portion ( resource -- )
  dup units 15 min ?dup if swap sell-best else drop then ;

\ Offer a good to other drones here at a price, keeping none; the order
\ stays open as the hold changes
: offer ( price resource -- ) 0 swap sell-order drop ;

\ --- Buying where it is cheapest (#380) ---------------------------------
\ Buy up to n of a resource here where it is cheapest: another drone's sell
\ order (best-offer) or the House station (house-price, side 2: what it
\ asks). The House delivers when its batch clears, within the game hour.
\ A drone selling only a unit or two can't starve it: what the drone
\ doesn't sell (or a pull that fails) it buys from the House
: buy-cheapest ( n resource -- )
  >r  r@ 2 house-price  r@ best-offer         ( n house price drone avail )
  dup 0> 3 pick 0> and  3 pick 5 pick > 0=  5 pick 0= or  and if
    rot drop  3 pick min  tuck  r@ rot pull if drop 0 then  ( n house k )
    rot swap -  swap 0<>  over 0> and                ( rest f )
  else 2drop drop 0<> then                           ( n f )
  if r> buy-from-station drop else r> 2drop then ;

\ Offer a good to other drones under the House: halfway between what the
\ House here pays and what it asks (nothing where there is no House)
: offer-under-house ( resource -- )
  dup 1 house-price over 2 house-price + 2 /
  ?dup if 0 rot sell-order drop else drop then ;

\ --- Reading the market (#380) ------------------------------------------
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

\ Holding more than half of what sold at Earth yesterday (query 682)?
\ Sell first, or make something else
: overstocked? ( product -- f ) dup units 2 *  swap 1 earth sold  > ;

\ Credits above cash-floor, at most 32,000 (credits are a double). Define
\ cash-floor first: 300 constant cash-floor
300 constant cash-floor
: spare ( -- n )
  credits cash-floor 0 d- dup 0< if 2drop 0 exit then
  if drop 32000 exit then  dup 0< if drop 32000 then ;

\ Of two minerals, the one with more left in the deposit here
: richer ( mineral mineral -- mineral ) over deposit over deposit < if nip else drop then ;

\ Profit from making one product: what it sells for here less what its
\ inputs cost here (n units of one input). Example, an alloy from 2 metals:
\ alloys-a1 metals-a1 2 profit
: profit ( product input n -- credits ) swap buy-price * swap sell-price swap - ;

\ Adaptive pricing: where there is a House to price by, ask a unit under
\ what the House asks while the stock sells out (fewer than 4 aboard), a
\ unit over what it pays while stock piles up (more than 16), and halfway
\ between otherwise; 0 where there is no House station. Call reoffer every
\ round, so a standing sell order follows its own sales (round 25's miners
\ priced once, too low, and demand outran them)
: smart-ask ( resource -- price )
  dup 2 house-price ?dup 0= if drop 0 exit then      ( resource ask )
  over 1 house-price  ?dup 0= if dup 2 / then        ( resource ask pay )
  rot units  dup 4 < if 2drop 1- exit then
  16 > if nip 1+ exit then
  + 2 / ;
: reoffer ( resource -- ) dup smart-ask ?dup if 0 rot sell-order drop else drop then ;

\ --- State machines (#380) ----------------------------------------------
\ The game words become, in?, tick and ticks keep a drone's mode. A
\ skeleton: number the modes, one word per mode, and a step that picks one.
\   0 constant mining  1 constant selling
\   : mine-step ... selling become ... ;   : sell-step ... mining become ... ;
\   : step ( -- ) mining in? if mine-step exit then sell-step ;
\   : launched ( -- ) unpack drop mining become begin step tick again ;
\ Give up on a mode that takes too long: ticks counts its rounds
: stuck-for? ( n -- f ) ticks < ;

\ --- Buying from other drones -------------------------------------------
\ Buy up to n of a good from the cheapest seller here at price or less;
\ leaves 0 when it bought, else nonzero
: buy-cheap ( n resource price -- ior )
  >r dup 610 ask 0> 0= if 2drop r> drop -1 exit then
  answer 2@ drop r> > if 2drop -1 exit then
  answer 6 + @ answer 4 + @ - 0 max rot min
  ?dup 0= if drop -1 exit then
  swap answer 8 + @ pull ;

\ --- Making -------------------------------------------------------------
\ Make goods one after another while the inputs last; stop at a failure.
\ Example: rods from Metals (A1), two metals a rod
: forge-rods ( -- )
  begin metals-a1 units 1 > while
    alloys-a1 make if exit then  rods make if exit then
  repeat ;

\ --- Growing ------------------------------------------------------------
\ Growth (#380): with 7,000 credits or more, at an exchange, buy a Mining
\ Cruiser kit and a Starter Kit I, launch a clone of this drone (it runs
\ this same program), and hand it a kit and 40 fuel. my-kit is the kit this
\ design needs: mining-kit or factory-kit (#380)
6001 constant cruiser-kit   mining-kit constant my-kit
\ Buy one of a good here unless one is aboard, and wait (up to 6 game
\ hours) for it: the House delivers when the batch clears
: get-one ( resource -- )
  dup units if drop exit then
  dup 1 swap buy-from-station drop
  6 begin over units 0= over 0> and while 60 wait drop 1- repeat 2drop ;
\ Hand the newest clone a kit and 40 fuel
: equip-clone ( -- )
  670 ask ?dup 0= if exit then
  1- 4 * answer + @ >r
  1 my-kit r@ push drop  40 fuel r> push drop ;
\ Top the tank up to n from the House here, waiting (up to 6 game hours)
\ (Waits for the delivery, not for n: computing burns a unit or two just
\ after it arrives, and waiting for n then sat out all 6 hours)
: fill-to ( n -- )
  fuel units tuck - dup 0> if
    fuel buy-from-station drop
    6 begin over fuel units < 0= over 0> and while 60 wait drop 1- repeat
  then 2drop ;
\ One kit at a time. A hull already
\ bought is launched whenever the fuel allows; after equipping the clone
\ the tank is refilled (round 19 lost 8 parents that left with 20 fuel)
: reinvest ( -- )
  my-kit units if equip-clone 60 fill-to exit then
  cruiser-kit units 0= if
    credits 7000 0 d< if exit then
    cruiser-kit get-one  cruiser-kit units 0= if exit then
  then
  90 fill-to  fuel units 80 < if exit then
  me cruiser-kit launch if exit then
  my-kit get-one  my-kit units if equip-clone then
  60 fill-to ;
\ A clone starts with no equipment: wait for its kit
: await-kit ( -- ) begin my-kit units 0= while 60 wait drop repeat ;

\ So that this file can also be released as it is (it does nothing)
: launched ( -- ) ;
