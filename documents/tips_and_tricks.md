# Ex Machinis: Tips and Tricks

This manual provides an expanding collection of FORTH scripts that you might find useful when programming and troubleshooting your Ex Machinis drones.  Although the scripts are meant to function without modification, they also provide convenient examples and starting points for writing your own scripts.

## Table of Contents
- [Improving Readability](tips_and_tricks.md#Improving-Readability)
  - [Naming Commonly Used Values](tips_and_tricks.md#Naming-Commonly-Used-Values)
  - [Improving Readability with Words](tips_and_tricks.md#Improving-Readability-with-Words)
  - [Checking Results](tips_and_tricks.md#Checking-Results)
  - [Simplifying the Mining Protocol](tips_and_tricks.md#Simplifying-the-Mining-Protocol)
- [Troubleshooting Tools](tips_and_tricks.md#Troubleshooting-Tools)
  - [Debugging the Stack with peek](tips_and_tricks.md#Debugging-the-Stack-with-peek)
  - [Clearing the stack with purge](tips_and_tricks.md#Clearing-the-stack-with-purge)
- [Appendix A: Basic Drone Script](tips_and_tricks.md#Appendix-A-Basic-Drone-Script)

## Improving Readability

If you’ve already read the [player’s manual](players-manual.md), you should be aware that FORTH is a very powerful and flexible programming language, which, due to its compact and highly efficient structure, jettisons many of the guard rails and readability features that are enforced in other languages.

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
: nKunspar 1100 ;

\ Favorite locations
: nEarth 3 ;
: nMars 4 ;
: nJupiter 5 ;
: nMoon 10 ;
```

Each of these definitions simply places the integer ID on the stack for the particular resource or location that is represented by the work we are defining.  Consequently, if I insert the words `nVoxite` or `nEarth` in my code, it will instruct the interpreter or compiler to put the corresponding ID on the stack.  This does not significantly change how the program functions but it does make it easier to read.

Since each of these words serve to indicate a specific object, I prefix them with a lower-case n for noun.

[Return to TOC](tips_and_tricks.md#Table-of-Contents)

### Improving Readability with Words

It’s possible to significantly improve the readability of your FORTH code by defining descriptive words to handle the transfer of values to commands.

Consider the `perform` command, which is used to instruct your drones to implement a broad range of in-game activities.  The `perform` command pulls multiple values from the stack that tell the interpreter which protocol is being invoked and how it is to be executed.

The following script defines a set of descriptive words that place the protocol ID on the stack before invoking the `perform` command. This eliminates the need for you to remember the ID associated with each action..

```
<run>

\ Basic definitions
: vPlaceSellOrder ( nPrice nLimit nResource -- ior ) 7 perform ;
: vPlaceBuyOrder ( nPrice nLimit nResource -- ior ) 8 perform ;
: vGotoLocation ( nLocation -- ior ) 5001 perform ;
: vPushResource ( nQuantity nResource nTarget -- ior ) 10 perform ;
: vPullResource ( nQuantity nResource nTarget -- ior ) 11 perform ;

</run>
```
I also improve readability here by using descriptive camel-case phrases to describe each newly-defined action and prefix the compound word with a lower-case v for verb. The parentheses that follow each word enclose uncompiled comments that describe the values that must be placed on the stack before invoking the word, which is represented by the two hyphens.  The values the word leaves on the stack are listed after the two hyphens.  FORTH programmers use this notation to help themselves and other users with stack management.

Every one of these words leaves one value, which FORTH programmers call an *ior* (an input/output result): `perform` waits until the action is finished, then leaves 0 if it worked or a negative code saying why it didn't (see [Issuing Commands](players-manual.md#chapter-4-issuing-commands) in the player's manual). Travelling uses your engine (protocol 5001), so unpack your kit first (a Mining or a Factory Kit; both have an engine). Pushes and pulls take the quantity first: `5 nKunspar 52 vPushResource` delivers 5 kunspar to drone 52.

The definitions can be used in conjunction with the previously-defined favorites to vastly improve the readability of your script.  For example, the following code places a buy order for up to 10 units of magmite and then moves the spacecraft to the Moon, printing the result of each (0 when it worked):

```
<run>

5 ( Credits ) 10 ( Units ) nMagmite vPlaceBuyOrder .

nMoon vGotoLocation .

</run>
```

In this example I bracketed the units in parens to remind myself and others what the values I placed on the stack represent in the context of the `vPlaceBuyOrder` word.

### Checking Results

A drone that works on its own has to notice when something goes wrong. Since each of these words leaves 0 on success, a word can test the result with `if` (which, like other control words, only works inside a definition) and decide what to do next:

```
<run>

: vTryTrip ( nLocation -- )
  vGotoLocation dup 0= if drop ." arrived" else ." trip failed: " . then cr ;

nMoon vTryTrip

</run>
```

The drone replies with "arrived", or with the failure code, such as -2 if it has no engine.

[Return to TOC](tips_and_tricks.md#Table-of-Contents)

### Simplifying the Mining Protocol

Mining is the process by which mineral resources are extracted from planets, moons, and asteroids to supply raw materials for all subsequent manufacturing processes.

As with every physical action taken by your drones, mining minerals is invoked by placing the protocol ID on the stack and calling the perform command.

Each mineral has its own mining protocol and protocol ID.  These protocol IDs can be easily derived by adding 10000 to the resource ID for the mineral that you want to mine.

Therefore, it's possible to create a simple FORTH word that automatically invokes the correct protocol based on the resource ID of the targeted mineral.

The following script creates a definition for `vMineResource`, which retrieves the resource ID from the stack and uses it to derive and invoke the appropriate mining protocol.

```
<run>

: vMineResource ( nResource -- ior ) 10000 + perform ;

</run>
```

Each mineral needs the right mining module and a location where it's found (see [Extracting minerals](players-manual.md#extracting-minerals) in the player's manual). The Worm in the Mining Kit mines minerals on moons, so the following code mines kunspar on the Moon and prints the result:

```
<run>

nKunspar vMineResource .

</run>
```

[Return to TOC](tips_and_tricks.md#Table-of-Contents)

## Troubleshooting Tools

Another important practice to follow when coding your drones is defining words and variables that can be used to troubleshoot errors in your script.  This section presents routines which can be added to the start of your script to greatly facilitate the debugging process.


### Debugging the Stack with `vPeek`

The stack is where it's at in terms of FORTH program execution.  It is also where many of your errors will occur, since it's often challenging to keep track of what parameters are on the stack at any given time.  Consequently, one of the most valuable troubleshooting routines you can write, is one that prints out the contents of the stack without deleting them or changing their order.

Your drone already knows one such word: `.s` prints everything on the stack, bottom first, without changing it. The following definition shows how such a word can be built. It uses the return stack to temporarily hold values from the variable stack as it sifts through them and prints their values, top first, in the drone’s output buffer.

```
: vPeek
  depth 0 > 
  if 
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
  depth 0 > \ is there something on the stack?
  if 
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
\ Updated 23 October 2026
\ http://exmachinis.com

\ To use this script, copy and paste it as plain text
\ in an email to your drone.

\ Favorite resources
: nMagmite 1000 ;
: nVoxite 1020 ;
: nKunspar 1100 ;

\ Favorite locations
: nEarth 3 ;
: nMars 4 ;
: nJupiter 5 ;
: nMoon 10 ;

\ Basic definitions (each leaves 0, or a negative code)
: vPlaceSellOrder ( nPrice nLimit nResource -- ior ) 7 perform ;
: vPlaceBuyOrder  ( nPrice nLimit nResource -- ior ) 8 perform ;
: vGotoLocation ( nLocation -- ior ) 5001 perform ;
: vPushResource ( nQuantity nResource nTarget -- ior ) 10 perform ;
: vPullResource ( nQuantity nResource nTarget -- ior ) 11 perform ;
: vMineResource ( nResource -- ior ) 10000 + perform ;

\ Debugging definitions
: vPeek
  depth 0 > 
  if 
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
  depth 0 > \ is there something on the stack?
  if
    begin
      drop \ drop the top item from the stack
      depth 0 = \ is the stack empty?
    until \ repeat until true
  then
;

</reset>
```
[Return to TOC](tips_and_tricks.md#Table-of-Contents)
