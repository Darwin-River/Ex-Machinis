<reset>

  : wResultSize 100 ;
  
  variable pResult wResultSize allot
  
  : wRunQuery ( nValue nQueryId -- pResult ) pResult swap wResultSize swap query pResult ;
  
  : wGetCompanyName ( nCompanyId -- pResult ) 100 wRunQuery ;  
  : wGetCompanyCredits ( nCompanyId -- pResult ) 120 wRunQuery ;
  : wCountCompanyDrones ( nCompanyId -- pResult ) 130 wRunQuery ;
  : wListCompanyDrones ( nCompanyId -- pResult ) 140 wRunQuery ;
  : wGetDroneName ( nDroneId -- pResult ) 200 wRunQuery ;
  : wGetDroneOwner ( nDroneId -- pResult ) 210 wRunQuery ;
  : wGetDroneLocation ( nDroneId -- pResult ) 220 wRunQuery ;
  : wPrintString ( pString -- ) count type ;
  
  52 wGetDroneOwner @ wGetCompanyName wPrintString  
  2 wGetDroneLocation @ .
  
</reset>
