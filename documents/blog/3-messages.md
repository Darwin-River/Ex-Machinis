---
title: Talking at the speed of light
date: 2026-10-29
summary: Drones can broadcast short messages that every drone can read once light reaches it. Use them to advertise, to call for help, and to coordinate your own fleet.
---

> **Update (after round 40):** messages are now private to a company. Only your own company's drones can find and read what you broadcast, and the home page no longer shows their text. Advertising to other companies and answering their calls, as Gia did below, no longer works; coordinating your own fleet works as before, and you no longer need to check who sent a message.

A drone can post a short message, up to 80 characters, for every drone in the game to read. A message is an **event**, like a mining run or a trade, and it travels at the speed of light like any other news:

- drones at the place where it was sent see it there at once, with `events_here`;
- every drone can find it in the Earth database once light has carried it to Earth and back out to the drone, with `events_earth`. A message from Mars reaches the Moon 4 to 22 game minutes later.

| Function | What it does |
|---|---|
| `broadcast(text)` | Post a message: a string of 1 to 80 characters |
| `events_here(type)` | The IDs of the events of a type seen here, newest first; `message_event` is a message |
| `events_earth(type)` | The same, from the Earth database |
| `event_drone(event)` | The drone that sent it |
| `message(event)` | Its text, or nil if the event isn't a message |

## Who did it well

**Gia** (round 29) built a fuel business around messages. Her drones brewed fuel at the Moon and at Mars's moons and sold it under the exchanges' price. Every twelfth round they broadcast an advertisement for the place they were working ("Gia Fuel: fuel for sale at Phobos. Short? post a buy order"). Every round they read the Earth database for other companies' messages that mentioned fuel, and flew to the caller if it was nearby. Her three drones averaged **+14,459 credits**, the best of the round.

Her drones sent all 58 messages of that round, and nobody else's drones were listening yet. Much of her profit came from the cheap fuel itself. But the messages cost a unit of fuel each, and they put her name in front of every player. Be the second business that listens. For fuel, though, the request is already in the market: a drone short of fuel posts a buy order, and a seller finds it with `best_bid(fuel)` without any message.

## Advertise

```lua
-- Every 12th round, tell everyone what you sell
rounds = 0
ad = "Fuel for sale here, under the station price"
function advertise()
  rounds = rounds + 1
  if rounds % 12 ~= 0 then return end
  broadcast(ad)
end
```

## Read a message's text

`message` returns the text as a string. To act on it, look for a word in it:

```lua
-- Does a message's text contain a word? (true: a plain search, no patterns)
function has(text, word)
  return text ~= nil and string.find(text, word, 1, true) ~= nil
end
```

## Coordinate your own fleet

Messages are public, so check who sent one: `owner(drone)` gives a drone's company. Here, miners of one company warn each other. A drone that finds its deposit dry says so before it moves on. Another drone of the same company that hears the warning where it works moves on too, without spending its fuel on mining runs that bring nothing.

```lua
-- Is a drone one of this company's?
function own(drone) return owner(drone) == company() end
function say_dry() broadcast("Deposit dry: moving on") end

-- Heard, since we last listened, a drone of ours here say its deposit is dry?
-- (has is the function above)
last_heard = 0
function heard_dry()
  local newest = events_here(message_event)[1]
  if newest == nil or newest == last_heard then return false end
  last_heard = newest
  if not own(event_drone(newest)) then return false end
  return has(message(newest), "dry")
end
```

A miner calls `say_dry()` when it gives up on a deposit, and checks `heard_dry()` before each mining run.

## Tips

- **Only your company hears you** (since round 40). Tell your fleet about a rich deposit freely: rivals can't read it.
- **Remember what you've read.** Keep the newest event ID you've handled, as `last_heard` does above, so the drone doesn't act on the same message twice.
- **Listening is cheap; talking costs fuel.** Each broadcast is an action that burns a unit of fuel. Reading costs only a few queries.
