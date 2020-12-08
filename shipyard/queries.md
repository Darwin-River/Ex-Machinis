# Queries Script

This bit of code greatly simplifies spacecraft queries by establishing a shared buffer for query results and assigning names to some of the most frequently used queries.

```
<reset>

  : nCompiled ." Done compiling code. " ;

  : nNullValue -989 ;

  : nResultSize 100 ;
  
  variable pResults nResultSize allot
  
  : vPurgeResults ( -- ) 
    0
    begin
      dup pResults + nNullValue swap !
      2 +
      dup nResultSize >
    until
    drop
  ;
  
  : vCountResults ( pResults -- pResults nCount )
    -1
    begin
     1 +
     over over 2 * + @
     nNullValue =
    until
  ;
       
  : vFetchResults ( pResults nCount -- bufferContents nCount )
    dup >r
    2 *
    begin
      2 -
      over over + @
      rot rot      
      dup 0 =
    until
    drop drop
    r>
  ;
  
  : vPrintStack ( bufferContents nCount -- )
    begin
      1 -
      swap .
      dup 0 =
    until
    drop
  ;
  
  : vRunQuery ( nValue nQueryId -- pResults ) vPurgeResults pResults swap nResultSize swap query pResults ;
  
  : vPrintResults ( pResults -- ) vCountResults vFetchResults vPrintStack ;
  
  : vPrintString ( pString -- ) count type ;
  
  : vGetCompanyName ( nCompanyId -- pResult ) 100 vRunQuery ;  
  : vGetCompanyCredits ( nCompanyId -- pResult ) 101 vRunQuery ;
  : vListCompanyDrones ( nCompanyId -- pResult ) 110 vRunQuery ;
  : vGetDroneName ( nDroneId -- pResult ) 200 vRunQuery ;
  : vGetDroneOwner ( nDroneId -- pResult ) 201 vRunQuery ;
  : vGetDroneLocation ( nDroneId -- pResult ) 202 vRunQuery ;

  nCompiled

</reset>
```
