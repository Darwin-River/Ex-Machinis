# Ex Machinis: Tips and Tricks

This manual provides an expanding collection of FORTH scripts that you might find useful when progrogramming and troubleshooting your Ex Machinis drones.  Although the scripts are meant to function without modification, they also provide convenient examples and starting points for writing your own scripts.

## Table of Contents
- [Improving Readability](tips_and_tricks.md#Improving-Readability)
  - [Naming Commonly Used Values](tips_and_tricks.md#Naming-Commonly-Used-Values)
  - [Improving Readability with Words](tips_and_tricks.md#Improving-Readability-with-Words)
  - [Simplifying the Mining Protocol](tips_and_tricks.md#Simplifying-the-Mining-Protocol)
- [Troubleshooting Tools](tips_and_tricks.md#Troubleshooting-Tools)
  - [Debugging the Stack with peek](tips_and_tricks.md#Debugging-the-Stack-with-peek)
  - [Clearing the stack with purge](tips_and_tricks.md#Clearing-the-stack-with-purge)
- [Appendix A: Basic Drone Script](tips_and_tricks.md#Appendix-A-Basic-Drone-Script)

## Improving Readability

If you’ve already read the player’s manual, you should be aware that FORTH is a very powerful and flexible programming language, which, due to its compact and highly efficient structure, jettisons many of the guard rails and readability features that are enforced in other languages.

This means that the programmer assumes much of the responsibility for writing code which is easy to understand and follow.

This section introduces various techniques for coding your drones in such a way that it is easy for yourself and others to follow what is happening in the script.

[Return to TOC](tips_and_tricks.md#Table-of-Contents)

### Naming Commonly Used Values

Since FORTH is not far removed from the underlying machine language and memory structure, it relies heavily on integer values to store, manipulate, and encode data.  While this has the advantage of greatly reducing the complexity and burden of the interpreter and compiler with respect to memory and processor time, it often makes the code harder to ready.

This interplay between efficiency and readability is reflected in the way that FORTH uses integer values to represent each of the game’s locations, drones, resources, protocols and queries.  Essentially, every in-game item has a unique two-byte integer ID that is used to access or manipulate the item in forth code.

Fortunately, FORTH makes it very easy for users to assign a memorable name or phrase to a numeric id by defining a word that places the particular ID on the stack.   In the following example, we create a definition for each of our drone’s favorite locations and items.

```
\ Favorite resources
: nMagmite 1000 ;
: nVoxite 1020 ;

\ Favorite locations
: nEarth 3 ;
: nMars 4 ;
: nJupiter 5 ;
```

Each of these definitions simply places the integer ID on the stack for the particular resource or location that is represented by the work we are defining.  Consequently, if I insert the words `wVoxite` or `wEarth` in my code, it will instruct the interpreter or compiler to put the corresponding ID on the stack.  This does not significantly change how the program functions but it does make it easier to read.

[Return to TOC](tips_and_tricks.md#Table-of-Contents)

### Improving Readability with Words

It’s possible to significantly improve the readability of your FORTH code by defining descriptive words to handle the transfer of values to commands.

Consider the `perform` command, which is used to instruct your drones to implement a broad range of in-game activities.  The `perform` command pulls multiple values from the stack that tell the interpreter which protocol is being invoked and how it is to be executed.

The following script defines a set of descriptive words, which pulls the required variables from the stack when performing a particular protocol.

```
<run>

\ Basic definitions
: vPlaceSellOrder ( nPrice nLimit nResource -- ) 7 perform ;
: vPlaceBuyOrder  ( nPrice nLimit nResource ) 8 perform ;
: vGotoLocation ( nLocation -- ) 5 perform ;
: vPushResource ( nResource nTarget -- ) 10 perform ;
: vPullResource ( nResource nTarget -- ) 11 perform ;

</run>
```

These new variables and definitions can be used in conjunction with the previously-defined favorites words to vastly improve the readability of your script.  For example, the following script places a buy order for up to 10 units of magmite and then moves your spacecraft to Jupiter:


```
<run>

5 ( Credits ) 10 ( Units ) nMagmite vPlaceBuyOrder

nJupiter vGotoLocation

</run>
```

[Return to TOC](tips_and_tricks.md#Table-of-Contents)

### Simplifying the Mining Protocol

Mining is the process by which mineral resources are extracted from planets, moons, and asteroids to supply raw materials for all subsequent manufacturing processes.

As with every physical action taken by your drones, mining minerals is invoked by placing the protocol ID on the stack and calling the perform command.

Each mineral has its own mining protocol and protocol ID.  However the protocol ID can be easily derived by adding 10000 to the resource ID for the mineral that you want to mine.

Therefore, it's possible to create a FORTH word that automatically invokes the correct protocol based on the resource ID of the targeted mineral.

The following script creates a definition for `wMineResource`, which retrieves the resource ID from the `pResource` variable and uses this to derive and invoke the appropriate mining protocol.


```
<run>

: vMineResource ( nResource -- ) 10000 + perform ;

</run>
```

This script, which is included in the [Appendix A Basic Drone Script](tips_and_tricks.md#Appendix-A-Basic-Drone-Script).

```
<run>

nVoxite vMineResource

</run>
```

[Return to TOC](tips_and_tricks.md#Table-of-Contents)

## Troubleshooting Tools

Another important practice to follow when coding your drones is defining words and variables that can be used to troubleshoot errors in your script.  This section presents routines which can be added to the start of your script to greatly facilitate the debugging process.


### Debugging the Stack with `vPeek`

The stack is where it's at in terms of FORTH program execution.  It is also where many of your errors will occur, since it's often challenging to keep track of what parameters are on the stack at any given time.  Consequently, one of the most valuable troubleshooting routines you can write, is one that prints out the contents of the stack without deleting them or changing their order.

The following definition uses the return stack to temporarily hold values from the variable stack as it sifts through them and prints their values in the drone’s output buffer.

```
: vPeek
  depth 0 > if 
    depth \ get the stack size
    begin
      swap \ swap stack size with next value on stack
      dup . \ duplicate and print the top value
      >r \ move the top value to the return stack
      depth 1 = \ only the stack size remains?
    until \ repeat until true
    begin
      r> \ retrieve a value from the return stack
      swap \ swap it with the original stack size
      1 - \ decrement the original stack size
      dup 0 = \ duplicate and check if zero
    until \ repeat until true
    drop \ drop the decremented stack size
  then
;
```

This script, which is included in the [Appendix A Basic Drone Script](tips_and_tricks.md#Appendix-A-Basic-Drone-Script).

[Return to TOC](tips_and_tricks.md#Table-of-Contents)

### Clearing the stack with `vPurge`

Sometimes, when you lose track of what is on the stack, it’s easiest just to clear the contents and start again.  The following script defines a useful word, which removes all values from the stack without generating a stack underflow error.  It does this by iteratively deleting the values on the stack until nothing remains and depth = 0.


```
: vPurge
  depth 0 > if \ is there something on the stack?
    begin
      drop \ drop the top item from the stack
      depth 0 = \ is the stack empty?
    until \ repeat until true
  then
;
```

This script, which is included in the [Appendix A Basic Drone Script](tips_and_tricks.md#Appendix-A-Basic-Drone-Script).

[Return to TOC](tips_and_tricks.md#Table-of-Contents)

## Appendix A: Basic Drone Script

Email the following text to your drone to erase its memory and install the scripting items addressed in this manual.


```
<reset>

\ Basic Drone
\ Updated 12 June 2020
\ http://exmachinis.com

\ To use this script, copy and paste it as plain text
\ in an email to your drone.

\ Favorite resources
: nMagmite 1000 ;
: nVoxite 1020 ;

\ Favorite locations
: nEarth 3 ;
: nMars 4 ;
: nJupiter 5 ;

\ Basic definitions
: vPlaceSellOrder ( nPrice nLimit nResource -- ) 7 perform ;
: vPlaceBuyOrder  ( nPrice nLimit nResource ) 8 perform ;
: vGotoLocation ( nLocation -- ) 5 perform ;
: vPushResource ( nResource nTarget -- ) 10 perform ;
: vPullResource ( nResource nTarget -- ) 11 perform ;

\ Debugging definitions
: vPeek
  depth 0 > if 
    depth \ get the stack size
    begin
      swap \ swap stack size with next value on stack
      dup . \ duplicate and print the top value
      >r \ move the top value to the return stack
      depth 1 = \ only the stack size remains?
    until \ repeat until true
    begin
      r> \ retrieve a value from the return stack
      swap \ swap it with the original stack size
      1 - \ decrement the original stack size
      dup 0 = \ duplicate and check if zero
    until \ repeat until true
    drop \ drop the decremented stack size
  then
;
	
: vPurge
  depth 0 > if \ is there something on the stack?
    begin
      drop \ drop the top item from the stack
      depth 0 = \ is the stack empty?
    until \ repeat until true
  then
;

</reset>
```
[Return to TOC](tips_and_tricks.md#Table-of-Contents)
