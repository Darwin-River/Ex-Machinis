\ The benchmark (#380 Lua proof of concept): score 8 products from a price
\ table and a bill of goods, and pick the best, as the Systems shop does
\ each round; the same work as score.lua
create prices 712 , 734 , 757 , 690 , 866 , 600 , 820 , 640 ,
create costs 22 , 9 , 14 ,
create needs 2 , 3 , 3 , 2 , 3 , 3 , 8 , 0 , 0 , 4 , 2 , 1 ,
             6 , 0 , 2 , 3 , 3 , 3 , 5 , 1 , 1 , 2 , 2 , 2 ,
variable best  variable bscore
: need ( p k -- n ) swap 3 * + cells needs + @ ;
: cost ( p -- n ) 0 3 begin dup while 1- >r over r@ need r@ cells costs + @ * + r> repeat drop nip ;
: score ( p -- n ) dup cells prices + @ 95 100 */ swap cost - ;
: choose ( -- ) -32000 bscore !
  8 begin dup while 1- dup score dup bscore @ > if bscore ! dup best ! else drop then repeat drop ;
: bench ( n -- ) begin dup while 1- choose repeat drop ;
: run ( -- ) 1000 bench ;
