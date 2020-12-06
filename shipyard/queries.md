# Queries Script

This bit of code greatly simplifies spacecraft queries by establishing a shared buffer for query results and assigning names to some of the most frequently used queries.

```
<reset>

  : nResultSize 100 ;
  
  variable pResults nResultSize allot
  
  : vPurgeResults ( -- ) 
    0
    begin
      dup pResults + 0 swap !
      2 +
      dup nResultSize >
    until
    drop
  ;
  
  : vFetchResults ( pResults nCells -- bufferContents )
    2 *
    begin
     over over + @
     rot rot
     2 -
     dup 0 <
   until
   drop drop
 ;
  
  : vRunQuery ( nValue nQueryId -- pResults ) vPurgeResults pResults swap nResultSize swap query pResults ;
  
  : vGetCompanyName ( nCompanyId -- pResult ) 100 vRunQuery ;  
  : vGetCompanyCredits ( nCompanyId -- pResult ) 120 vRunQuery ;
  : vCountCompanyDrones ( nCompanyId -- pResult ) 130 vRunQuery ;
  : vListCompanyDrones ( nCompanyId -- pResult ) 140 vRunQuery ;
  : vGetDroneName ( nDroneId -- pResult ) 200 vRunQuery ;
  : vGetDroneOwner ( nDroneId -- pResult ) 210 vRunQuery ;
  : vGetDroneLocation ( nDroneId -- pResult ) 220 vRunQuery ;
  : vPrintString ( pString -- ) count type ;

</reset>
```
