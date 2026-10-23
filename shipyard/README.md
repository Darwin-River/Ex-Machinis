# Welcome to the Shipyard!

I'll be using this directory to store and modify bits of useful code for drones as well as scripts for fully functional spacecraft. You can use this as a starting point for coding your own spacecraft.

Scripts in the directory:

* The [Queries](queries.md) script creates a simple vocabulary for running queries that use a shared results buffer.
* [drop_station](drop_station) is a whole drop station drone: query helpers, import and export, and `vMaintainResource ( n1 n2 -- n3 )`, which imports or exports resource n2 one unit at a time until the hold has n1 of it, leaving 0 when done or the code of the import or export that failed (an Import Station must be aboard). Send the file as it is: it is wrapped in `<reset>` tags, so the drone starts from a fresh memory.
