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

\ Below n fuel, buy m here, cheapest first (from any drone selling it)
: top-up ( n m -- )
  swap fuel units > if fuel buy-here drop else drop then ;

\ Stranded with little fuel and nobody selling here: post a buy order for
\ up to want fuel at price a unit (well above what fuel sells for, to pay
\ a courier to come), then wait, 2 game hours at a time, until some has come.
\ Waiting runs no instructions, so it burns no fuel
: call-for-fuel ( price want -- )
  fuel buy-order drop
  begin fuel units 20 < while 120 wait drop repeat ;

\ --- Selling ------------------------------------------------------------

\ Sell up to n of a resource here, best price first: sell-here pushes into
\ the best buy orders here (any drone's, the station drone's too)
: sell-best ( n resource -- ) sell-here drop ;
\ Sell at most 15 of a good this hour: buyers here tend to bid less as
\ they stock up
: portion ( resource -- )
  dup units 15 min ?dup if swap sell-best else drop then ;

\ Offer a good to other drones here at a price, keeping none; the order
\ stays open as the hold changes
: offer ( price resource -- ) 0 swap sell-order drop ;

\ --- Buying where it is cheapest (#380) ---------------------------------
\ Buy up to n of a resource here, cheapest first: buy-here pulls from the
\ cheapest sell orders here (any drone's, the station drone's too),
\ one after another until it has n or nobody sells more
: buy-cheapest ( n resource -- ) buy-here drop ;

\ Offer a good to other drones halfway between the best buy order here and
\ the cheapest other sell order (nothing where either is missing)
: offer-midway ( resource -- )
  dup sell-price over buy-price  2dup 0<> swap 0<> and
  if + 2/ else 2drop 0 then
  ?dup if 0 rot sell-order drop else drop then ;

\ --- Reading the market (#380) ------------------------------------------
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

\ Adaptive pricing, from the market here: ask a unit under the cheapest
\ other seller while the stock sells out (fewer than 4 aboard), a unit over
\ the best bid while stock piles up (more than 16), and halfway between
\ otherwise; 0 where nobody sells it. Call reoffer every
\ round, so a standing sell order follows its own sales (round 25's miners
\ priced once, too low, and demand outran them). ?sell-order posts only
\ when the price changed: each post costs a fuel
: smart-ask ( resource -- price )
  dup buy-price ?dup 0= if drop 0 exit then          ( resource ask )
  over sell-price  ?dup 0= if dup 2 / then           ( resource ask pay )
  rot units  dup 4 < if 2drop 1- exit then
  16 > if nip 1+ exit then
  + 2 / ;
: reoffer ( resource -- ) dup smart-ask ?dup if 0 rot ?sell-order drop else drop then ;

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
\ hours) for it, in case nobody here has one for sale yet
: get-one ( resource -- )
  dup units if drop exit then
  dup 1 swap buy-from-station drop
  6 begin over units 0= over 0> and while 60 wait drop 1- repeat 2drop ;
\ Hand the newest clone a kit and 40 fuel
: equip-clone ( -- )
  670 ask ?dup 0= if exit then
  1- 4 * answer + @ >r
  1 my-kit r@ push drop  40 fuel r> push drop ;
\ Top the tank up to n here, cheapest first, waiting (up to 6 game hours)
\ (Waits for the fuel, not for n: computing burns a unit or two just
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
