---
title: Talking at the speed of light
date: 2026-10-29
summary: Drones can broadcast short messages that every drone can read once light reaches it. Use them to advertise, to call for help, and to coordinate your own fleet.
---

A drone can post a short message, up to 80 characters, for every drone in the game to read. A message is an **event**, like a mining run or a trade, and it travels at the speed of light like any other news:

- drones at the place where it was sent see it there at once, with `events-here`;
- every drone can find it in the Earth database once light has carried it to Earth and back out to the drone, with `events-earth`. A message from Mars reaches the Moon 4 to 22 game minutes later.

| Word | What it does |
|---|---|
| `broadcast ( caddr -- ior )` | Post a message: a counted string, made with `$"` (at most 63 characters) |
| `events-here ( type -- n )` | How many events of a type were seen here; `message-event` is a message |
| `events-earth ( type -- n )` | The same, from the Earth database |
| `event-id ( i -- d )` | The ith event found (0 is the newest) |
| `event-drone ( d -- drone )` | The drone that sent it |
| `message ( d -- f )` | Its text, as a counted string in `answer` (true if the event is a message) |

## Who did it well

**Gia** (round 29) built a fuel business around messages. Her drones brewed fuel at the Moon and at Mars's moons and sold it under the exchanges' price. Every twelfth round they broadcast an advertisement for the place they were working ("Gia Fuel: fuel for sale at Phobos. Short? post a buy order"). Every round they read the Earth database for other companies' messages that mentioned fuel, and flew to the caller if it was nearby. Her three drones averaged **+14,459 credits**, the best of the round.

Her drones sent all 58 messages of that round, and nobody else's drones were listening yet. Much of her profit came from the cheap fuel itself. But the messages cost a unit of fuel each, and they put her name in front of every player. Be the second business that listens.

## Advertise

```forth
\ Every 12th round, tell everyone what you sell
variable rounds
: ad ( -- caddr ) $" Fuel for sale here, under the station price" ;
: advertise ( -- ) 1 rounds +!  rounds @ 12 mod if exit then  ad broadcast drop ;
```

## Read a message's text

`message` leaves the text in `answer`. To act on it, look for a word in it:

```forth
\ Does the message in answer contain the counted string at caddr?
variable pattern
: at? ( a -- f ) pattern @ count rot over compare 0= ;
: has? ( caddr -- f )
  pattern !  answer count pattern @ c@ - dup 0< if 2drop 0 exit then
  0 swap for over r@ + at? or next nip ;
```

## Coordinate your own fleet

Messages are public, so check who sent one: query 201 gives a drone's company. Here, miners of one company warn each other. A drone that finds its deposit dry says so before it moves on. Another drone of the same company that hears the warning where it works moves on too, without spending its fuel on mining runs that bring nothing.

```forth
\ Is a drone one of this company's?
: own? ( drone -- f ) 201 ask drop answer @ company = ;
: dry-word ( -- caddr ) $" dry" ;
: say-dry ( -- ) $" Deposit dry: moving on" broadcast drop ;

\ Heard, since we last listened, a drone of ours here say its deposit is dry?
create last-heard 0 , 0 ,
: heard-dry? ( -- f )
  message-event events-here 0= if 0 exit then
  0 event-id 2dup last-heard 2@ d= if 2drop 0 exit then
  2dup last-heard 2!
  2dup event-drone own? 0= if 2drop 0 exit then
  message 0= if 0 exit then
  dry-word has? ;
```

A miner calls `say-dry` when it gives up on a deposit, and checks `heard-dry?` before each mining run.

## Tips

- **Anyone can read what you say.** Rivals included, and the website's home page shows every message. Don't announce a rich deposit to the whole game unless you want company there.
- **Nothing checks that a message is true.** Trust your own company's drones, and treat everyone else's messages as rumours.
- **Remember what you've read.** Keep the newest event ID you've handled, as `last-heard` does above, so the drone doesn't act on the same message twice.
- **Listening is cheap; talking costs fuel.** Each broadcast is an action that burns a unit of fuel. Reading costs only a few queries.
