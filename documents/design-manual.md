# Ex Machinis Game Design Manual

_Ex Machinis_ is an online multiplayer space simulation designed to expose players to programming and robotics. In the game, which takes place against the backdrop of the real-time solar system, players program remotely piloted spacecraft to mine, manufacture, and trade goods and services.

The game uses augmented reality to project a parallel universe upon our own. In the game's re-imagined post-war history, world leaders embraced space exploration and colonization as a means of protecting mankind from the global threats that emerged in the wake of the second world war.

This wiki is used to organize the project, which began in August 2017.  It contains multiple sections which describe the game's design and function in great detail. These are the blueprints we follow when coding and creating content.

This document lays out the plans for developing Ex Machinis, an online space simulation, which promotes the acquisition of programming skills.

> **This is the working design manual.** Update it in the same pull request as the code or game data it describes, so the two never drift apart. The game data (resources, protocols, their effects, queries and where minerals can be mined) is generated from spreadsheets in [`game-engine/sql/game-data`](../game-engine/sql/game-data/): change a spreadsheet, run `build_game_data.py`, and commit the regenerated `game-engine/sql/game_data.sql` together with this manual. The query list in [Appendix A](#Appendix-A-In-Game-Queries) is written by that script.

## Table of Contents
* [System Overview](#System-Overview)
  * [Plot](#Plot)
  * [Key Concepts](#Key-Concepts)
  * [Functional Components](#Functional-Components)
    * [Mail Handler](#Mail-Handler)
    * [Game Engine](#Game-Engine)
    * [Physics Engine](#Physics-Engine)
    * [Event Engine](#Event-Engine)
    * [Game Database](#Game-Database)
* [General Mechanics](#General-Mechanics)
  * [All objects are defined by their orbits](#All-objects-are-defined-by-their-orbits)
  * [Game time runs on a master clock](#Game-time-runs-on-a-master-clock)
  * [Information travels at the speed of light](#Information-travels-at-the-speed-of-light)
  * [All ingame events result from actions taken by drones](#All-ingame-events-result-from-actions-taken-by-drones)
  * [Players use FORTH to control their drones](#Players-use-FORTH-to-control-their-drones)
  * [Players advance by developing more complex codes](#Players-advance-by-developing-more-complex-codes)
  * [The Game Engine cycles through player run virtual machines](#The-Game-Engine-cycles-through-player-run-virtual-machines)
  * [Equipment](#Equipment)
* [Players and Resources](#Players-and-Resources)
  * [Player and Drone Tables](#Player-and-Drone-Tables)
    * [Table-users](#Table-users)
    * [Table-companies](#Table-companies)
    * [Table-agents](#Table-agents)
    * [Table-hulls](#Table-hulls)
    * [Table-hull_bonuses](#Table-hull_bonuses)
    * [Table-resources](#Table-resources)
* [Static Objects](#Static-Objects)
  * [The Physics Engine](#The-Physics-Engine)
  * [Units of Measure](#Units-of-Measure)
  * [Elliptic Coordinate Systems](#Elliptic-Coordinate-Systems)
    * [Polar Representation](#Polar-Representation)
    * [Cartesian Representation](#Cartesian-Representation)
  * [Orbital Tables](#Orbital-Tables)
    * [Table-objects](#Table-objects)
    * [Table-abundancies](#Table-abundancies)
  * [The Orbits of Planets and Moons](#The-Orbits-of-Planets-and-Moons)
  * [The Orbital Parameters](#The-Orbital-Parameters)
    * [Gravitational Parameter](#Gravitational-Parameter)
    * [Semimajor Axis](#Semimajor-Axis)
    * [Semiminor Axis](#Semiminor-Axis)
    * [Eccentricity](#Eccentricity)
    * [Mean Angular-Motion](#Mean-Angular-Motion)
    * [The orbital period](#The-orbital-period)
  * [Key-Orbital-Functions](#Key-Orbital-Functions)
    * [Mean Anomaly](#Mean-Anomaly)
    * [Eccentric Anomaly](#Eccentric-Anomaly)
    * [True Anomaly](#True-Anomaly)
  * [Solving Kepler’s Equation with JavaScript](#Solving-Keplers-Equation-with-JavaScript)
    * [Radius at the True Anomaly](#Radius-at-the-True-Anomaly)
  * [Converting the orbital position to Cartesian coordinates](#Converting-the-orbital-position-to-Cartesian-coordinates)
* [Meta Commands](#Meta-Commands)
* [Protocols](#Protocols)
  * [Performing a Protocol](#Performing-a-Protocol)
  * [Process Multiplier](#Process-Multiplier)
  * [Protocol ID](#Protocol-ID)
  * [Protocol Parameters](#Protocol-Parameters)
  * [Returned Value](#Returned-Value)
  * [Aborted Actions](#Aborted-Actions)
  * [Action Tables](#Action-Tables)
    * [Static Action Tables](#Static-Action-Tables)
      * [Table-protocols](#Table-protocols)
      * [Table-event_types](#Table-event_types)
      * [Table-resource_effects](#Table-resource_effects)
      * [Table-market_effects](#Table-market_effects)
      * [Table-location_effects](#Table-location_effects)
    * [Dynamic Action Tables](#Dynamic-Action-Tables)
      * [Table-actions](#Table-actions)
      * [Table-events](#Table-events)
      * [Table-transmissions](#Table-transmissions)
  * [The Perform Function](#The-Perform-Function)
  * [Ad Hoc Protocols](#Ad-Hoc-Protocols)
  * [Launching Drones](#Launching-Drones)
  * [Tips for creating protocols](#Tips-for-creating-protocols)
* [The Event Engine](#The-Event-Engine)
    * [Tableobservations](#Table-observations)
* [Markets and Trade](#Markets-and-Trade)
  * [Placing Market Bids](#Placing-Market-Bids)
  * [Acting on Market Bids](#Acting-on-Market-Bids)
  * [Adding and removing credits from a user's bank account](#Adding-and-removing-credits-from-a-users-bank-account)
  * [A Trade Example](#A-Trade-Example)
* [Queries](#Queries)
  * [Database Searches](#Database-Searches)
    * [Running a Query](#Running-a-Query)
    * [The Queries Table](#The-Queries-Table)
      * [Table queries](#Table-queries)
    * [Query Variables](#Query-Variables)
    * [The Query Algorithm](#The-Query-Algorithm)
    * [First Query Example](#First-Query-Example)
    * [Second Query Example](#Second-Query-Example)
* [Appendix A: In-Game Queries](#appendix-a-in-game-queries)

## System Overview

### Plot
Ex Machinis takes place in a parallel universe where technology has advanced to the point where humans are using drones to mine and exploit the riches of the solar system. In an effort to spur extraterrestrial industries, governments are awarding citizens (new players) one-time grants of money and drones to take part in this economic expansion.

Given the high cost and risk of manned spaceflight, economic expansion into the solar system has come to be dominated by fleets of remotely controlled drones. Drones can accelerate and decelerate much faster than manned spacecraft allowing them to move quickly between distant points in space. Furthermore, drones require far less infrastructure than manned missions and fleets of drones can be effectively managed by a single programmer.

Much of the physical labor of drones is accomplished by smaller self-propelled bots which specialize in localized tasks like mining, construction, and repair. Drones are typically outfitted with pods, which launch and control bots that are equipped to perform specific tasks. Fleets of drones drive the modern economy by mining, refining, and manufacturing goods and technologies which benefit a largely Earth-based human population.

Although faster than light communications exist, they are much too expensive and cumbersome to be broadly deployed.  Therefore they are generally only used to span the gaps between the earth and deep space trading hubs.

[Return to the TOC](#Table-of-Contents)

### Key Concepts
Ex Machinis is an augmented reality space simulation in which players program and control interstellar drones in real time. Consequently, the simulation is able to adhere to several key principles:
* Continuous gameplay. The player’s drones always remain in play and continue to follow their programs even while the owner is offline.
* Realistic time scales.  Although advanced technologies are able to compress the time it takes for drones to travel long distances and complete industrial activities, it is still expected that many activities will span the course of hours and days. A master time scale lets game time run faster than real time to keep the game playable (see [Game time runs on a master clock](#Game-time-runs-on-a-master-clock)).
* Communications limited to the speed of light. Communications with distant drones is not instantaneous.

[Return to the TOC](#Table-of-Contents)

### Functional Components
Ex Machinis has several functional components which operate independently to support gameplay.

#### Mail Handler
**Removed (#367 step 5).** There is no email: no mail server, no `/get-mails` mail processing, no registration, no welcome or report emails. Commands reach drones only through the `commands` table (a release on the website, or the engines' own resumes and restarts), and replies go to `drone_messages`. The engines' email code stays but is off (`EMAIL_ENABLED` defaults to "0"). The rest of this section, and the meta-commands below, describe the original email game.

The Mail Handler application runs continuously in the database to process incoming and outgoing emails.
Incoming emails will be entered as new events in the Central Database and directed to the appropriate drones.
Outgoing emails, generally originating from in-game drones, will be pulled from the events log at the appropriate time and forwarded to the owner of the drones.

[Return to the TOC](#Table-of-Contents)

#### Game Engine
The Game Engine is a server based application that runs constantly in the background to retrieve, process, and update game data in the Central Database.  GE activities include:
* Processing the compiled FORTH routines that are running in each of the drones.
* Resolving the in-game actions taken by each of the drones in response to their programs.
* Routinely updating the orbits of celestial data with information with data from a public registry.

[Return to the TOC](#Table-of-Contents)

#### Physics Engine
The Physics Engine is a small C++ application, which runs continuously in the background to compute and record the cartesian coordinates of orbiting bodies.

#### Event Engine
The Event Engine is a small C++ application, which runs continuously in the background to identify breaking events, check for errors, and determine which drones will become aware of these events.

[Return to the TOC](#Table-of-Contents)

#### Game Database
The Game Database is a server-side database that holds all game-related data. The database facilitates communications between the Mail Handler, Game Engine, and other applications that are running on the server.  It is also a rich source of data for players and their drones.

The schema starts from `game-engine/sql/create_db.sql` and `game-engine/db-backup/exmachinis_tables.sql`. Every later change is a numbered migration in `game-engine/sql/migrations/`, applied in order by `game-engine/sql/migrate.sh`, which records each in the `schema_version` table. In Docker the `migrate` service runs it on every start, before the website and engines, and then loads `game_data.sql`; CI runs it before the tests. The engines no longer change the schema themselves. See the migrations folder's README for how to add one.

[Return to the TOC](#Table-of-Contents)

## General Mechanics

### All objects are defined by their orbits
Every natural and manmade object in the game will be in orbit around the sun, a planet, or a moon.  Static objects are bodies that are locked in a fixed orbit, which does not change during the game.  Drones are dynamic objects, which can move from one orbit to the next. The Physics Engine routinely updates the cartesian coordinates of static and dynamic objects based on their orbital parameters.

[Return to the TOC](#Table-of-Contents)

### Game time runs on a master clock
Game time can run faster than real time, so that trips and industrial processes measured in days take minutes or hours to play. One master setting, `GAME_TIME_SCALE` (game seconds per real second, default 60: one real minute is one game hour), governs everything in the game that takes time:

* **Orbits.** Bodies are placed where they are at the current *game* time.
* **Actions.** Effect times in the action tables are game seconds. An event is scheduled at a game time (`events.game_t` = game now + its delay) and comes due when game time reaches it.
* **Travel.** See below.
* **Signals.** The light-speed delay of commands and observations is the light time in game seconds (see [Information travels at the speed of light](#Information-travels-at-the-speed-of-light)).

The clock is kept in the `game_clock` table so both engines share it: *game time = game_anchor + (real now − real_anchor) × scale*. The Game Engine reads the scale from its configuration (environment variable `GAME_TIME_SCALE`) when it starts. If the scale has changed, it re-anchors the clock at the current game time, so game time, and every orbit, carries on without a jump. Game time starts equal to real time; at any scale above 1 the game's planets run ahead of the real sky. The world's first game second is kept as `game_clock.epoch`, and every change of scale is recorded in `game_clock_history` (real time, game time and the new scale), so charts can map game time to real time.

Everything the engines schedule or compare is stored in game time (game seconds, counted like unix seconds) and compared with the game clock, never with the computer's clock, so it stays right if the scale changes while it waits. Both engines reload the clock every cycle.

<a name="The-Clock-Floor"></a>**The clock floor (#324 step 20).** The clock aims for `GAME_TIME_SCALE` but slows when the engines can't keep up, never below `GAME_TIME_FLOOR` (Public Forge: 24 and 8; `0`, the default, means it never floats). A slower clock makes every action take longer in real time, so drones ask for fewer slices and events a real second; computing doesn't slow, but it costs fuel. Once every real minute (`CLOCK_CHECK_SECONDS`) the Game Engine measures how far behind the engines run, in real seconds: the commands that may run now, at the rate it ran them over the minute, or the oldest due event the Event Engine hasn't done, whichever is later (`clock_floor_lag`). Then (`clock_floor_next`, in `clock_floor.c`):

* more than `CLOCK_LAG_HIGH` behind (60 s): the scale drops by a quarter, to no less than the floor;
* less than `CLOCK_LAG_LOW` behind (15 s): it rises by a quarter, to no more than the target;
* in between: it holds.

Each change re-anchors the clock as above (and is written to `game_clock_history`). Behind by more than `CLOCK_LAG_HIGH` already at the floor, the world is **full** (`game_clock.is_full`), until it is less than `CLOCK_LAG_LOW` behind. The check is recorded in `game_clock` (`target_scale`, `floor_scale`, `lag_seconds`, `slices_per_minute`, `is_full`, `load_checked_at`; migration 0015), and the Home page shows the clock live.

While the world is full, the Release page puts new releases in `launch_queue` instead of launching them, and tells the player their place in line; a waiting release keeps its company name. The scheduler's tick (`/tick`, every few seconds) launches waiting releases in order, at most 5 a time (`LaunchQueue::drain`), while the world isn't full. A load check more than 5 real minutes old (the Game Engine stopped) doesn't count, so releases aren't held on old news.

**Travel time.** Drones travel with constant acceleration, speeding up to the midpoint and slowing down after, so a trip of distance *d* takes *t = 2·√(d ÷ a)* game seconds. *d* is the distance between the two bodies when the drone leaves (at game time), and *a* is `TRAVEL_ACCELERATION` (m/s², default 1). Every effect of the travel protocol at or after its move (the location effect) is delayed by the trip time, so the engine stays locked until arrival.

Since actions are short and only one runs at a time (see [The Game Engine cycles through player-run virtual machines](#The-Game-Engine-cycles-through-player-run-virtual-machines)), events of one action that land in the same real second at a high scale are still processed in game-time order: the Game Engine inserts an action's events sorted by game time, and the Event Engine processes an action's same-second events in that order. At equal times the action's own fuel cost comes first (`Event_t.burn`: fuel, a fixed amount, from the acting drone; fuel that a push or pull moves is not the cost), then each equipment check (a protocol takes a module out and puts it straight back to show it is aboard) with its two halves together, then the other depletions, then the rest. A failure aborts the rest of the action, so this order means a failure never strands a module out of the hold. Before #380 the fuel burn sat between a check's two halves, and a drone short of fuel lost the module for good. And a pull of fuel put the seller's fuel first: the Event Engine runs a transfer's two halves at once, so the delivery landed before the buyer's 1-fuel cost, which then overwrote it and the buyer never paid.

**Precision.** Game times are whole game seconds, and the engines check for due work every few seconds (`DB_READ_TIME` and `ENGINE_CHECK_TIME`, 5 s by default). At high scales, short actions therefore complete within one engine cycle, and messages can arrive up to a cycle after their light time.

**Where it is stored.**
* `game_clock`: the anchors and the scale.
* `game_clock_history`: every change of scale.
* `events.game_t`: when each event takes place, in game time; `events.timestamp` holds the same moment in real time, for the website. An event inserted without a `game_t` (by the website, such as a new drone's starter kit, or by a script) gets the current game time from the `events_game_t` trigger.
* `observations.game_t`: when a drone (or the Central Database) sees an event, in game time; `observations.time` is the real time, for the website.
* `trades.game_t`: when each trade happened, in game time.
* `commands.deliver_at`: when a player's command reaches its drone, in game time.
* `outbox`: replies on their way to Earth.
* `agents.waiting_action`: the action a drone's script is waiting for.
* `agents.pending_input`: the lines a paused script hasn't read yet.

Examples at the defaults (scale 60, 1 m/s²):

| Trip | Game time | Real time | Light delay (real) |
|---|---|---|---|
| Earth to the Moon | 10.9 hours | 11 minutes | under a second |
| Earth to Mars, closest (0.52 au) | 6.5 days | 2.6 hours | 4 seconds |
| Earth to Mars, farthest (2.67 au) | 14.6 days | 5.9 hours | 22 seconds |
| Earth to Jupiter (5.2 au) | 20 days | 8.2 hours | 43 seconds |
| Earth to Neptune (30 au) | 49 days | 19.6 hours | 4.2 minutes |

[Return to the TOC](#Table-of-Contents)

### Information travels at the speed of light
A drone will not know what is happening at a distance until the light or radio transmission actually reaches it.  This can take minutes to hours depending on the drone’s distance from the observed event. Ex Machinis will handle this phenomena via the tEvents and tObservations tables in the Central Database.

Players are on Earth, so their email to and from drones travels at the speed of light too, scaled by the game clock (see [Game time runs on a master clock](#Game-time-runs-on-a-master-clock)):

* **Player to drone.** When a command is queued (a released script; in the email game, a player's mail), the Game Engine sets `commands.deliver_at` to the game time its signal reaches the drone: game now + the drone's distance from Earth ÷ c. The command runs only after that (meta-commands such as `<abort>` too; engine resumes are immediate).
* **Drone to player.** A reply is written to `drone_messages` with `game_t` set to when the drone's signal reaches Earth, the same light time later; the website shows it from then on. (In the email game it was held in the `outbox` table until then, and emailed.) The reply's first line still gives the drone's distance in light-minutes of game time.
* **Observations.** An event reported to the Central Database is observed the drone's light time from Earth later, in game time (`observations.game_t`).

The Observation Engine is responsible for coordinating the discovery of events by distant drones.
Every time something occurs in the game it will be recorded in the tEvents table along with the location and time of the event.  

The following are examples of recorded events:
* Drone departs or arrives in an orbit.
* Drone starts or stops mining an asteroid.
* The owner emails FORTH commands to a drone.
* The drone emails program output to the owner.

Drones will only be aware of those events that directly affect them, events that are directly observable, or events related to other drones that they are specifically monitoring on their scanners.  The links between drones and observable events will be held in the tObservations table and managed by the Observation Engine.

[Return to the TOC](#Table-of-Contents)

### All ingame events result from actions taken by drones
Drones use onboard resources to perform actions that result in recorded events.  Each action that is performed by a drone will generally:
* Require a particular piece of onboard equipment (an installed resource) to perform;
* Consume a certain quantity of resources at the start of the action (ie. burn fuel, consume minerals, use up ammunition);
* Take a defined amount of time to perform;
* Change a drone’s location and/or inventory when complete.

The Game Engine will respond to specific FORTH commands by recording events associated with the beginning and end of an action in the Events and Observation Tables, respectively.  These events will dictate changes in a drone’s status that occur at the start and stop of the activity.

Examples of some FORTH initiated activities activities that can be performed by drone include:
* Moving to another orbit
* Transferring cargo from one drone to another
* Mining and enriching ores
* Using refined metals to produce components for new drone

[Return to the TOC](#Table-of-Contents)

### Players use FORTH to control their drones
FORTH is a compact, efficient, and very powerful programming language that uses stacks and operator-defined words to organize complex programs.  Each user manages her fleet of drones by defining and applying FORTH words (functions) in drone-specific programs. These words reside in dictionaries belonging to each drone.

In the game, a drone's eForth interpreter is best understood as a **sequencer**: it decides how one in-game action follows another. Every in-game action is started by `perform`, and the script waits while the action takes place. The script only runs in between actions, to look at how the last action went (the value `perform` leaves on the stack), query the drone's surroundings and decide what to do next. A drone therefore performs one action at a time, and the time a script spends computing is negligible next to the game time its actions take.

Generally, a user will test out new routines on a single drone before uploading them to other drones in his fleet.
Programs are usually tested on drone operating nearby to reduce the delay associated with messages traveling long distances through space.

[Return to the TOC](#Table-of-Contents)

### Players advance by developing more complex codes
A new player starts by sending simple real-time commands to drone that are operating in his vicinity.  However, as the player expands his operation in search of resources and opportunities, the commands necessarily become more complex.  This complexity results from the challenges associated with:
* The demands of simultaneously operating multiple drones.
* Communication delays associated with drone operating light minutes away from the player.
* The need to have drones carry-on activities when the player is not online.

FORTH is well suited for this sort of progressive engagement because the player develops and manages an increasingly complex dictionary of words that she can can use to command her drones. These commands can be very simple at first, requiring constant user intervention to pilot the drone. However, the drone’s vocabulary can quickly expand to include more complex commands as the player becomes a sophisticated operator. In theory, there are no limits to the amount of autonomy and complex decision making the player can off-load onto her drone.

[Return to the TOC](#Table-of-Contents)

### The Game Engine cycles through player-run virtual machines
Since there will be a rather large number of drones running FORTH programs at any given time, the Game Engine (GE) cycles through their virtual machines. Work for the GE is queued in the `commands` table: a player's emailed script, or a "resume" entry for a script that has paused. Each cycle (every `DB_READ_TIME` seconds, 5 by default) the GE takes the oldest command it may run, loads that drone's VM from `agents.vm`, runs it, and saves the VM again. The VM holds everything needed to carry on later: the stacks, the dictionary, variables and where the script stopped.

A drone is always in one of these states:
* **Idle:** no script is running. A new script from the player starts at once.
* **Running:** a script has used up its slice of instructions (`MAX_CYCLE_INSTRUCTIONS`, 100,000 by default) and paused. The GE queues a resume and continues it on a later cycle, so long scripts don't hold up other drones. The VM reads a script one line at a time; when it pauses (here or while waiting, below), the lines it hasn't read yet are kept in `agents.pending_input` and fed back to it when it resumes, so the script carries on with its next line. What the script prints is collected (`agents.output`) and emailed in one message when it ends.
* **Waiting:** the script called `perform` and the action it started has not finished. The script stays paused, with `agents.waiting_action` set to the action's ID, until every event of that action has been processed by the Event Engine (or the action is aborted). Only then is the drone's resume taken from the queue: `perform` leaves its result on the stack (see [Returned Value](#Returned-Value)) and the script carries on. The GE doesn't load a waiting drone's VM to check on it; the queue simply skips its resume until the action is done.

So a drone performs one in-game action at a time, and its script always knows how the last one went before deciding what to do next. While a drone is running or waiting, a new script waits its turn: the next-command query holds it until the drone has no resume queued (#367; it used to be refused with "Another command is running, retry later", which dropped the rest of any released script longer than one 2,048-character command). `<abort>` and the other meta commands are never held (see [Meta Commands](#Meta-Commands)).

<a name="Engine-Throughput"></a>**Throughput (#324 step 20).** The load generator (`php artisan forge:load-test N --database=...`, never the live database) releases N drones running the starters in turn, through the same path as the Release page, and `--report` shows how the engines keep up. It found four limits, now gone:

* The Game Engine slept `DB_READ_TIME` (5 s) after every command, so it ran one slice per 5 seconds. It now sleeps only when there was nothing to run.
* Loading a drone's saved VM first set up a fresh one (callback words, word library, game words: millions of instructions) and then overwrote all of it with the saved core. It now just loads the core.
* The Event Engine did one batch of `OUTCOME_EVENT_BATCH_SIZE` (10) events per `ENGINE_CHECK_TIME` (5 s). It now runs the next batch at once while batches come back full.
* Every observable event got one row in `observations` per drone where it happened, so a thousand drones in one place meant a thousand inserts an event, and the table grew as events × drones (12 million rows in 12 real minutes). Now nothing is stored per drone for it: see [Local sightings](#Local-Sightings).

**Measured** (one 4-core sandbox, MySQL in Docker, 1,000 starter drones): before the fixes, 12 slices a real minute; after them, about 4,200 slices and 4,500 events a real minute in steady play, with the engines 4 to 9 real seconds behind at a 600× clock (the floor's governor dipped to 337× while all 1,000 drones arrived at the Moon at once, and was back at 600× within three minutes). So Public Forge's target of 24× has room to spare at this size. One cost grows with the world and is left for later: every drone's 64 KB VM is read and written each slice, which is the main cost left as the world grows.

**Aging worlds (#380).** A world slows down as its `events` table grows, because two queries read every event. Both have indexes since migration 0020:
* `sqInventory` (what a drone holds: the `units` game word, query 212, the House's assessments) finds each drone's latest inventory event per resource, installed and locked state. Index `events_inventory` (drone, resource, installed, locked, outcome, event_type) lets MySQL read one index entry per group. In a 90-day test world with 440,000 events, a call fell from about 136 ms to 16 ms, and MySQL's load from two cores to under one.
* The Event Engine's "next due events" (outcome 0, by game time) read and sorted every event; index `events_pending` (outcome, game_t) reads only the pending ones.
* A drone's latest event before a game time, which the Event Engine asks for with every event, walked back through every drone's events by game time; index `events_drone_game_t` (drone, outcome, game_t). The latest sell or buy order of each drone (queries 600 to 612) took about 630 ms at day 90; index `events_orders` (event_type, outcome, resource, drone) brings it to about 4 ms.

**Pauses.** When it finds nothing to do, the Game Engine sleeps `DB_READ_TIME` and the Event Engine `ENGINE_CHECK_TIME`, both 1 second since #380 (5 before). Each action passes through both engines, so a drone could wait up to about 10 real seconds per action just on these pauses, with both engines nearly idle: at 600 game seconds a second that was over an hour and a half of game time. On a live server at 24, 10 seconds is 4 game minutes; 1 second costs a few cheap queries a second.

**Memory (#380).** Valgrind found the engines leaking: about 7 MB a real minute (the Game Engine reached 1.9 GB in 3 hours) and about 450 MB a game day (the Event Engine). `embed_free` (`embed_util.c`) cleared the VM struct before freeing its core, so every VM leaked 64 KB; three lookups (`db_get_agent_engine_info` after a command, after a rename, and for the light-delay query tag) loaded a VM they never freed; and `event_update_new_cargo_and_quantity` never freed the resource list it fetched. `db_scape_string` also wrote a byte past its buffer for empty input. All four are fixed; under valgrind both engines now lose nothing but a few small blocks.

<a name="Local-Sightings"></a>**Local sightings.** A drone sees an observable event when it happened where the drone is now, since the drone arrived there. The Event Engine records where an observable event happened (`events.place`, the object its drone was at when it was processed), and each drone keeps when it reached where it is (`agents.arrived_t`, game time, set by the triggers `agents_arrived_insert` and `agents_arrived_update` whenever a drone is made or its `object_id` changes, whatever makes the change; migration 0016). Query 803 and the website's event list work the sightings out when asked: `e.place = drone.object_id AND e.game_t >= drone.arrived_t AND e.game_t <= game now`. So a drone forgets what it saw at a place once it leaves, as it only sees live orders where it is. The `observations` table keeps the rows that are one per event: the acting or affected drone's, and the Central Database's (drone 0), which wait for the light to reach Earth.

Also: `db_reconnect` checks the connection at most every 5 seconds instead of before every query; the engines' logs are quiet at `APP_TRACE_LEVEL -1` (only lines with ERROR, WARNING or NOTICE; Docker's default), since every step logged at level 0 is gigabytes a day at full load, and rotated logs are removed after 7 days; and the Docker database runs without a binary log and flushes its log once a second (`--disable-log-bin --innodb-flush-log-at-trx-commit=2`): a thousand drones saving their 64 KB VMs wrote gigabytes of binary log an hour.

**Instruction metering.** A slice is measured in FORTH instructions, not seconds: the VM counts every instruction it runs, and the slice ends when the count reaches `MAX_CYCLE_INSTRUCTIONS`. Each `query` costs a flat `QUERY_INSTRUCTIONS` (1,000 by default) and each `perform` a flat `ACTION_INSTRUCTIONS` (1,000), since their real cost is database work. So the same script always runs the same instructions per slice, however fast the server is, and test runs match live ones. Each drone's running total is kept in `agents.instructions`. The engine's own set-up code (loading the callback words, checking for a word) isn't metered.

<a name="Doubles"></a>**Double cells (#324 step 9).** Cells are 16 bits, so money and volumes are doubles: two cells, the high cell on top.

* **Words.** Every drone's VM gets the standard word library (`vm_library.c`), the standard Forth double-cell words: `um* um+` (in the VM's system words, which players don't see, so the library makes them visible), `s>d dnegate d+ d- dabs d0= d0< d= d< du< 2swap 2over d> dmax dmin m* m+ ud. d.`. `vm_add_callbacks` loads it, unmetered, into a VM that doesn't have `d.` yet. A saved VM keeps it, so it is added once.
* **Query results** with `d_` columns, and **query inputs** through `[dvalue_N]` tags (see [Queries](#Queries)).
* **Protocol parameters.** `protocols.double_params` is a bit mask (bit 0 = parameter 1) of the parameters taken off the stack as doubles: high cell first, then low (`protocol_read_parameters`). Sell (17) and buy (18) "double price" are protocols 7 and 8 with the price, parameter 3, a double (mask 4), for prices past 65,535. Migration 0008 adds the column. The protocols loader names its columns now, rather than `SELECT *`.
* **Tests.** `test_double.c` checks the words past 32,767 and 65,535.

<a name="Game-Words"></a>**Game words and starter scripts (#324 step 15).** After the double-cell words, every new drone's VM gets the game words (`vm_library_game_source` in `vm_library.c`, loaded by `vm_add_callbacks` once, when the word `stdlib` is missing, after the `perform` and `query` callbacks they use). They are ordinary definitions, so the language doesn't change; they make scripts readable:

* **Constants** for places (`earth` 3, `moon` 10, `mars` 4) and resources (`fuel`, `magmite`, `kunspar`, `yalspar`, `vladnium`, `wolfite`, `metals-a1` to `metals-a3`, `inorganics-a1`, `organics-a1`).
* **Actions**, each leaving `perform`'s result: `unpack`, `travel ( place )`, `mine ( mineral )` (protocol mineral + 10000), `refine ( mineral )` (+ 20000), `make ( resource )` (+ 10000), `wait ( minutes )`, `sell-to-station ( n resource )` (31), `buy-from-station ( n resource )` (32), `sell-order ( price keep resource )` (7), `buy-order ( price want resource )` (8), `push ( n resource drone )` (10), `pull ( n resource drone )` (11), `launch ( drone kit )` (12), `plunder ( wreck )` (34), `jettison ( n resource )` (1: Dump resource, which takes a quantity since #380, n units for 1 fuel, like push and pull; it is not called `dump` because eForth's `dump` prints memory).
* **Queries**, through a 120-byte buffer `answer`: `ask ( [n] id -- rows )`, `me` (205), `location` (202), `units ( resource -- n )` (212), `company` (201), `credits ( -- d )` (101), `deposit ( mineral -- n )` (640: units left here, 0 if none), `today ( -- day )` (207: the game day now, #380), and `sell-all ( resource -- )`, which offers all of it to the station here.
* `stdlib` leaves the library's version, 3 (2 before `today`; a drone keeps the library it was released with).

Three **starter scripts** in `examples/starters/` (miner, refiner, trader; the Release page offers them with `?starter=name`, `Release::STARTERS`) use these words, each with comments that explain it:

* **Miner:** flies to the Moon, mines kunspar 20 units at a time, refines it and sells the metals to the Moon Depot (copies spread out: a third at the Moon, the rest at Phobos and Deimos, selling at the Mars Exchange). At the Moon it first offers its metals to other drones through standing sell orders at 3 over the Depot's price (23, 33 and 43), and sells to the Depot only once 16 are aboard (#367). When it sells at an exchange and has less than 50 fuel, it buys 30 there from the House. It gets fuel when it has less than 40, which leaves enough to brew it (a brew burns about 15; at 25, Mars-side copies hopping between Phobos and Deimos ran dry). Otherwise, for fuel it buys from another drone's sell order on the Moon when one is at 4 or less, and otherwise brews its own (yalspar for organics, vladnium for inorganics, then Make Fuel). It waits while a deposit refills.
* **Refiner:** brews fuel on the Moon the same way, keeps a reserve and sells the rest through a standing sell order at 4 a unit; the metals from refining go to the Moon Depot.
* **Trader:** works the Moon and doesn't mine (#367). It buys metals from other drones' sell orders there (the Miner's) at up to 7 under the Mars Exchange's price (23, 38 and 53), resting 2 game hours when nobody sells. With 60 metals aboard, or 20 after 24 looks at the market (a trader that buys a little at a time still sets off), it hauls them to Mars and sells them to the Mars Exchange, at most 15 of each metal an hour for up to 6 hours (a fuller batch pays less; what is left goes on the next trip), then comes back. It buys its fuel (20 before each trip) from the House at the exchanges. (Before #367 it waited for 60 fuel, which the Mars-side copies never reached, and for 40 metals it mostly mined itself: in a load test no trader sold anything in 23 game days.)

Each one can earn alone, and the miner and refiner trade fuel when both are on the Moon. Found while running them: Mine yalspar (11110) yielded kunspar (from `economy.xlsx`); `build_game_data.py` now makes every mining protocol yield its own mineral.

**Loading a script is free (#380).** The VM reads text slowly: each word it reads (a name, a number, a comment's `\`) costs about 20,000 instructions, most of them looking the name up in the dictionary, and a comment line about 30,000. Compiled code then runs fast. Until a released drone first runs `launched` (when `release_vm` is saved) it is only reading its program, so `db_take_power_due` marks those instructions paid instead of billing them; `agents.instructions` still counts them. Billed at `POWER_INSTRUCTIONS`, a starter script cost 13 to 25 fuel to load, but a 150-line script cost more than the 100 fuel a drone starts with: in round 21 four newcomers' drones died before their `launched` ran. A clone gets the compiled program and reads nothing.

<a name="Fuel"></a>**Fuel (#324 step 8).** Fuel (A1), resource 2061 (mass 1), is burned two ways:

* **By actions.** Every protocol's game data has a resource effect at time 0 that uses up fuel from the drone's hold. If the fuel isn't there, the action fails like any missing input (`perform` leaves -2). The cost is per action, whatever the multiplier: pushing 50 units burns as much as pushing 1 (`protocol_process_resource_effect` doesn't multiply fuel effects). Each protocol's description ends "Burns N fuel."

  | Actions | Fuel |
  |:--|--:|
  | Unpack Starter Kit I (25901) | 0 |
  | Dump, sell and buy orders, push, pull, import, export, plunder, station offers and orders | 1 |
  | Mining (11xxx) (2 until #324 step 15) | 1 |
  | Refining, alloys, organics, polymers, fuel (21xxx, 12xxx) | 2 |
  | Parts (13xxx) | 3 |
  | Systems (14xxx) | 4 |
  | Travel (5001) | 5 |
  | Modules (15xxx) | 6 |
  | Ship kits (16xxx) | 8 |
  | Launch (12): 10 burned and 20 for the clone ([Cloning](#Cloning)) | 30 |
  | Wait (3) | 0 |

* **By computing (power).** After each slice the Game Engine works out the whole units of fuel the drone owes: (`agents.instructions` − `agents.power_paid`) ÷ `POWER_INSTRUCTIONS` (game-engine.conf, 100,000; 0 makes computing free; `vm_meter_fuel_due`). It marks them paid and burns them with protocol 2, "Burn power" (`[N 2 perform]`, one effect using up N fuel). The engine plans that action itself (`protocol_burn_power`), and the script doesn't wait for it. The rest of the instructions carry over to the next bill. If the burn fails for lack of fuel (outcome -2), the events engine makes the drone a wreck (`db_wreck_drone`: `agents.wrecked_at`, `wreck_cause` = `fuel`), and a wreck runs no more commands. A burn that fails for any other reason only leaves that bill unpaid. Taking something out of the hold never fails the cargo-space check (`event_no_cargo_condition`), so a drone over its capacity can still burn fuel.

Fuel is made from Organics and Inorganics with protocol 12061, "Make Fuel (A1)": 1 Organics (A1) and 2 Inorganics (A1) make 30 Fuel (A1) with a Chemical Reactor in 2 game hours. Refining 20 magmite gives exactly those inputs. A new drone (registration, release, test run) starts with `forge.starting_fuel` (100) in its hold, next to the packed starter kit (375 of hull 1's 500 cargo). It is loose in the hold, because a released script needs power while it loads, before the kit can be unpacked. A launched drone's hold starts empty: its owner must push it fuel before its computing bill comes due.

<a name="Wrecks"></a>**Wrecks and plunder (#324 step 12).** A drone becomes a wreck for good when it runs out of fuel, crashes too often in a row, or its company is eliminated for not paying its tax (`agents.wrecked_at`, `wreck_cause`). A wreck refuses commands and does nothing more, but everything aboard it stays there: its hold and its installed and locked parts. Another player's drone at the same place can plunder it once:

* **Protocol 34, "Plunder wreck":** `W 34 perform`, where W is the wreck's drone ID. It burns 1 fuel and takes 1 game hour; its resource effect is a plunder event (type 11) that carries W in `new_quantity`.
* **The events engine** (`db_plunder`, `db_wreck.c`) checks the event when its time comes: W must be a wreck, at the plunderer's `object_id`, not plundered yet (else outcome -15, `OUTCOME_NO_WRECK`). Whose wreck it is doesn't matter (#367 step 1; own-player wrecks were refused with -13 before). It then marks the wreck plundered (`plundered_at`, `plundered_by`), so a wreck is plundered once even if two drones try.
* **Everything leaves the wreck:** one action of protocol 35, "Plunder haul" (which nobody performs), with a decrement event for each resource in the hold and among the installed and locked parts (the latest totals, as `sqInventory` reads them).
* **The plunderer gets its share** (`house_rules.plunder_share`, 50%) of each resource's total, rounded down; the odd part-unit comes too with the matching chance, fixed for each wreck and resource (`wreck_share`, `wreck.c`), so a single part comes away whole from about half of the wrecks. Each resource arrives as its own protocol 35 action with an increment event, which the events engine checks like any other: what doesn't fit in the hold fails with -3 and is lost. What the plunderer doesn't get is destroyed, so wrecks are a sink.
* Queries 660 ("Wrecks here") and 661 ("Wreck hold") show the wrecks where the drone is and what is aboard one.

<a name="Taxes"></a>**Taxes and company end (#324 step 12).** Every company pays a tax bill each `tax_period` (a game day): `tax_flat` (0) plus `tax_permille` thousandths (0.5%) of what it made: its **assessed wealth**, its credits plus the goods aboard its working drones at the House price (the `company_assessed` view, migration 0018, valued as the leaderboard values them; a packed Starter Kit I counts as its contents), less its starter package, never below 0 (the `company_taxable` view, migration 0019). The package is `house_rules.start_credits` (1,000) and `start_fuel` (100) at the House price, plus a packed Starter Kit I; they must match `forge.starting_credits` and `forge.starting_fuel`. So the tax grows with what a company made, credits or goods, and a fresh company pays nothing. (Migration 0018 taxed the whole assessed wealth: the kit's equipment, about 3,000 credits at House prices, made a new company pay about 20 credits a game day, more than most earned.) (Before #367 it was 20 credits plus 0.5% of the player's combined credits.) A company whose bill is more than its credits can't pay, even if it holds goods: it is eliminated. The events engine charges it (`db_tax_due`, `db_wreck.c`) after the station batches, in its own transaction:

1. A company it hasn't seen yet gets `founded_t` and `taxed_t` = now: its grace period (`tax_grace`, 3 game days) and its first tax period start.
2. When a company's period has passed, it gets one bill. Periods missed while the engine was down are skipped, not charged all at once. In its grace period the company pays nothing.
3. A company with the credits pays: they vanish (taxes are a sink), and `company_taxes` records the bill.
4. One that can't pay is **eliminated**: its credits vanish, `eliminated_at` is set, and its drones become wrecks (`wreck_cause = tax`) that other players can plunder.

**A company also ends when its last drone dies** (#324 step 13): whenever a drone becomes a wreck (`db_wreck_drone`, for any cause), a company left with no working drones ends the same way, with `end_cause = drones`: its credits vanish. A company eliminated for its tax has `end_cause = tax`.

Query 650 ("My taxes") gives the drone's company's next bill (2 cells), the game hours until it is due and the game hours of grace left. `test_wreck.c` checks the arithmetic of bills and shares.

**Event order.** The Game Engine reads the game clock once per loop, so on a fast clock the events engine can be ahead of it. An event stamped earlier than one already processed would be worked out from older state and undo it. Also, `sqInventory` takes a drone's highest event ID as its current state, so IDs must keep game-time order. So `protocol_flush_events` reloads the clock and starts each new action a game second after the drones' latest event, done or still to come (`db_get_latest_processed_game_t`). A script waits for its own action anyway, so in practice this mostly delays a power bill until the current action ends.

When the GE runs a `perform`, it computes the times and resource requirements of the in-game activity (change orbits, mine ores, transfer cargo, etc.) and logs the events that will happen, which the Event Engine processes when they are due.

*Planned, not built:* a "passive" state in which a script pauses (via a CatchEvent word) until the drone observes a particular event.

[Return to the TOC](#Table-of-Contents)

### Equipment
The following types of modules can be added to drone hulls and used to perform various activities.  Each of class of module consists of variants which perform differently.
* Power plants generate the energy used by the ship’s modules.
* Fuel tanks contain the reactants that are consumed by power plants.
* Propellent tanks store the gases that are energized and expelled by the engines to accelerate the drone.
* Engines energize and release the propellant to accelerate the ship.
* Fuel scoops skim the atmospheres of planets to fill the fuel and propellant tanks.
* Transfer bots shuttle cargo between adjacent drones.
* Mining bots are used to extract ores from asteroids and comets.
* Refineries process ores in to minerals.
* Forges turn raw materials into functional parts by following blueprint instructions.
* Construction bots are required to install modules in drone hulls.

[Return to the TOC](#Table-of-Contents)

## Players and Resources

### Player and Drone Tables
The following tables are used to record basic data about the players, their drones, and the onboard resources.

#### Table: users
The Users Table will hold information specific to each player account.
* id [INT(5)] -  This is the table index. Max 65,534 (FFFF).
* email [VARCHAR(255)] - The email that the player uses to communicate with her drones.
* name [VARCHAR(45)] - This is the in-game name that is used by the player.
* password [VARCHAR(255)] - This is the player’s password.
* created_at [TIMESTAMP] - The date that the use first created their account.
* updated_at [TIMESTAMP] - The date that the record was last updated.
* credits [INT(10)] - No longer used: bank accounts belong to companies (below).

[Return to the TOC](#Table-of-Contents)

#### Table: companies
A player can own many companies; each has its own bank account and drones (Public Forge, #324 step 4). Migration 0004 turned each existing user into one company with the same ID, name and credits.
* company_id [INT] - Table index.
* user_id [INT] - The player (users.user_id) who launched the company.
* name [VARCHAR(255)] - The company's name, changed with the rebrand meta-command.
* credits [INT] - The company's bank account. Market trades pay into and out of it.
* created_at [TIMESTAMP] - When the company was launched.
* founded_t [BIGINT] - Game time the tax office first saw the company: its grace period starts then ([Taxes](#Taxes)).
* taxed_t [BIGINT] - Game time its current tax period started.
* eliminated_at [BIGINT] - Game time the company ended, NULL while it trades.
* end_cause [VARCHAR(16)] - Why it ended: `tax` (it couldn't pay its tax) or `drones` (its last drone died).

Any two companies may trade (push and pull against a market order), including two of one player's (#367 step 1): companies working together is a legitimate gain. Until then such trades failed with outcome -13 (`OUTCOME_SAME_PLAYER`), which no longer happens. Queries 100, 101, 110 and 201 read companies: a company's name, its credits, its drones, and a drone's company.

#### Table: house_rules
The numbers of taxes and plunder (#324 step 12), one row (id 1). Game data: `build_game_data.py` writes it, and every start reloads it.
* tax_period [INT] - Game seconds between tax bills (86,400: a game day).
* tax_grace [INT] - Game seconds a new company pays no tax (3 game days).
* tax_flat [INT] - Credits each company pays per bill on top of the share (0).
* tax_permille [INT] - Plus this many thousandths of what the company made (5, so 0.5%): its assessed wealth less its starter package.
* start_credits [INT] - A released company's starting credits, left out of the tax (1,000; migration 0019).
* start_fuel [INT] - Fuel in a released drone's hold, left out of the tax at the House price (100).
* plunder_share [INT] - Percent of a wreck's goods and parts a plunderer gets (50).

#### Table: company_taxes
One row per tax bill, for the website and post-mortems.
* id [INT] - Table index.
* company_id [INT] - The company billed.
* charged_t [BIGINT] - Game time of the bill.
* amount [BIGINT] - Credits charged; for a company that couldn't pay, the credits it had (they vanish).
* credits_after [BIGINT] - Its credits after the bill.
* eliminated [TINYINT] - 1 if it couldn't pay and was eliminated.

[Return to the TOC](#Table-of-Contents)

#### Table: agents
This table stores basic information on the ingame drones.  Most information about drone such as their current orbit and the contents of their cargo hold are recorded in the tEvents table, which is described later on.
* id [INT(5)] - Table index. Max 65,534 (FFFF).
* name [VARCHAR(45)] - The name of the user who owns the drone.
* vm [MEDIUMBLOB] - An image of the drone’s virtual machine.
* input [LONGTEXT] - The contents of the last email received from the user.
* output [LONGTEXT] - The contents of the email going back to the user.
* subject [VARCHAR(2048)] - The subject of the email exchange.
* object_id [INT(5)] - The static object where the drone is located.
* hull_type [INT(2)] - The type of hull used by the drone.
* user_id [INT] - The player who owns the drone.
* company_id [INT] - The company the drone belongs to. A launched drone joins its launcher's company.
* instructions [BIGINT] - FORTH instructions the drone's script has run in all (instruction metering).
* waiting_action [INT(10)] - The action the drone's script is waiting for (set by `perform`), NULL when it isn't waiting.
* release_vm [MEDIUMBLOB] - The clean copy of the drone's VM, saved the first time it runs `<launched>`. A crash restarts the drone from it ([Crashes](#Crashes)).
* launched_running [TINYINT] - 1 while the drone's `launched` word runs.
* crashes_in_row [INT] - Crashes since the drone's last action that succeeded.
* crashes_total [INT] - All the drone's crashes.
* wrecked_at [BIGINT] - Game time the drone became a wreck (too many crashes in a row, out of fuel, or its company eliminated), NULL while it works.
* wreck_cause [VARCHAR(16)] - Why: `crashes`, `fuel` or `tax`.
* plundered_at [BIGINT] - Game time the wreck was plundered ([Wrecks and plunder](#Wrecks)), NULL until then. A wreck is plundered once.
* plundered_by [INT] - The drone that plundered it.
* power_paid [BIGINT] - Instructions already paid for in fuel ([Fuel](#Fuel)). The migration set it to `instructions`, so what ran before fuel existed is free.
* parent_id [INT] - The drone that launched this one ([Cloning](#Cloning)), NULL for a released or registered drone.
* generation [INT] - Launches back to a released or registered drone (0 for that drone).
* code_from [INT] - The drone whose program it got at launch.

#### Table: drone_crashes

One row per crash of a `launched` word, for post-mortems.

* id [INT] - Table index.
* drone [INT] - The drone (agents.agent_id).
* game_t [BIGINT] - Game time of the crash.
* code [INT] - The error code `catch` caught (FORTH throw codes: -10 is division by zero, -4 stack underflow; a halted VM, such as after `abort`, gives -1).
* in_row [INT] - Crashes in a row, this one included.
* restart_at [BIGINT] - Game time the drone restarts; NULL when the crash made it a wreck.

[Return to the TOC](#Table-of-Contents)

#### Table: invites

No longer used (#367 step 4: there are no accounts to invite to); the table is left in place. It held invitation codes for an invite-only world.

* id [INT] - Table index.
* code [VARCHAR(32)] - The code (12 capital letters and digits).
* note [VARCHAR(255)] - Who it is for.
* used_by [INT] - users.user_id of the account opened with it, NULL while unused.
* used_at [TIMESTAMP] - When it was used.

#### Table: drone_messages

A drone's replies. The Game Engine writes one for each reply (with email on, the original game, it emailed the player instead).

* id [INT] - Table index.
* agent_id [INT] - The drone.
* game_t [BIGINT] - Game time the reply reaches Earth (now + the light delay from the drone). The website shows it from then on.
* subject [VARCHAR(2048)] - The subject of the command it answers ("Release" for a released script).
* body [LONGTEXT] - The reply, as it would have been emailed.

<a name="The-Release-Page"></a>**The Release Page (#324 step 6).** Public Forge has no email (#367 step 5: the `GAME_EMAIL` setting, the mail server, mail processing, `/web-register` and `/user/send-report` are gone): anyone releases scripts on the website instead. The engines' `EMAIL_ENABLED` (game-engine.conf) is "0", so the Game Engine writes replies to `drone_messages`. The scheduler calls `/tick/{CRON_KEY}` every 5 seconds (`/get-mails/{key}` is kept as its old name): it takes the leaderboard's snapshots and drains the launch queue.

**Redefined words (#380).** A test run or a release whose script defines (with `:`, `variable`, `constant`, `create`, `2variable` or `2constant`) a word the drone already knows gets a warning on the next page (`Release::redefinedWarning`); the release still goes ahead. The words are in `resources/drone-words.txt`, every word of the VM image, the callback words and the word library; `make -C game-engine/machine test` regenerates the list with `words.mjs` and fails if the file differs, so it can't drift. The same test fails a starter or company script that redefines a word (`check_starters.mjs`). In round 21 three of four newcomers named a word `report`, which silenced their looping drones.

There are no player accounts (#367 step 4: login, sign-up, password resets, invitation codes, `INVITE_ONLY` and `php artisan forge:invite` are gone; the `users` and `invites` tables stay, for the built-in owner and the email game). `/release`, open to anyone, posts a company name and a script:

* The script must define `launched` (a `: launched` definition, case-sensitive) and be at most 16,000 characters, with no line longer than a command (2,048).
* It creates a company (owned by the built-in account `Release::owner()`, "Public Forge", made on first use, so the engines, which expect every company and drone to have an owner, work as before; with 1,000 credits) and one drone in orbit around Earth (object 3, hull 1) with Starter Kit I. It then queues the script as one or more commands, split at line ends to fit the commands table, followed by `<launched>`. All of this happens in one transaction (`App\Services\Release`).
* The drone loads the script, then runs `launched` with crash handling ([Crashes](#Crashes)). `<launched>` waits until the script has finished loading.
* The player goes to the new drone's page, which shows, publicly like its code, the replies in `drone_messages` that have reached Earth ("What it said", the latest 20). The Release page itself lists nothing: there are no accounts to list drones by.

<a name="The-Test-World"></a>**The test world (#324 step 7).** The Release page's **Test** button runs a script in a separate world before the player releases it:

* **Database.** It has its own database, `exmachinis_test`, on the same MySQL server. On every start, the `migrate` service runs `game-engine/sql/init_test_world.sh` after the live migrations. A new test database starts from the same base schema (`create_db.sql`, pointed at the test database, and `exmachinis_tables.sql`). Each start then applies the migrations, loads the current game data and grants the game's MySQL user access. So the test world always has the live world's schema, rules and game data.
* **Engines.** `test-game-engine` and `test-events-engine` are the same engines with `DB_NAME=exmachinis_test`, email off (`EMAIL_ENABLED=0`) and a faster clock (`TEST_GAME_TIME_SCALE`, default 600). They are in the Compose profile `testworld`. The website shows the Test button when `TEST_WORLD=1` and the test database answers (connection `testworld`, database `DB_TEST_DATABASE`).
* **A test run** (`POST /release/test`, `App\Services\TestWorld`):
  * Anyone may (#367 step 3). The test world's built-in owner (`Release::owner('testworld')`) owns the run.
  * It releases the script there exactly as a live release does: a company with 1,000 credits and a drone in Earth orbit with Starter Kit I. It needs no company name: the run is named `Test <token>`, a random 16-hex-digit token.
  * The test world keeps the latest 100 test runs, all together (`forge.test_runs_kept`). Starting another removes the oldest: its commands, replies, crashes, drone (its events cascade) and company.
* **Results.** Each run has its own page, `/release/test/<token>`, which the Test button leads to: the test drone's output (`drone_messages`), crashes, company credits, latest 20 events (named by `event_types`) and trades. A wrong or pruned token is a 404.
* **Not yet.** The test world doesn't copy the live world's market. Its order book holds only test runs' orders. When drop stations arrive (step 10) they will be game data, so both worlds will follow the same station rules.

<a name="The-Drone-Page"></a>**The Drone page (#324 step 16).** `/spacecraft/{id}` is public. Besides the location, hull and cargo it had, it shows (`App\Services\DronePage`):

* **Live state:** what the drone is doing (idle, running its script or `launched`, waiting for an action and which, or a wreck), its fuel, its company (and whether it has ended), its generation, parent and clones ([Cloning](#Cloning)), the instructions it has run and its crashes.
* **Activity (#380)**, for a working drone (`DronePage::activity`): the action under way and when it ends (the last event of `agents.waiting_action`), its last 10 actions (`DronePage::timeline`, shared with the post-mortem: power bills and actions not yet started left out; "under way, until …" while an event is unprocessed) with the fuel left after each, and its last 10 sales and purchases: House station batches it took part in (`station_offers`, once cleared: units filled of offered, credits paid) and trades with other drones (`trades`, naming the other drone). Every playtester said they couldn't tell a busy drone from a stuck one.
* **Its code.** Every release is kept in `releases` (migration 0013): the script as released, `source_hash` (SHA-256 of the normalised source: `\` and `( )` comments removed and spacing collapsed, `App\Services\Provenance`), and one `release_words` row per word it defines (`: name ... ;`, normalised and hashed the same way). A clone shows the release its line of `code_from` drones goes back to. Drones that came by email have no release.
* **Where the code comes from.** **Copy this code** opens the Release page at `/release?copy={id}` with the drone's code filled in, and **copy** next to each word at `?copy={id}&word=name` with just that word's definition (comments kept). The form carries `copied_from` (and `copied_word`), which the new release records. A pasted copy is found without the buttons: `same_as` is the earliest release by another player with the same `source_hash`, and each word shows the drone whose release first had the same word hash ("first in drone N"). Copies within one player's own releases aren't flagged.
* **Post-mortem**, for a wreck: the cause in plain words, from `wreck_cause` and its records: out of fuel (with its last action, not counting a plunder haul done to the wreck after, and the instructions its program ran with the fuel they cost, so a loop that burned the tank thinking shows; and, for a released drone, whether it ever started `launched`), `launched` crashing N times in a row (the last error code from `drone_crashes`, named: -10 division by zero, -4 stack underflow…), or its company not paying its tax (the credits it had, from `company_taxes`); and who plundered it. Then a timeline, newest first, of its last 15 actions (power bills left out) with their results in plain words (`perform`'s codes) and the fuel left after each, and its crashes.

<a name="The-World-Pages"></a>**Home, Market and Map (#324 step 17).** The public view of the world (`App\Services\World`). Visitors see everything at once; only drones are limited to what is local ([Local information](#Local-Information)).

* **Home** (`/`): "What's happening", the latest 30 events in plain words, newest first, each linking to the drone it is about: releases (and code copied from another drone), launches of clones (launch events), wrecks and their causes, plunders, companies ending (tax or last drone), trades, and House station batches that bought or sold something. Each kind is read on its own (its latest 30) from its table, then they are merged by game time; a release's real `created_at` is turned into game time with the game clock.
* **Market** (`/market`): each House station with its budget left and its cap, and each good it trades now (from its `from_day`): whether it buys or sells, the price it would pay now for the batch so far (`World::buyPrice`: base × 2^(−units waiting ÷ daily units), at least 1, as the events engine clears it), its base price, daily units, the units waiting in this batch, and a small chart of its buying price from an empty batch to two days' worth. Then the order book: the 25 cheapest open sell orders and 25 best buy orders (each drone's latest order of each resource that its stock still leaves open; wrecks left out), with links to the full Buy and Sell Orders pages, and the 30 latest trades.
* **Map** (`/map`): the planets seen from above where they are now: longitude = ascending node + argument of periapsis + mean anomaly at J2000 + mean motion × days since J2000 (eccentricity ignored), distance from the Sun on a log scale. Each planet shows its working drones and wrecks, its moons' included, and a square marks a planet or moon with a House station. Below, the places with drones, busiest first.

<a name="The-Leaderboard"></a>**The leaderboard (#324 step 18; companies since #367 step 2).** `/leaderboard` ranks every company still trading by net wealth (`App\Services\Leaderboard`). Each release is a company with one drone, and the drones it launches belong to the same company, so their goods count too. Companies that have ended are left off.

* **Net wealth** = the company's credits + the goods aboard its working drones (installed and locked included; wrecks' holds don't count) at the House price − the company's starter package: its starting credits (`forge.starting_credits`), starting fuel at the House price and Starter Kit I. A fresh release is worth 0, so wealth is what it made.
* **The House price** of a good is the least any House station pays for it (`station_goods`, side 1); if no station buys it, the least one sells it for (side 2); otherwise 0. A packed Starter Kit I is worth its contents (the increments of protocol 25901), so unpacking it changes nothing.
* **Rising** is the gain over the last real week: wealth now − the company's latest snapshot at least 7 days old (0 for a company started within the week). The website takes a snapshot of every company's wealth once a real hour (`wealth_snapshots`, migrations 0014 and 0017), when the scheduler's tick calls it (`/tick`, every few seconds) or when the leaderboard is viewed.
* Both boards show the top 50: the company, its working drones, and wealth or gain.

<a name="The-Machine-View"></a>**The Machine view (#324 step 19).** `/machine` runs a word on the engine's own FORTH VM in the browser, one instruction at a time, so a player can watch it work and see what it costs.

**Live game data on the Machine (#380).** With the page's *live game data* box ticked, `query` runs on the live world. The machine (`machine_set_live`) stops the word at a query, with its id taken off the stack (`machine_query_pending`); the page takes the results array's size and address and the query's parameters off the stack (`machine_pop`, as `vm_ext_query_cb` does; `/machine/queries` gives each query's parameter count and string parameters), asks `/machine/query?id=&drone=&size=&v[]=&s[n]=`, writes the bytes it gets back into memory (`machine_write_byte`) and leaves the code (`machine_query_answer`): rows stored, -1 not enough parameters, -2 failed or found nothing, -3 no such query (its other arguments left on the stack). Stepping then goes on. `LiveQuery::run` fills in the game's own query script as `vm_get_new_tag_value` does (`[value_n]` signed, `[dvalue_n]` from a high and a low cell, `[string_n]` with the same character limits, `[drone_id]` the chosen drone, `[game_now]`; `[time_delay]` isn't supported and fails), and lays the rows out as `db_run_vm_query` does: 2-byte cells low byte first, `d_` columns as 4 bytes high cell first and clamped, dates as a game day and even seconds, text as a counted string of up to 255 bytes, whole rows only. Players supply numbers, never SQL; the endpoint is throttled (240 a minute). Checked byte for byte against the engine on a test drone (queries 205, 101, 630, 700, 400, 843). The machine also builds the engine's `wrap.c` (`machine_wrap`), and the starter checker, the Machine page and the newcomers' tools wrap each line through it before the VM reads it, as the Game Engine does: they used to cut a line at the VM's 80-character buffer, mid-word, and report errors a drone would never see. Off, query leaves -3, so `make test` (traces and the starter checker) is unchanged; a live query counts 1,000 instructions, as the engine meters it. Live, `perform` stops the word the same way (`machine_perform_pending`): the page takes the protocol's parameters off the stack (`/machine/protocols` gives each protocol's count) and leaves -11, so a word that acts is traced with the stack the engine would leave, though nothing happens in the game.

* **The same machine.** `game-engine/machine/machine.c` sits on the engine's `embed.c`, its image and `vm_library.c`, unchanged, and is built twice: natively and as WebAssembly (`clang --target=wasm32`, with a few libc functions in `wasm/libc.c`), served as `html/wasm/machine.wasm`. `machine_init` sets the VM up as `vm_add_callbacks` does for a drone: the same preamble, the callback words `perform query report dummy`, the word library and the game words. With no game around it, `perform` leaves -11 and `query` -3.
* **Stepping.** `machine_prepare(word)` puts the word's execution token on the stack and calls `catch` on it, as the engine runs `launched`, with a return address on the return stack that points at `machine-halt`, a cell that branches to itself. `machine_step(n)` runs up to n instructions, one at a time (the VM's yield callback stops it after each), and stops early when pc reaches the halt cell. So the word ends with 0 on top, or with the error it threw (`1 0 /` leaves -10).
* **What the page shows** (`html/wasm/machine.js`): the instruction count and the fuel it would burn (1 per 100,000), pc and the next instruction decoded (literal, call, branch, 0branch or ALU: the operation on t, n and r, the stack changes, and whether it returns), the data stack, the word's own return stack (the interpreter's frames under it are hidden), what it printed, and a listing of the word pc is in. Names come from `words` and each word's execution token; a one-instruction primitive inlined into a word (like `dup` or `*`) is named by its instruction. **Run** goes up to a limit (1,000,000 instructions by default), so a word that never ends stops. `?defs=&stack=&word=` fill the boxes.
* **Tested to match.** `make -C game-engine/machine test` runs the words in `cases.txt` on the native build (`trace_machine`) and on the WebAssembly in Node (`compare.mjs`) and diffs the traces: pc, instruction, stack and return-stack depth at every step, then each word again in one run. CI runs it on the committed `machine.wasm`, so it can't drift from the engine's VM. It also loads each starter script on the WebAssembly build, line by line, and fails on any error (`check_starters.mjs`): a `\` comment only reaches column 80, so a longer comment line leaks into code, which left the step 15 refiner without a `launched` word.

#### Table: hulls
Drones are defined by their hulls and the materials and equipment contained within them. Both cargo, in the form of consumable resources, and functional modules affect what the drone can do.  The different varieties of hulls and their hold capacities are described in the tHull table and specifically linked to each drone in the agents table.
* id [INT(2)] - Table index. Hulls 1 and 2 are the starting drones'; a drone launched from a ship kit has the hull with the kit's ID (6001–6231, see [Launching Drones](#Launching-Drones)).
* name [VARCHAR(45)] - The name of the hull design
* description [LONGTEXT] - A brief description of the hull design
* cargo_capacity [INT(10)] - The total cargo capacity of the hull to include equipment and supplies.
* a_slots [INT(2)] - The number of A slots.
* b_slots [INT(2)] - The number of B slots.
* c_slots [INT(2)] - The number of C slots.
* d_slots [INT(2)] - The number of D slots.
* e_slots [INT(2)] - The number of E slots.

[Return to the TOC](#Table-of-Contents)

#### Table: hull_bonuses
Hull bonuses apply to specific modules, further enhancing the the effects of the modules on the specific resources.
* id [INT(5)] - This is the table index. Max 65,534 (FFFF).
* hull_type [INT(2)] - This is the ID of the hull which confers the bonus.
* affected_resource [INT(5)] - This is the ID of the installed module that is affected by the hull bonus.
* multiplier [INT(2)] - This is the percent fraction of the time it takes to complete any protocols involving the referenced ResourceID.

[Return to the TOC](#Table-of-Contents)

#### Table: resources
This is a static table of all the resources that are available in the game.
* id [INT(5)] - Table index. Max 65,534 (FFFF).
* name [VARCHAR(45)] - The name of the resource.
* description [LONGTEXT] - A brief description of the resource.
* mass [INT(10)] - The mass of a single resource unit.
* capacity [INT(10)] - The number of subunits a module can support.
* slot_size [INT(1)] - This indicates the required slot size for the installed.  When this field is set to zero, the resource is not a module and cannot be installed in the ship.

[Return to the TOC](#Table-of-Contents)

## Static Objects

All static objects in Ex Machinis follow elliptical orbits around the sun or another object.  Static objects specifically refer to planets, moons, asteroids, comets, space stations, and beacons, which never change their orbits. The data for these objects and their orbits are stored in the Objects Table. Drones are not considered static objects because they’re capable of changing orbits. In order to simplify gameplay, drones do not follow their own orbits.  Rather, they shadow the orbits of smaller objects like asteroids, comets, space stations, and beacons.  There is no limit to the number of drones that can shadow another object.

This chapter details the coordinate systems, parameters, and computations required to track and locate static objects, and their adjacent drones in space.  These calculations are necessary to compute the distances between objects at any time.

[Return to the TOC](#Table-of-Contents)

### The Physics Engine
The Physics Engine is a C++ application, which runs independently on the server to maintain updated cartesian coordinates on each active orbit. It does so by simply cycling through each spacecraft and static object orbit in the database and calculating its current position in space.  Since most orbits will only require minor positional corrections during the course of the day, the Physics Engine is set to run through the tOrbits table only two to four times a day.  The following sections describe the process by which an object’s cartesian coordinates are computed from it’s orbital parameters.

[Return to the TOC](#Table-of-Contents)

### Units of Measure
All values will be computed and stored in the database terms of meters, kilograms, and degrees.  However, when distances are reported to players they will generally be given in terms of light seconds where one light second equals 2.998E+8 meters.

[Return to the TOC](#Table-of-Contents)

### Elliptic Coordinate Systems

#### Polar Representation
All orbits in Ex Machinis are described using the same polar ecliptic coordinate system that is commonly applied to celestial orbits (Figure 1).  The latitude of the coordinate system is oriented on the orbital plane of the Earth with north elliptic pole being oriented in the direction of the Earth’s northern hemisphere.  The zero point for the longitude points through the sun when the Earth is at it’s vernal equinox for the northern hemisphere.  The origin (center) of the polar coordinate system changes so that it is always positioned at the central body around which the object is orbiting.


Figure 1.  Depicts the orientation of the Ecliptic Coordinate System with respect to the Earth’s orbital plane.


[Return to the TOC](#Table-of-Contents)

#### Cartesian Representation
Although the orbits are typically expressed in the polar representation of the elliptic coordinate system,  they frequently need to be converted to a cartesian representation to compute distances between objects.  In the cartesian coordinate system, the xy plane corresponds with the Earth’s orbital plane and the positive z axis points towards the north ecliptic pole.  The positive x axis points towards the sun from the Earth when the Earth is at it’s vernal equinox.

[Return to the TOC](#Table-of-Contents)

### Orbital Tables

#### Table: objects
This table stores the parameters of all of all static objects.  These are called static objects because, unlike spacecraft, they never change their orbits.  Figure 1 depicts these parameters with respect to a defined orbit.
* object_id [INT(11)] - This is a unique identifier for the orbiting object.
* object_name [VARCHAR(45)] - The searchable name of the object.
* object_type [VARCHAR(16)] - This classifies the object: Star, Planet, Moon, Station, Asteroid, Comet or Ring. Minerals are found on the body types named in their resource category (see `game-engine/sql/game-data/`).
* gravitational_parameter [DOUBLE] (μ) - The gravitational parameter of the central body expressed in m3/s2.
* central_body_object_id [INT(11)] -  This is the ID of the object that is being orbited.
* mean_angular_motion [DECIMAL(13,9)]  (n) - This is the average angular motion expressed in degrees per day.  It can be derived from the gravitational parameter as described below.
* epoch [DATETIME] (t0) - The time at which the orbital parameters were defined.  This is typically J2000.
* eccentricity [DECIMAL(10,9)] (e) - Orbital eccentricity between 0 and 1.
* semimajor_axis [DOUBLE] (a) - Half of the major axis of the orbital ellipse expressed in kilometers.
* inclination [DECIMAL(12,9)]  (i) - Defines the angle in degrees between the orbital plane and the reference plane.
* ascending_node_longitude [DECIMAL(12,9)]  (Ω) - defines the angle in degrees between the reference direction and the upward crossing of the orbit on the reference plane (the ascending node).
* periapsis_argument [DECIMAL(12,9)]  (ω) - defines the angle in degrees between the ascending node and the periapsis. This is not the longitude of perihelion (ϖ = Ω + ω) that tables of planetary elements often list: convert with ω = ϖ − Ω.
* mean_anomaly [DECIMAL(12,9)]  (M0) - defines the position of the orbiting body along the trajectory at the epoch, measured in degrees from periapsis. This is not the mean longitude (L = ϖ + M) that tables of planetary elements often list: convert with M = L − ϖ.
* computed_on [DATETIME] - This is the time at which the object’s cartesian coordinates were last computed by the Physics Engine.
* x_coord (DOUBLE) - This is the most recent x coordinate of the object in kilometers.
* y_coord (DOUBLE) - This is the most recent y coordinate of the object in kilometers.
* z_coord (DOUBLE) - This is the most recent z coordinate of the object in kilometers.
* The Inclination, Longitude of the Ascending Node, and Argument of Periapsis help orient the orbital plane in space (Figure 2) whereas the other parameters are used to locate the orbital body on the plane.

The planets, moons and stations come from `game-engine/db-backup/exmachinis_tables.sql`. The asteroids, comets and planetary rings come from `game-engine/sql/game-data/bodies.csv`, which records where each body's elements came from; `build_game_data.py` adds them to `game_data.sql`, and also corrects the planets' elements (see `PLANET_A_AU` in that script). Rings are circular orbits at the ring's mean radius in their planet's frame, like the moons. `make -C game-engine/src test` checks the orbit maths against real, observed positions.


Figure 2. A diagram of the various parameters that go into describing an orbital plane.  The plane of reference parallels the Earth’s orbital plane with the reference direction paralleling the vector which points from the Earth to the sun during the vernal equinox.

[Return to the TOC](#Table-of-Contents)

##### Table: abundancies
All manufacturing starts by harvesting raw materials from planets, moons, asteroids, and comets.  The types of resources that can be harvested depends on the specific object that the spacecraft is orbiting.  

The abundancies table is used to record the availability of harvestable resources at different locations.  Whenever a harvest event occurs (events.event_type = 1), the Event Engine multiplies the events.new_quantity value by the abundancies.multiplier value for the first entry in the abundancies table with the same drone location and resource values as indicated by the event.  If a matching entry is not found in the table, events.new_quantity is set to zero, indicating that the resource cannot be harvested from that location.  In this manner, the abundancies table determines the efficiency with which a resource can be extracted from a location.

The following fields are contained in the abundancies table:
* id [INT(11)] - This is the ID of the abundanies table entry.
* location [INT(5)] - This is the location to which the abundancies.multiplier applies.
* resource [INT(5)] - This is the resource to which the abundancies.multiplier applies.
* multiplier [INT(2)] - This is the amount that the harvested resource is multiplied by.

##### Tables: deposits and deposit_state
Minerals are not endless (#324 step 11). Each mineral at each place is a deposit with a capacity, and mining takes from it. A mined-out deposit yields nothing until it refills, which it does slowly over game time. This stops one drone from mining a spot forever and makes busy places worth fighting over.

* **deposits** (game data, from `build_game_data.py`): `object_id`, `resource`, `capacity` (units) and `refill_per_day` (units regained per game day). Every abundance gets one: capacity is 400 mining runs and the refill is 80 runs a day, so a mineral of abundance 5 holds 2,000 units and regains 400 a day (ten times the step 11 placeholders since #367: at 40 and 8, the Moon, Phobos and Deimos fed only a few dozen drones). A place and mineral with no deposit row is unlimited.
* **deposit_state** (live): `object_id`, `resource`, `amount` (units left, a DOUBLE so part-units of refill carry over) and `refilled_t` (the game time the amount was worked out). No row means the deposit is full.

How it works:
1. When the game engine schedules a mining increment (an effect with `abundancies` set and event type 1), it works out the yield as before (quantity × abundance multiplier × process multiplier). Then `db_take_deposit` refills the deposit lazily (`amount + refill_per_day × elapsed game seconds / 86400`, capped at `capacity`), takes the whole units it can, up to the yield, and writes back what is left with `refilled_t` = now. The event's quantity becomes what was taken.
2. A deposit that is empty gives 0 units. The action still succeeds; the drone just gets nothing.
3. Mining is taken from the deposit when it is scheduled, but the events engine may still fail the action later (no module, no cargo space, no fuel). Then `db_refund_deposits` (called from `event_record_failure`) gives the unapplied mining increments of that action back to the deposit and zeroes them, so they are refunded only once.
4. Query 640 ("Deposits here") lists the deposits where the drone is: resource, units left now (2 cells), capacity (2 cells) and refill per day. It works out the refill the same way, without changing anything.

The engine logs `Deposit of resource [R] at [O]: [taken] of [wanted] wanted taken, [left] left` for every mining run, and `... given back by failed action [A]` for every refund.

[Return to the TOC](#Table-of-Contents)

### The Orbits of Planets and Moons
We’ve created a table of orbital parameters for the planets, moons, and in-game satellites that will be used to initially populate the Objects table of the Central DB.  However, a much larger database of orbital data is available here.  We will eventually need to determine a way for importing this data into the Central DB.

[Return to the TOC](#Table-of-Contents)

### The Orbital Parameters
The following variables and conversion factors are required to define an elliptical orbit in the database and permit the computation of an object’s position along that orbit at any point in time.

[Return to the TOC](#Table-of-Contents)

#### Gravitational Parameter
In orbital mechanics, the gravitational parameter (μ) can be described as
=GM
Where G is the gravitational constant (6.674×10−11 N·kg–2·m2) and M is the mass of the central body.  This equation assumes that the mass of the central body is much larger than that of the orbiting body. There is a useful table of precisely measured gravitational parameters for the major stellar objects on Wikipedia. The gravitational parameter for all major bodies will be stored in the object data tables with the units of m3/s2.

[Return to the TOC](#Table-of-Contents)

#### Semimajor Axis
The semimajor axis (a) is half the distance between the furthest points on the ellipse. This value is expressed in meters.

[Return to the TOC](#Table-of-Contents)

#### Semiminor Axis
The semiminor axis (b) is half the distance of the minor axis, which runs through the center of the ellipse, perpendicular to the major axis. This value is expressed in meters.

[Return to the TOC](#Table-of-Contents)

#### Eccentricity
The eccentricity (e) is a measure of the elongation of an ellipse and can be related to semimajor (a) and semiminor (b) axis as follows:
e=1-ba2

[Return to the TOC](#Table-of-Contents)

#### Mean Angular Motion
The mean angular motion (n) provides the average velocity of the object as it transits the orbit.  It is expressed in radians per second and can be computed from the semimajor axis (a) as expressed in meters and the gravitation parameter () for the body which the object is orbiting.  In the case of the planets, this will be the gravitational parameter of the sun (1.327E20).  
n=a3

Once, computed, the mean angular motion needs to be converted to degrees/day first by multiplying by 57.2958 degrees/radian and then by dividing by 86,400 seconds/day.  This value can be stored in the DB for the orbital object so that it doesn’t need to be computed again.  In the case of moons, the mean angular motion is already provided in the data set.

[Return to the TOC](#Table-of-Contents)

#### The orbital period
The orbital period (T) is the time required for the object to complete a single orbit.
T=2n

[Return to the TOC](#Table-of-Contents)

### Key Orbital Functions
These are the main functions that need to be created and used to compute the x, y, and z coordinates for any object at time t.  

[Return to the TOC](#Table-of-Contents)

#### Mean Anomaly
The mean anomaly with respect to time (M(t)) is the angular distance at time t that an object would have from the periapsis if it moved in a circular orbit at a constant speed as described by the mean angular motion (n).
M=M0+n(t-t0)

Here, t0 is the Epoch for the orbit.  The difference in times (t - t0) should be expressed in days.
The mean anomaly can be used to compute the true anomaly of the orbiting body at a specific point in time (Figure 3).


Figure 3. This diagram depicts the relationship between the mean and true anomalies.


[Return to the TOC](#Table-of-Contents)

#### Eccentric Anomaly
The eccentric anomaly (E) defines the angular position of an object that is moving in an elliptical orbit.  It is related to the mean anomaly (M) as follows:
M=E-esin E

Unfortunately, the eccentric anomaly can only be computed via an iterative algorithm known as the Newton-Raphson iteration.
Ei+1=Ei-Ei-M+esin Ei1-ecos Ei

Here Ei+1 is the result of the current iteration and Ei is the result of the previous iteration.  The process should start by setting the first guess E0 to M.  The iteration is complete when the second term is sufficiently small.

[Return to the TOC](#Table-of-Contents)

#### True Anomaly
The true anomaly (ν) represents the orbital objects actual angular position and can be computed from the orbit’s eccentric anomaly (E) and eccentricity (e) using the following equation:
=2arg1-ecos E2, 1+esin E2

Where arg(x,y) is the polar argument for the vector (x,y).  This function is available in many programming languages as atan2(y,x).

[Return to the TOC](#Table-of-Contents)

### Solving Kepler’s Equation with JavaScript
I found an online resource that provides a JavaScript routine for deriving the eccentric (E) and true (v) anomalies using an orbit’s eccentricity (e) and mean anomaly (M):
http://www.jgiesen.de/kepler/kepler.html

#### Radius at the True Anomaly
The distance (r) of the orbital object from the central body at the true anomaly (ν) can be computed using the following equation:
r=a1-e21+ecos

Here, both the semi-major axis (a) and the resulting radius (r) should be expressed in km.  Also, e is the orbital eccentricity.

[Return to the TOC](#Table-of-Contents)

### Converting the orbital position to Cartesian coordinates
Using the above variables, you can derive the Cartesian coordinates of the orbital object.  Each of the coordinates will be expressed in terms of km.

x = r (cos Ω cos (ω + ν) - sin Ω sin (ω + ν) cos i)

y = r (sin Ω cos (ω + ν) + cos Ω sin (ω + ν) cos i)

z = r (sin i sin (ω + ν))

This provides the Cartesian position of the orbital object with respect to the central body.  However, the central body may could be a moon that is orbiting around a planet, which is in orbit around the sun.  Therefore, the position of the orbital object with respect to the sun is the sum of the Cartesian vectors for the planet, moon, and spacecraft.

[Return to the TOC](#Table-of-Contents)

## Meta Commands

Players generally interact with their spacecraft via email.  Each spacecraft has its own email address and players use this address to send their drones meta-commands.  Meta-commands are always bracketed by greater than and less than signs (ie. “\<command\>”) to distinguish them from other email content, which is ignored by the drones. Most meta-commands enclose a single parameter, which is being sent to the spacecraft.  In this case, the meta-command would be rendered as “\<command\> parameter \</command\>.”  Spacecraft will only execute the first recognizable meta-command in an email: a second `<run>` block, for example, is ignored, so a script that needs more lines goes in one block.

The Mail Handler reads an email's plain-text part. If that part holds no meta-command but the HTML part does (some mail clients send a plain part without what the player typed), it uses the HTML part converted to text. Mail to a drone name that doesn't exist (a typo, or a drone that was renamed) gets a reply listing the sender's drones, if the sender is a registered player; anyone else gets no reply, so spam to the catch-all address isn't answered.

Spacecraft recognize a few basic meta-commands, which allow players to execute FORTH scripts, reset errant code, and reconfigure user settings.  These commands are as follows:

* \<run\> parameter \</run\> tells the drone to execute the FORTH script contained within the parameter string.  This parameter can contain line breaks and other special characters.  The drone's FORTH interpreter reads at most 80 characters of a line (its terminal input buffer), so the GE re-wraps longer lines at spaces before running them, to at most 79 characters. It keeps each string (`."`, `s"`, `c"`, `abort"`, `.(`) and each word that reads the next word (`:`, `'`, `char`, `create`, `variable`, …) together with what it reads, and drops comments from a line it has to split. A single string longer than a line can't be split: the drone replies with an error and runs nothing.
* \<abort\> forces the drone to immediately stop any script that it is currently running and return control to the player, including a script waiting for an action to finish. The action itself is not stopped: its events still happen.  The drone will return an email confirming that it has aborted the script.
* \<reset\> stops the currently running script and resets the drone’s virtual memory to its factory setting, erasing any code that the player may have entered.  The drone will return an email to confirm that it has successfully reset its memory.
* \<rename\> parameter \</rename\> changes the email address of the drone to parameter@exmachinis.com.  Consequently, the parameter must not contain characters that generate an invalid email address and cannot be in use by any other drone or player.  The drone will return an email confirming its name change.  It will also report any failure to change the name and the reason why the proposed name is unacceptable.
* \<launched\> is never mailed by players: the Event Engine queues it for a drone it has just launched ([Launching Drones](#Launching-Drones)), ready to run at once (no light delay).
* \<rebrand\> parameter \</rebrand\> changes the name of the player’s company.  The chosen name must be unique and not exceed the maximum lengths prescribed by the SQL field. The drone will return an email confirming the company’s name change.  It will also report any failure to change the name and the reason why the proposed name is unacceptable.

[Return to the TOC](#Table-of-Contents)

## Protocols

Every physical action in Ex Machinis results from a drone executing a specific protocol. Protocols are procedures that a drone can perform provided they have the required resources, modules, and cargo space.  Most protocols consume and produce a certain amount of resources with the help of installed modules.  Protocols generally take a finite time to complete.  If any of the modules or reagents are missing or currently in use by another protocol, the protocol will not be begin.

[Return to the TOC](#Table-of-Contents)

### Performing a Protocol
Each protocol has a unique ID and is invoked from FORTH by placing the process multiplier and protocol ID on the top of the stack and calling the perform function.  Many protocols also have additional parameters, which must be set using values on the stack.

[Return to the TOC](#Table-of-Contents)

### Process Multiplier
The process multiplier is an integer, which indicates the number of consecutive times a particular protocol will be applied before stopping.  The multiplier will be applied to each of the resources that are consumed and produced by the protocol as well as the corresponding time intervals.  The only thing it will not affect is the number of modules that are required to perform the action.  The process multiplier is pulled from the stack, after the protocol's parameters, only for protocols whose multiplier flag is true in the protocols table (push 10 and pull 11), and for those it is required: `N R D 10 perform`, with 1 for a single unit (#315). It must be at least 1, or perform refuses (-12); left out, it is a stack underflow (-4) like a missing parameter. Protocols without the flag never take one.

[Return to the TOC](#Table-of-Contents)

### Protocol ID
Each protocol has a unique numeric ID, which is used to invoke the specific action via the perform function.  This number is identical to the Protocol ID in the protocols table, described below. The protocols table and other action tables are used by the perform routine to determine the costs and outcomes of each protocol.

[Return to the TOC](#Table-of-Contents)

### Protocol Parameters
Protocols often require parameters to further define their operations. These parameters are pulled from the stack when the protocol is performed. The parameters are placed in a C++ array according to the order in which they were pulled from the stack. Sometimes the values in this array are modified by the Game Engine prior to use.  Other times they are not.  However, in most cases the array values are used in place of variable markers found in the action tables, which are described below.

[Return to the TOC](#Table-of-Contents)

### Returned Value
`perform` waits for the action it starts. The drone's script does not run on until every event of the action has been processed (see [The Game Engine cycles through player-run virtual machines](#The-Game-Engine-cycles-through-player-run-virtual-machines)), so a drone performs one in-game action at a time. Then `perform` leaves one value on the stack, following FORTH's convention for I/O results (an *ior*): 0 when the action succeeded, a negative code when it didn't.

| Code | Meaning |
|---|---|
| 0 | The action was completed |
| -1 | The other drone is not at the same place (outcome -1) |
| -2 | Not enough of a resource (outcome -2) |
| -3 | Not enough cargo space (outcome -3) |
| -4 | Not enough credits (outcome -4) |
| -5 | The seller's sell order doesn't allow selling that many (outcome -5) |
| -6 | The buyer's buy order doesn't allow buying that many (outcome -6) |
| -7, -8 | Wrong depletion or accumulation price (outcomes -7, -8) |
| -9 | The action was aborted |
| -10 | The other drone has no matching buy or sell order (outcome -10) |
| -13 | No longer used (#367 step 1): it refused trades and plunder between one player's companies |
| -14 | No House station where the drone is trades that resource that way (outcome -14) |
| -15 | No unplundered wreck of that ID where the drone is (outcome -15) |
| -11 | Unknown protocol (no action was started, no wait) |
| -12 | Refused when performed, e.g. a parameter names a resource or destination that doesn't exist (no wait) |

Codes -1 to -10 are the outcome of the first event that failed (see [The Event Engine](#The-Event-Engine)). A script handles them like any ior, for example `... perform if ." trip failed" cr then`.

`perform` takes its parameters off the stack like any FORTH word. If the stack holds too few, FORTH's own error -4 (stack underflow) stops the script, as for any other word.

[Return to the TOC](#Table-of-Contents)

### Aborted Actions
Actions can be aborted by the Game Engine, the player, or another ingame event.  In many cases protocols are aborted by the drone before they ever begin because of insufficient resources or cargo space to hold incoming resources.  When aborted, any events that are associated with the action and occuring in the future will be deleted from the events table, which is described below.

[Return to the TOC](#Table-of-Contents)

### Action Tables
Ex Machinis uses multiple database tables to describe the various protocols that are available to drones, track their outcomes, and regulate how knowledge of these activities propagates outward from their source at the speed of light.  These tables can be divided into static tables, which do not change as the result of gameplay, and dynamic tables, which do change as the game progresses.

Whenever a field in one of the static tables contains a value less than zero, it’s absolute value will be interpreted as a pointer (p) to the C++ variable variable array var(p), which contains values that were pulled from the stack and/or modified by the Game Engine.  Whenever a value in one of the static table fields is greater than or equal to zero, it will be interpreted as a constant value for that particular field.  There is only one exception to this rule. If the Drone ID field in any of the static tables is zero, it will be replaced with the ID of the drone performing that action.

[Return to the TOC](#Table-of-Contents)

#### Static Action Tables
The static action tables are used to describe full range of possible drone actions and their outcomes.  Static tables do not change during gameplay.

[Return to the TOC](#Table-of-Contents)

##### Table: protocols
This table lists all of the executable protocols that are available in the game.  It’s a static table, which doesn’t change in the course of gameplay.  However it defines the actions that are available to drones and, when taken in conjunction with the other static tables, proscribes how these actions affect the drone and their surroundings.  The protocols table contains the following fields:
* id [INT(5)] - Table index. Max 65,534 (FFFF).
* name [VARCHAR(45)] - The name of the protocol.
* parameters [INT(1)] - This is the number of parameters that should be pulled from the stack when running the protocol.
* bulk_modifier [INT(2)] - This is the percent time compression that occurs with bulk processes.  A value of 100 or more indicates that there is no advantage in performing bulk actions and they are not permitted.
* description [VARCHAR(256)] - A brief description of the protocol.
* observable [BOOLEAN] - Indicates that the protocol creates an observable event.
* reportable [BOOLEAN] - Indicates that the protocol creates an event that will be recorded in the Central Database and discoverable by other drones.
* multiplier [BOOLEAN] - Indicates that the next value on the stack after the protocol number will be a process multiplier that can be used to perform iterative processes.

[Return to the TOC](#Table-of-Contents)

##### Table: event_types
This is a static table, which describes the different types of events that can arise from a protocol.
* id [INT(2)] - Table index.
* name [VARCHAR(45)] - A name for the event type.

The types are 1 increment inventory, 2 decrement inventory, 3 move, 4 sell order, 5 buy order, 6 increment credits, 7 decrement credits and 8 launch a drone ([Launching Drones](#Launching-Drones)). `game_data.sql` adds type 8 to existing databases.

Some event types have an impact on how the Event Engine responds to an event.  For example, events.event_type = 1 indicates that the event is a harvest type event and the Event Engine will use the abundancies.multiplier value for the corresponding location and resource to modify the amount of resource that’s added to the cargo hold by the event.

[Return to the TOC](#Table-of-Contents)

##### Table: resource_effects
This is a static table, which is used to indicate how specific protocols affect the depletion or accumulation of resources in a drone’s hold and credits in a player’s bank account.
* id [INT(5)] - Table index. Max 65,534 (FFFF).
* protocol [INT(5)] - The protocol that affects this resource.
* event_type [INT(2)] - This is the Event Type ID of the particular event.
* local [BOOL] - If true, the affected drone must be in the same location.
* drone [INT(5)] - The drone that is affected by the protocol.
* resource [INT(5)] - The resource that is used, consumed, or produced by the protocol.
* installed [BOOL] - Is true if this activity applies to installed resources.
* locked [BOOL] - This field is true if the change applies to locked resources.
* deplete [BOOL] - If true, this indicates that the resource is being depleted. Otherwise the resource is being accumulated.
* quantity [INT(5)] - The number of resources depleted or accumulated during the event.
* time [INT(5)] - How much time in minutes elapses after the start of the protocol before the item is affected.

[Return to the TOC](#Table-of-Contents)

##### Table: market_effects
This static table is used to set upper and lower limits on the quantity and price of a particular resource on the market.  The limit is used to constrain the amount of the resource that are sold or purchased by a ship.  In Ex Machinis, drones will be permitted to purchase from or sell to other drones provided the prices and quantities of the resources fall within the market limits set by the other drones.
* id [INT(5)] - Table index. Max 65,534 (FFFF).
* protocol [INT(5)] - This is the protocol that affects the market order.
* event_type [INT(2)] - This is the EventType ID of the particular event.
* resource [INT(5)] - This is the resource whose limits are being set.
* upper_limit [BOOL]  - If true, this effect places an upper limit on the quantity and price of the resource.  If false, it establishes a lower limit for the quantity and price.
* quantity [INT(5)] - This is the upper or lower limit of the resource in the ship’s cargo hold.
* price [INT(5)] - This is the upper or lower limit that is being offered (upper) or asked (lower) for the specified resource.
* time [INT(5)] - How much time in minutes elapses before the item is affected.

[Return to the TOC](#Table-of-Contents)

##### Table: location_effects
The static tLocationEffects table describes protocol-induced changes in the drone’s location.  It’s generally associated with propulsion protocols, which begin by setting the drone’s location to zero, indicating it is transit between two points, and end by setting the location to the target destination after the transit has been completed.
* id [INT(5)] - Table index. Max 65,534 (FFFF).
* protocol [INT(5)]  - This is the protocol that affects the location.
* event_type [INT(2)] - This is the EventType ID of the particular event.
* location [INT(5)] - This is the ID of static object where the drone is currently located.  A zero indicates that the drone is in transit between locations.
* time [INT(5)] - How much time in minutes elapses before the item is affected.

[Return to the TOC](#Table-of-Contents)

#### Dynamic Action Tables

##### Table: actions
Every time a protocol is executed, an action entry is created to track the outcome.  The dynamic actions table contains basic information about the action which, is helpful in tracking its costs and outcome.
* id [INT(10)] - Table index
* drone [INT(5)] - This is the drone that initiated the action.
* protocol [INT(5)] - This is the protocol that was performed.
* multiplier [INT(5)] - This is the number of times that the process is run concurrently.
* aborted [BOOLEAN] - This is a boolean value which indicates that a particular action has been aborted.
* outcome [INT] - When the action failed, the outcome of the first event that failed (a negative code); NULL otherwise. `perform` leaves it for the drone's script.

[Return to the TOC](#Table-of-Contents)

##### Table: events
The dynamic events table is used to track what has and will happen in the game.  Most events represent either the beginning or end of a particular action and have specific effects on the drone and their environments.  In general, each resource_effects, market_effects, and location_effects entry will be converted to an event when the corresponding protocol is performed by the drone.

The fields in the events table can be grouped according to whether they describe the event, the affected object, or the effect.

The fields which describe the event itself are:
* id [INT(10)] - This is the ID of the current event.
* event_type [INT(2)] - This is the type of event.
* time (DATETIME) - This is the time at which the event occurs.
* action [INT(10)] - This is the action that caused the event.
* logged [BOOLEAN] - True once the event's observations have been recorded. Inventory events are inserted logged; market orders (sell and buy, types 4 and 5) are inserted unlogged and the Event Engine sets the flag when it processes them. Nothing else reads it: whether an event happened is its outcome (1 = it took place), so a processed sell order is valid either way (#254).
* outcome [INT(2)] - This field defaults to zero but is set to one if the event is successfully processed and a negative value if it produces an error.

The fields, which describe the affected drone or object, are as follows.  These are the fields that are generally used to group event queries.
* drone [INT(5)] - This is the drone that was affected by the event.
* resource [INT(5)] - This is the resource that was affected by the event.
* installed [BOOLEAN] - This is true if the outcome applies to installed resources.
* locked [BOOLEAN]  - This is true if the outcome applies to locked resources.

The following fields describe the outcome of the event.  They are used to set new resource quantities, market prices, or drone locations.
* new_quantity [INT(5)] - Depending on the unprocessed event type, this is either the change in quantity of a resource or the max or min quantity of market transaction. For processed events, this becomes the total quantity of the resource.
* new_credits [INT(10)] - Depending on the unprocessed event type, this either the change in  value of the player’s bank account or the max or min price of a transaction set by a market effects event.  For processed events, this becomes the total value of money in the player’s bank account.
* new_location [INT(5)] - This is the new Orbit ID of a drone as set by an orbit effect.
* new_transmission [INT(10)] - This is the ID of the transmission text addressed by the event.
* new_cargo [INT(10)] - For unprocessed events, this field records the total positive or negative change in mass of the drone’s cargo at the time of the event.  This can be determined when the event is created by multiplying the quantity_effect by the resource mass.  When the event is processed this value is updated to represent the current mass of all the resources the drone’s cargo.

[Return to the TOC](#Table-of-Contents)

##### Table: transmissions
The dynamic transmissions table is an adjunct to the events table that is used to store the data contained in drone communications with the drone.
* id [INT(10)] - Table index
* content [VARCHAR(256)] - The text being transmitted

[Return to the TOC](#Table-of-Contents)

### The Perform Function
The perform function applies a general algorithm, which uses the action tables, in conjunction with stack-supplied values to implement a broad range of in-game actions. It does so by performing the following procedure whenever it is called:
1. When the perform function is called, it pulls the first number from the stack and uses that to look up the corresponding Protocol ID in the Protocols Table.  The function aborts the protocol and returns an error if the protocol is not available.
2. Create a new actions table entry for the executed protocol.  Set the aborted field to false unless the action is aborted as a result of an error at some point during the following procedure or later in the game.
3. Next, use the protocol table’s parameter field to determine how many values need to be pulled from the stack and stored in a parameter array for future reference. If there are not enough values on the stack, FORTH's stack underflow error (-4) stops the script and the action is aborted.
4. Then, for protocols whose multiplier flag is set (push and pull), the process multiplier, which is required (how many times the process is performed in a row; many protocols have bulk modifiers, which reduce the time repetitive actions take; see [Process Multiplier](#Process-Multiplier)). For a protocol that moves the drone to a location given on the stack (a location effect with location -1: travel, `N 5001 perform`), the destination, if the stack isn't empty. No other protocol takes anything more, so values below its parameters stay on the stack for the script. Before #236 and #315, protocols took one more value whenever the stack wasn't empty, as an optional multiplier or location, so `perform` inside a word ate the caller's values.
5. If the protocol moves the drone (it has a location effect), work out the trip time from the distance between the drone's body and the destination (see [Game time runs on a master clock](#Game-time-runs-on-a-master-clock)) and delay the move, and every effect timed at or after it, to match.
6. When performing a multiplexed action, multiply all consumed and produced resources by the process multiplier.  Similarly multiply the time offsets for each of the events by the process multiplier and then by the bulk modifier to account for bulk process efficiencies.
7. Sequentially search for the Protocol ID in each of the effects tables. Apply variable parameters where indicated (fields with negative numbers). Generate a corresponding event for each effect, timed by its game-time offset ÷ the game time scale. The events are inserted together, sorted by game time; at equal times the action's fuel cost comes first, then each equipment check's two halves together, then the other depletions, then the rest (see above). If an effect can't be applied (for example, a parameter names a resource that doesn't exist), delete the action's events, abort the action and leave -12.
8. Otherwise, pause the script until the Event Engine has processed all of the action's events, then leave 0 or the failure code (see [Returned Value](#Returned-Value)).

<a name="Waiting"></a>**Waiting (#324 step 14).** Protocol 3, "Wait": `[M 3 perform]` pauses the script for M game minutes (1 to 10,080, `MAX_WAIT_MINUTES`; otherwise `perform` is refused with -12). Its one resource effect is a wait event (type 12), which `protocol_plan_wait` puts M × 60 game seconds ahead (`ProtocolInfo_t.wait_delay`, like a launch's light time). Like any `perform`, the script stays paused (`agents.waiting_action`) until the Event Engine has processed the event, which it simply marks done. A paused script runs no instructions, so it burns no power, and the protocol burns no fuel: waiting is free. It replaces polling in a loop, which costs fuel for every 100,000 instructions.

A waiting drone still sells. A trade is the buyer's action: the buyer pulls from the seller's sell order (or pushes to its buy order), and the seller's script doesn't run, so selling while waiting costs the seller no instructions and no power. For that, a buyer's action doesn't wait for the seller. An action starts a game second after the acting drone's latest event, done or still to come (`db_get_latest_processed_game_t`; a wait event changes nothing, so it is left out). That keeps each drone's events in game-time order, which the inventory needs: it takes the highest event ID of each stock entry (drone, resource, installed, locked) as its latest state. Another drone the action touches, the seller in a pull or the buyer in a push, holds it back only by its events on the stock entries this action changes (`db_get_latest_entry_game_t`, #380): almost never, since a seller rarely has the good it sells still on its way. Until #380 every event of every drone touched counted, so a pull from a drone that was mining or making something waited until that action ended, up to hours. Both drones must still be at the same place when the transfer is processed (`event_process_transfer`).

<a name="Local-Information"></a>**Local information (#324 step 14).** A drone sees live market orders only where it is. Queries 601, 611 and 612 used to list orders anywhere in the solar system; now they list the orders at the drone's location (601 and 611 are the same as 600 and 610, and 612 is "sell orders here up to a price"), so existing scripts keep working. For elsewhere there is the public **price board**, query 680: for a resource, each place where it traded in the game day up to one game hour ago, with units and credits (doubles) and the number of trades, from the `trades` table. Query 621 (solar market volume) also stops one game hour ago (`BOARD_LAG` in `build_game_data.py`). Deposits (640), stations (630–632) and wrecks (660, 661) were already local.

[Return to the TOC](#Table-of-Contents)

### Ad Hoc Protocols
When someone executes "perform", the Game Engine will pull the first number off the stack and determine whether it indicates that the Game Engine should perform a special command. This would be a command that is not addressed in either the protocols or queries tables but that we might add in the future.

If the number does not belong to a special command, the Game Engine will search protocols.id for an entry that matches the number it pulled from the stack.

If it fails to find the value in protocols.id, it will search for the number in queries.id.

If it can't find the number there, it will conclude that the command doesn't exist.

In other words, every time the "perform" command is called the Game Engine engine will follow a simple branching structure:

If the number on the stack corresponds to a specially-defined command, execute that command and then end the routine. (We haven't defined any of these commands yet.)
Else, if the number on the stack matches a protocol ID, implement that protocol and end the routine.
Else, if the number on the stack matches a query ID, implement that query and end the routine.
4, Else, the command is undefined. End the routine without doing anything.
In practice, we'll assign IDs in one number range (say 1001 - 2000) to protocols and IDs in another number range (say 2001 -3000) to queries. Special commands may occupy the value range from 1-1000.

[Return to the TOC](#Table-of-Contents)

### Launching Drones

Protocol 12 turns a ship kit into a new drone: `[D K 12 perform]`, where K is a ship kit (6001–6231) in the launching drone's cargo and D is any drone of the same player, whose program the new drone gets. D may be the launching drone itself.

**When it's performed** (Game Engine), the protocol is refused (`perform` leaves -12) unless K has a hull row (`hulls.id` = K) and D belongs to the launching drone's player. The copy of D's program travels to the launch site at the speed of light, so the launch event is delayed by the light time from D's location (game seconds; nothing when D is at the same place).

**Its effects** (`resource_effects`, generated by `build_game_data.py`): at 0 s, one unit of K (parameter 1) is removed from the launching drone; at 3,600 s (1 game hour) plus the light time, a launch event (type 8) on resource K carries D (parameter 2, in the quantity column). If the kit is missing, the first event fails with -2 and the action is aborted like any other.

**The launch event** (Event Engine) creates the drone, in one step: a new `agents` row owned by the launching drone's player, named `SN<number>` like the starting drones, at the launching drone's `object_id`, with `hull_type` = K and `vm` = a copy of D's `vm` taken now (a fresh VM if D no longer belongs to the player). The event's `new_quantity` is then the new drone's ID. The EE also queues a `<launched>` command for it, with `deliver_at` = now.

<a name="Cloning"></a>**Cloning (#324 step 13).** A launched drone is a clone of its launcher, which pays for it with the ship kit:

* **Lineage.** The new drone records `agents.parent_id` (the launching drone), `generation` (the launcher's + 1; 0 for a drone released on the website or registered by email) and `code_from` (D, when its program was copied). The website shows a drone's parent and clones from these, and query 670 ("My clones") and 671 ("My lineage") let a script find them: `perform` only leaves an outcome, so a parent finds its new clone's ID with query 670.
* **Its tank.** A clone would otherwise start with an empty hold and become a wreck at its first computing bill, before its parent could push it fuel. So the launch's fuel cost is 30: 10 are burned and 20 (`LAUNCH_FUEL` in `common_types.h` and `build_game_data.py`) go to the clone. The launch event adds an increment event of 20 Fuel (A1) to the new drone, in the same action, which the EE processes next. Without 30 fuel the launch fails with -2 before the kit is used.
* Everything else in its hold is empty: its parent pushes it what it needs.

**`<launched>`** (Game Engine) brings the copied VM back to the prompt, as Forth's `ABORT` does: empty stacks and no paused script, even if D was in the middle of one (as it is when a drone copies itself: its script waits in `perform` for the launch). The words and variables stay. It emails the player that the drone has launched (from the new drone's address, so the player learns it), then looks up the word `launched` (case-sensitive) with `' ' catch launched` and, if it's defined, runs it once as a script: a launched drone can start working unattended. Its output is emailed when it finishes, like any script's.

The first time a drone runs `<launched>`, its VM as it is then is saved in `agents.release_vm`: the clean copy a crash restarts from. A queued `<launched>` waits while the drone still has a script running (a resume queued), so a script sent just before it, such as a released one, loads in full first.

<a name="Crashes"></a>**Crashes.** The Game Engine runs the word as `' launched catch` and sets `agents.launched_running`. The `catch` frame lives in the VM, so it survives the script pausing in `perform` or at the end of an instruction slice. When the run ends (it doesn't pause), the engine takes `catch`'s code off the stack (`crash_launched_result` in `crash.c`):

- 0: `launched` returned normally. The drone has finished (`crashes_in_row` goes back to 0) and its reply ends "My launched word has finished."
- Anything else is a crash: the code `catch` caught, -4 (stack underflow) if the word emptied the stack below `catch`'s frame, or the VM's result when it halted (-1, e.g. after `abort`).

After a crash (`engine_end_launched` in `engine.c`), with `crashes_in_row` + 1 = n:

- n < `CRASH_LIMIT` (game-engine.conf, 5): the drone restarts. Its VM is put back to `release_vm` once this command is saved, a new `<launched>` command is queued with `deliver_at` = now + `CRASH_RESTART_WAIT` x 2^(n-1) game seconds (3600, doubling), and `CRASH_RESTART_INSTRUCTIONS` (1000) are added to `agents.instructions` (a stand-in for the fuel a restart will cost).
- n = `CRASH_LIMIT`: the drone becomes a wreck (`wrecked_at` is set). A wreck runs no more commands: each gets the reply "This drone is a wreck". Its cargo stays aboard for plunderers ([Wrecks and plunder](#Wrecks)).

Each crash is recorded in `drone_crashes`. `crashes_in_row` goes back to 0 whenever one of the drone's actions succeeds (`perform` leaves 0), so only crashes with no progress in between add up to a wreck. `<abort>` and `<reset>` stop the run without counting a crash. A `launched` word that loops forever isn't a crash: it keeps running, one instruction slice at a time.

The hulls come from the kits' names (`HULL_SIZES` in `build_game_data.py`), and `game_data.sql` upserts them, leaving hulls 1 and 2 alone:

| Kit size | Cargo capacity | A–E slots |
|:--|--:|:--|
| Cruiser | 500 | 2, 2, 1, 0, 0 |
| Cutter | 2,000 | 3, 3, 2, 0, 0 |
| Frigate | 5,000 | 4, 3, 2, 1, 0 |
| Barge | 20,000 | 6, 4, 3, 2, 1 |

Slots aren't used by the engines yet, and barges (described as stationary) can still travel if given an engine.

`resource_effects.quantity` must be signed for parameters (-1, -2…): the old `exmachinis_tables.sql` had it unsigned, so `game_data.sql` alters it.

[Return to the TOC](#Table-of-Contents)

### Tips for creating protocols
When creating protocols, use the following guidelines to ensure that they funciton correctly:
1. First, test for the presence of required modules by simultaneously decrementing and incrementing each module by the same amount at the very start of the protocol.  This way, the protocol will abort if the required modules are not present and the player will not loose any resources.
2. Next, deplete all consumable reseources so that they are removed from the player's inventory.
3. Then, lock each required module by decrementing the unlocked item and incrementing the locked item.
4. Wait the amount of time that's required to perform the action.
5. Unlock the required modules by decrementing the locked items and incrementing the unlocked items.  Do this before adding the products to ensure that the protocol isn't aborted for lack of cargo space.
6. Add manufactured products to the cargo hold at the very end of the protocol.

In general, protocols will be configured as follows, where T is the duration of the protocol in seconds:
| Time (sec) | Phase | Events |
|:-----------|:------|:-------|
| 0 | Test modules | Simultaneously increment and decrement all modules required for the protocol to make sure that they're present. |
| 1 | Remove consumables | Simultaneously remove all resources that will be consumed by the protocol. |
| 2 | Lock modules | Simultaneously move all modules to a locked state. |
| T - 1 | Unlock modules | Simultaneously move all modules to an unlocked state. |
| T | Add products | Simultaneously add all protocol products to the cargo bay. |

#### Production stages, tools and timing

Every production recipe follows this pattern with one tool module and a duration T in game seconds, set by its stage (`production_stage` in `game-engine/sql/game-data/build_game_data.py`; the game clock converts to real time, see [Game time runs on a master clock](#Game-time-runs-on-a-master-clock)):

| Stage | Protocols | Tool | T (game time) |
|:--|:--|:--|:--|
| Mining | 11xxx | Slug, Worm or Crawler (A1) | 1 hour |
| Refining ore | 21xxx | Smelter (A1) | 2 hours |
| Alloys | 12021–12023 | Smelter (A1) | 2 hours |
| Organics and polymers | 12042–12053 | Chemical Reactor (A1) | 2 hours |
| Metal parts | 13xxx | Smelter (A1) | 4 hours |
| Polymer parts | 13141, 13301 | Chemical Reactor (A1) | 4 hours |
| Systems | 14xxx | Spider (A1) | 8 hours |
| Spider, Welder, Riveter | 15501, 15511, 15521 | Spider (A1) | 1 day |
| Other modules | 15xxx | Welder (A1) | 1 day |
| Ship kits | 16xxx | Riveter (A1) | 3 days |

The starter kit (25901) holds the Smelter (A1), Chemical Reactor (A1) and Spider (A1), which cover every stage up to systems; the Spider builds the Welder and Riveter. So no recipe needs a tool that can only be made with itself. The specialised manufacturing modules (Lathe, Roller, Extruder, Cutter, Grinder, Press) are not required by any A1 recipe; they are kept for larger items later. Push and pull (10, 11) take one game minute.

Recipe quantities come from `resources.xlsx`. Recipes it doesn't have yet (the 14 combat and assembly modules and the 12 ship kits) are in `game-data/recipes-extra.csv`: modules from one of each of two to five systems, kits from modules whose masses add up to the kit's mass. Item masses are the Resources sheet's; recipes don't conserve mass. Recipe names and descriptions are generated from the data. Ship kits are launched as drones with protocol 12 ([Launching Drones](#Launching-Drones)).

[Return to the TOC](#Table-of-Contents)

## The Event Engine

The Event Engine (EE) is a C++ application that runs continuously in the background and monitors the Events Table for events that have occurred but have not yet been processed (outcome = 0).  When it finds such an event, the EE makes sure the event can still take place (i.e. the required conditions and resources exist), converts changed quantities to total quantities, determines if and when other spacecraft will observe the event, and cleans up expired events.  

The Events Engine takes the following steps when evaluating events:

1. The EE scans the events table for any unprocessed events (outcome = 0) that occurred earlier than the current game time and are not associated with an aborted action. It processes them in time order. Events in the same second run in the order their actions were started, so an action always sees the inventory left by an earlier one; within one action, they run in the order the Game Engine inserted them, which is game-time order with, at equal times, the fuel burn first, then equipment checks, then other depletions.

2. Whenever the EE processes a harvest event (events.event_type = 1), the it multiplies the events.new_quantity value by the abundancies.multiplier value for the first entry in the abundancies table with the same drone location and resource values as indicated by the event.  If a matching entry is not found in the table, events.new_quantity is set to zero, indicating that the resource cannot be harvested from that location.

3. When it finds such an event, the EE will update the resource, credit, and cargo quantities so they represent total values rather than changes in the total value.  Important: This adjustment will NOT be performed on events associated with market actions.  Depending on the affected quantity, the EE will compute the totals as follows:
  * new_quantities. The EE will search for the last processed event with the same drone, resource, installed, and locked values (if such an event exists) and add that quantity to the new_quantities field of the current event.  If the resulting value is less than zero the event will not occur.
  * new_cargo.  The EE will search for the last processed event with the same drone and add that quantity to the new_cargo field of the current event.
  * new_credit.  The EE will search for the last processed event for any drone belonging to the owner of the current drone and add the quantity of the new_credit field of the current event.

   Because new_quantity counts one state of a resource (its installed and locked values: a module in use is locked), a drone's holding of a resource is the sum of the latest successful inventory event of each state. The `sqInventory` view (used by the inventory and market queries), the cargo manifest on the website and the EE's cargo mass all add the states up this way, so a locked module still counts (#210).

4. Next, the EE will ensure that the conditions are sufficient for the event to take place.  It will do so by checking for the following errors:
  * The local field is set to true but the affected drone is not local. The outcome will be set to -1 if this error is detected.
  * The affected ship contains insufficient resources of the indicated state (identical resource, locked, and installed values) to accommodate a depletion event. This happens when the newly computed new_quantity field is less than zero. The outcome will be set to -2 if this error is detected.
  * There affected ship contains insufficient cargo space to accommodate the accumulation event. This happens when the recently computed new_cargo value is greater than the cargo capacity of the drone (found in hull table).  The outcome will be set to -3 if this error is detected.
  * The depletion event will violate the affected drone’s self imposed lower limit for the resource. This only applies to cases in which the acting and affected ships are different. The outcome will be set to -5 if this error is detected.
  * The accumulation event will violate the affected drone’s self imposed upper limit for the resource. This only applies to cases in which the acting and affected ships are different. The outcome will be set to -6 if this error is detected.
  * The Depletion Price for items being removed from the cargo bay of a non-allied ship is equal to or greater than the price the player has set on those items. The outcome will be set to -7 if this error is detected.
  * The Accumulation Price for items being added to the cargo bay of a non-allied ship is less than or equal to the price the player has set for those items. The outcome will be set to -8 if this error is detected.

5. If any of these errors are detected, the EE will update the event’s outcome with the indicated value and flag the corresponding action in the action table as aborted. It also stores the first failing outcome in `actions.outcome`, which `perform` leaves on the drone's stack when the script resumes. Failures are not emailed: drones often run unattended, so their scripts check the result and respond. Only errors that stop a script reach the player, in the script's email. A push or pull to another player's drone that has no matching buy or sell order fails with outcome -10. (The engines add the `actions.outcome`, `agents.waiting_action` and `agents.pending_input` columns themselves to databases created before them.)

   **Transfers (push, protocol 10, and pull, protocol 11) are all or nothing.** A transfer is two events: a depletion from one drone and an accumulation into the other. The EE checks first that both drones orbit the same object (outcome -1 otherwise), then processes both events together, ignoring the second one's later timestamp. If either fails, everything both did (inventory, cargo and credits) is rolled back: the failing event gets its error outcome, the other gets -9, and the action is aborted. So a buyer never pays for goods that don't arrive, and a seller never loses goods without being paid.

6. Removing Old Data. Otherwise, the event’s outcome will be set to 1 and any events (processed or otherwise) with the same event type, drone, resource, installed, and locked values that are older than the game’s data lifespan will be deleted.  Any observation table entries that are associated with a deleted event will also be deleted.  This helps ensure that the database size does not grow beyond limits.

7. Then, the EE creates a new observations table entry for the acting and affected drones and sets the observation time to that of the event plus the distance in light minutes between the acting and affected drones (observation time = current time + distance between drones).  For drones that are local to the event (meaning they are orbiting the same static object), the observation time is identical to the event time.

8. If the event is reportable (i.e. the reportable field is true), then the EE creates an observations table entry for the Central Database (Drone ID = 0) and sets the observation time to the event time plus the distance of the affected drone from the Earth in light minutes (observation time = current time + distance to Earth).  This is because the event is being reported to the Earth from the spacecraft.

9. If the event is observable and the event was successfully processed  (i.e. the observable field is true and status = 1), the EE also creates observation table entries for each local drone and sets the observation time to the event time (observation time = current time).

[Return to the TOC](#Table-of-Contents)

#### Table: observations
Since evidence of each in-game event travels between distant points at the speed of light, it may be hours before a drone on the other side of the solar system is aware that an event has occurred. The Observations Table tracks what each drone knows and when it learns it. The table directly links drones to the events they observe or experience using the following fields:
* id [INT(10)] - This is the ID of the current observation.
* drone [INT(5)] - This is the ID of the drone that observed the change.
* event [INT(10)] - This is the ID of the observed event.
* time [DATETIME] - This is the time at which the drone observed the state change.

[Return to the TOC](#Table-of-Contents)

## Markets and Trade

<a name="Drop-Stations"></a>**House drop stations (#324 step 10).** House stations bring credits and basic equipment into the game and take finished goods out of it.

* **Game data** (`build_game_data.py`, replaced on every start). `stations` (id, name, `object_id`, `budget_cap`, `refill_per_day`) and `station_goods` (station, resource, side, price, `daily_units`, `from_day`). A made good's buying price is `made_value` (#380): the value of its inputs, plus its recipe's fuel at 5 a unit, plus a wage for each game hour its tool works (`WAGE`: 20 for alloys and polymers, 25 for parts, 30 for systems), divided by the units the recipe makes. Raw goods keep fixed prices (`RAW_VALUE`); mining and refining the best ore earns about 17 a game hour. Until round 22 an alloy sold for less than its metals and a gear box earned about 4 a game hour, so playtesters only mined.
  * Side 1 means the station buys: `price` is its price for an empty batch and `daily_units` its daily demand.
  * Side 2 means it sells: `price` is the price and `daily_units` its daily supply.
  * `from_day` is the game day, counted from the world's first, on which it starts trading that good. Stations buy refined goods from day 0, parts from day 3 and systems from day 7, so demand steps up the production chain.
  * Placeholders: the Earth Exchange (3), the Moon Depot (10) and the Mars Exchange (4), each with a 5,000-credit budget refilling 5,000 a game day, except the Mars Exchange: 15,000 (#378), since two thirds of the starter miners and refiners sell there, and every trader. Every exchange sells fuel at 5 credits a unit, 2,000 a day (the Earth Exchange alone until #367; the Mars Exchange 6,000 since #378). Since #380 every exchange also sells Mining Cruiser (A1) kits (6001) for 3,000 and Starter Kit I (5901) for 2,000, 24 a day (one a batch: a batch's supply is `daily_units` ÷ 24, so fewer than 24 a day sells nothing), so a company can launch clones; both kits are flat-packed to 40 cargo (they were 224 and 375, and a working drone's equipment takes 364 of its 500). Migration 0021 keeps the House price of a Starter Kit I at its contents, not its sale price, so assessments don't count it twice.
  * Refined metals (#324 step 15): the Earth Exchange and the Moon Depot pay 20, 30 and 40 for Metals (A1), (A2) and (A3) (they paid 10, 15 and 20, which left a simple Moon miner losing money), and the Mars Exchange, far away, pays half as much again (30, 45, 60), so hauling metals there pays.
* **State.** `station_state` holds each station's budget and last refill. It starts full (`INSERT IGNORE`) and is kept across game-data reloads.
* **Offers and orders.**
  * Protocol 31 (`[N R 31 perform]`) has two effects. At time 0 an event of type 9 (Offer to station) records the offer. A second later, a decrement takes the goods out of the hold (escrow).
  * Protocol 32 (`[N R 32 perform]`) has an event of type 10 (Order from station).
  * The events engine records either in `station_offers` (`db_station_record`), in the batch open now: `batch_t` is the end of the game hour (`STATION_BATCH_SECONDS`).
  * If no station at the drone's location trades the resource that way (yet), the action fails with -14 (`OUTCOME_NO_STATION`).
* **Clearing** (`db_station_clear_due`, every events-engine loop, in its own transaction). A batch clears once its time has passed and its offers' events have all settled.
  * **Buying:**
    * The budget is refilled for the time since its last refill, up to the cap.
    * One price is set for the batch: `station_buy_price` gives the price for an empty batch × 2^(−offered ÷ daily demand), never below 1.
    * As many units as the budget pays for are shared out (`station_share`): everyone in full if there is enough. Otherwise each company gets a share pro rata to what it offered, capped at `STATION_COMPANY_CAP_PCT` (50%) of the batch, and what that leaves over goes to those still wanting more.
    * Sellers are paid: `companies.credits` rises, recorded as a type 6 credit event.
    * What isn't bought goes back to the hold: a protocol 33 "Station delivery" action with an increment event, which the events engine processes like any other.
    * Only offers whose action went through count. For an offer to sell, that means its escrow event succeeded.
  * **Selling:**
    * The batch's supply is `daily_units` ÷ 24, and the price is fixed.
    * Each order is limited by its company's credits and its drone's free cargo space, then shared out the same way.
    * Buyers pay (a type 7 credit event) and get a delivery.
* **Once only.** Each cleared batch is recorded in `station_batches` (primary key: station, resource, side, batch), in the same transaction as its payments. A crash before the commit undoes everything. A batch already recorded is never cleared again; its offers are only marked cleared.
* **Queries.** 630 (what the station here trades), 631 (a drone's offers and orders) and 632 (the station's budget).
* **Tests.** `test_station.c` covers the price curve and the shares.
* **Not yet:**
  * Budgets that grow with activity (a fixed cap for now).
  * The House fuel ceiling floating with the price of fuel traded between players (fixed at 5).
  * Equipment and fuel sold anywhere but Earth.

In Ex Machinis, spacecraft can use scripted protocols to add or remove materials from the cargo hold of other spacecraft via the resource_effects table. Other than the obvious inventory and cargo hold constraints, there are no limits on how much material can be transferred between two spacecraft that are owned by the same player.  However, if a drone attempts to access the cargo hold of another player’s spacecraft, the interaction will be governed by market orders, which the targeted spacecraft places against specific resources that can be added to or removed from its hold.

[Return to the TOC](#Table-of-Contents)

### Placing Market Orders
These market orders are placed and adjusted by protocols that reference the market_effects table, which is addressed in the Protocols Section of this manual. The market_effects table works by directing the Events Engine to generate buy and sell events that adjust the drone’s criteria for accepting cargo from or releasing cargo to another player’s drones.

By using protocols that invoke the market_effects table, players can set the costs at which the drone will automatically purchase or sell a particular resource from or to another player. The market_effects table also sets limits on the amount of a resource that the drone will buy or sell.  

[Return to the TOC](#Table-of-Contents)

### Acting on Market Orders
One player's drone can act on the market orders of another player's drone by adding or removing resources from the drones's cargo hold in accordance with existing buy or sell orders. Buy orders permit another player's drone to add (increment) a specific resource in the bidder's cargo hold in exchange for credits (money). Similarly, sell orders allow another player's drone to remove (decrement) resources from the cargo hold at a price that its set by the order.  

A drone can only **add** a resource to the cargo hold of another player's drone via an increment event if:
1. The affected drone has placed a buy order for the resource.
2. The incremented resource doesn't exceed the amount of material permitted by the buy order or the target drone's cargo capacity.

Failure to meet these conditions will generate a negative outcome for the increment event and cause the corresponding action and all subsequent events to be aborted.

If the increment event succeeds,  the Event Engine will:
1. Add the correct amount of credits in the user's bank account of the acting drone. This amount is set based on the amount of material successfully added to the hold and the unit price set by the corresponding buy order.
2. Remove the same amount of credits from the user's bank account of the affected drone. It is possible for the number of credits to go negative.

A drone can only **remove** a resource from the cargo hold of another players drone via a depletion event if:
1. The affected drone has placed a sell order for that resource, and
2. The decrement event doesn't drop the amount of resource in the affected drones cargo below zero or below the limit set in the sell order.

Failure to meet these conditions will generate a negative outcome for the decrement event and cause the corresponding action and all subsequent events to be abborted.

If the decrement event succeeds, the Event Engine will:
1. Add the correct amount of credits in the user's bank account of the affected drone. This amount is set based on the amount of material removed from the hold and the unit price set by the buy order.
2. Remove the same amount of credits from the user's bank account of the acting drone. It is possible for the number of credits to go negative.

[Return to the TOC](#Table-of-Contents)

### Adding and removing credits from a user's bank account

When the Event Engine adds or subtracts credits from a user's account, it does so by incrementing or decrementing the companies.credits field of the drone's company by the indicated amount and creating a new events table entry to record this change:

- The companies.credits field can go negative as a result of a transaction.
- Whem creating the corresponding events table entry, the Events Engine will set events.time to the current date/time, events.drone to the ID of the affected drone, events.action to the ID of the related action, events.logged to true, events outcome to 1, and events.event-type to 6 if credits are being added and 7 if credits are being removed. The Events Engine will also set events.new_credits to the new value in companies.credits. All other values will be null.

### Recording trades

The credit events record each player's new balance, not what was paid, so the Event Engine also writes every completed trade to the `trades` table (#249): when, which resource, how many units, how many credits, the selling and buying drones and where (the affected drone's location). It is written inside the transfer's savepoint, so a transfer that is rolled back leaves no trade. Queries 620 (at the requesting drone's location) and 621 (anywhere) add up the units, credits and number of trades of resource N over the last H game hours (the real age of each trade times the game clock's scale). The engines create the table on databases made before it.

### A Trade Example

If a player decides to sell iron ore from one of his drones, he will invoke a protocol, which pulls three values from the stack.  The first value is the Resource ID of the material he wants to sell, the second value is minimum amount of the resource he wants to maintain in the drone's cargo hold (ie. a quantity of iron ore that is NOT for sale), and the third value indicates the unit price that he is requiring for the ore.  The Game Engine uses this information to create a sell-type event, which sets minimums for quantity and cost on the indicated resource via the events.new_quantity and events.new_redits fields.  When the Event Engine processes this event, it simply marks it as complete (events.outcome=1). It doesn't need to make any checks on the availability of resources because no resources are being created or depleted.

When another player wants to buy iron ore he will search the database for sell events and discover that the drone described above is selling some amount of ore (ore for sale = total amount in cargo - minimum amount in sell event) for a certain price (indicated in the sell event's new_credits field).  Consequently, the buyer will send one of his drones to the location of the drone that's selling the resource and invoke a transfer protocol, which moves cargo from the hold of the selling drone to the hold of his own drone. This transfer protocol will use resource_effects table entries to deplete a specified amount of the ore from the selling drone's cargo and add an equivalent amount of the resource to its own cargo.  

When the Event Engine processes the resulting depletion event (removing the ore from the seller's cargo), it will ensure that the indicated amount of resources are available for sale. In other words, there must be enough resources in the cargo hold to accommodate the depletion without allowing the total quantity to drop below the level set by the sell order. It also checks that the buyer has enough credits for the whole purchase (outcome -4 otherwise). If there are enough resources and credits, the depletion event will occur and the Event Engine will create two new contemporaneous events to remove credits from the buyer and give them to the seller.  Otherwise, the event will be blocked and the other events associated with that action will be aborted.

Provided the depletion event occurs without a problem, the accumulation event of the same transfer adds the depleted resources to the buyer's cargo hold if there is enough room in the hold. If there isn't, the whole transfer is undone, including the payment (see step 5 of the Events Engine above).

Buy orders will be fulfilled in much the same way. The only difference is that the Event Engine will check for a buy order when another player attempts to add material's to another player's cargo hold.  In this case, the Event Engine will ensure that that the added resources do not exceed the limit set by the corresponding buy order and that they do not exceed the capacity of the cargo hold.  If the materials are successfully added to the buyer's cargo bay, the Event Engine will create concurrent events, which remove credits from the buyer's bank account and add them to the seller's bank account.

[Return to the TOC](#Table-of-Contents)

## Queries

### Database Searches
Each drone has access to an internal database, which contains information on events that directly affected the drone, were observed by the drone, or were reported to the Earth-bound Central Database and uploaded to the drone.  Therefore, a collection of words that can be used to query the onboard database will be a key part of the FORTH lexicon.
Drones can access their internal database (a subset of the Game Database) via predefined SQL queries. The queries will serve to organize the data for player consumption as well as limit the information in the Game Database that the players have access to.  Available queries are defined via the queries table, which is described below.
The drone’s internal database and the much larger Central Database are virtual constructs, which consist of a subset of the data contained in the Game Database.  Basically, each drone will be able to search the Game Database for any events that are linked to the drone’s ID or the Central Database ID (Drone ID = 0) via the links formed by the observations table.  In addition to using the observations table to search event data, the drones will also be able to search data contained in many of the static tables such as the objects and protocols tables.

[Return to the TOC](#Table-of-Contents)

#### Running a Query  
Drones can evoke any one of the predefined ingame queries with the FORTH-based query command.  Depending on the specific query, the query command pulls three or more values from the stack as follows:
The number of the query that the player is invoking

1. A pointer to a Results Array that will receive the query results
2. A value that indicates the size of the Results Array in bytes
3. Any additional parameters that are required by the specific query

[Return to the TOC](#Table-of-Contents)

#### The Queries Table
A single table is used to define the queries that are available to drones through the FORTH query interface.  This is a static table, which does not change as the result of gameplay.  However, the table can be edited by the system administrator to change the type and scope of queries that are available to the players.

[Return to the TOC](#Table-of-Contents)

##### Table: queries
The queries table provides a name and description for each of the possible drone-invoked queries. It also has a field that contains the SQL script for the query, which is written by the system administrator when the query is defined.
id [INT(5)] - This is the table index.  This is the value that the FORTH query routine uses to identify the query.  Max 65,534 (FFFF).

* name  [VARCHAR(45)] - This is the name of the query.
* description [VARCHAR(255)] - This is a brief description of the query.
* parameters [INT(1)] - This is the number of query-specific parameters that are pulled from the stack.
* script  [VARCHAR(255)] - This is the SQL-formatted query script for the particular query. The script is created by the * system administrator using standard SQL syntax.  The system administrator can add variables to the script, which will be replaced by user-supplied parameters or environmental variables when the query is executed

[Return to the TOC](#Table-of-Contents)

#### Query Variables
When writing a script for the queries.script field, the system administrator will use SQL syntax to indicate how data will be selected, sorted, and reported back to the drone.  In addition to naming specific tables, fields, joins, and search terms in the query script, the system administrator can include variables, which will be replaced with player or Game Engine supplied values when the query is actually performed,  The variables that the system administrator will be able to use in the query script are as follows:

* [value_1] will be replaced by the first query specific parameter that is pulled from the stack (the last one pushed) and rendered as a signed decimal integer, so -1 stays -1. [value_2] to [value_9] will do the same with the second to ninth query-specific parameters that are pulled from the stack. queries.script holds up to 2,000 characters (`game_data.sql` widens older databases).
* [dvalue_1] to [dvalue_4] (#324 step 9) are doubles: [dvalue_N] combines parameter N, the double's high cell (on top of the stack), with parameter N+1, its low cell, into one signed 32-bit number. The query counts both cells in its parameters. Query 612 (Solar sell orders up to a price) uses [dvalue_1] for the price and [value_3] for the resource.
* [string_1] will be replaced by the string that is pointed to by the first query-specific parameter on the stack.  The ASCII string will be pulled from the drone’s memory location pointed to by the user supplied address. [string_2], [string_3], and [string_4] will do the same for the second, third, and fourth user-supplied values found on the stack. Because the string is placed directly into the SQL, it may contain only letters, digits, spaces, hyphens, underscores and full stops; any other character makes the query fail.
* [drone_id] will be replaced by the integer ID of the drone that is performing the query.
* [time_delay] will be replaced by the distance in light minutes that the drone is from Earth.

Query 803 (Search observed events, #149) shows how far this goes: nine parameters, each compared only when it isn't negative (`([value_n] < 0 OR column = [value_n])`), and only events the requesting drone has observed: an observations row (`observations.drone = [drone_id]` and `observations.game_t <= [game_now]`), or a local sighting worked out from `events.place` and `agents.arrived_t`. A drone sees events that affected it, observable events where it is now since it arrived (see [Local sightings](#Local-Sightings)), and nothing else yet: there is no relay of the Central Database's observations to drones. Its "at least M game minutes old" filter converts the real age of the event to game time with the game clock's scale.

[Return to the TOC](#Table-of-Contents)

#### The Query Algorithm
The query command provides a general mechanism for players to query portions of the Game Database from within the FORTH programming environment.  As mentioned above, the query command pulls three or more values off the stack, which are used to define the query and its output.  Whenever the FORTH VM executes a query command, the Game Engine performs the following steps:

1. The Game Engine begins by pulling the first value off of the stack and using it to look up the player-evoked query in the queries table via queries.id.  This entry will tell the Game Engine how many query-specific parameters to pull off the stack and what SQL script to run when executing the query. If there is no such query, it prints "Query ID [n] not found in DB", places -3 on the stack and ends. The query's other arguments stay on the stack: without the query, the engine can't know how many there are.
2. Next, the Game Engine pulls a value off the stack that lists the maximum size of the Results Array in bytes. The Game Engine will not write to the Results Array beyond this point.
3. The Game Engine then retrieves the Results Array Pointer from the stack. This pointer tells the Game Engine where to report the query results.
4. The Game Engine will likely also retrieve query-specific values from the stack. The number of values that are pulled from the stack is specified in queries.parameters.  If there aren’t enough values on the stack to complete the query, FORTH's stack underflow error (-4) stops the script, as for any word that runs out of values.
5. Once it has retrieved all the required parameters from the stack, the Game Engine replaces each of the variables in a copy of the queries.script string with the corresponding user and Game Engine supplied values as indicated in the preceding Query Variables section.
6. The Game Engine executes the resulting SQL query.  If the query fails or finds no rows, the Game Engine places -2 on the stack and ends the FORTH-invoked query routine.  Otherwise, it stores the results in the VM, starting at the location indicated by the Results Array Pointer, and places the number of rows it stored on the stack (#256). That is at least 1, or 0 when the Results Array is too small for even the first row.
7. Rows are stored one after another, each field in turn, and only whole rows: when the next row doesn't fit in the Results Array, the Game Engine stops. Every number (whatever its SQL type: counts, sums and decimals included) is rounded to an integer and stored as one 16-bit cell (2 bytes), except a column whose name starts with `d_` (#324 step 9): it is a double, stored in 4 bytes, the high cell first so `2@` reads it, as a signed 32-bit value (clamped to that range). Money and volume columns are named this way: `d_credits` (101, 841), `d_price` (600, 601, 610, 611, 612), `d_units` and `d_credits` (620, 621). Text is stored as a counted string: a length byte followed by the characters (at most 255), with no terminating zero. Times take 4 bytes: the game day, counted from the world's first game second (`game_clock.epoch`, game day 0), then the number of even seconds (every other second) into that game day, each a 16-bit value. Query times are game times: queries compare `game_t` columns with the `[game_now]` tag, the current game time in game seconds, and return a time as `FROM_UNIXTIME(game_t)`. A row of numbers only therefore has a fixed size (2 bytes per column), so a script finds row n at R + n × size; see the players' manual.

[Return to the TOC](#Table-of-Contents)

#### First Query Example
One query, which will be available to players, reports the most recent events that were experienced by a drone.  This query will be defined with the following queries table entry:
~~~
id=1; name=”Recent Events”; parameters=1; script=”SELECT events.id, events.time FROM observations INNER JOIN events ON observations.event =events.id WHERE observations.drone=[drone_id] AND observations.time BETWEEN CURRENT_TIMESTAMP AND CURRNET_TIMESTAMP - INTERVAL [value_1] MINUTE ORDER BY events.time DESC;”
~~~
The player will evoke this query by sending something like the following instruction to his drone:
~~~
<run>
  variable results_array ( create a variable to accept the query result )
  200 allot ( allocate 200 bytes of memory for use with the variable )
  120 200 results_array 1 query ( retrieves all events up to two hours old )
  results_array @ . ( prints the ID of the most recent event )
  results_array 2 + @ . ( prints the date value of the most recent event )
  results_array 4 + @ . ( prints the time value of the most recent event )
  results_array 6 + @ . ( prints the ID of the second most recent event )
</run>
~~~
When the Game Engine process the query command it will use the first number on the stack (1) to look up the query that’s being invoked by the drone (queries.id = 1).  This will tell the Game Engine that it should pull one additional parameter-specific value from the stack (queries.parameters = 1).

Then, the Game Engine will pull the pointer to the Results Array from the stack (placed on the stack by results_array) and the value that indicates the maximum size of the array (200).  These values will tell the Game Engine where it should report the results.

Finally, the Game Engine will pull the one query-specific value from the stack (120).  It will replace [value_1] with “120” in the script.  It will also replace [drone_id] with the ID of the drone that’s running the script.

Having replaced the variables in the SQL script with real values, the Game Engine will execute the query via a C++ SQL database call.  It will then parse the returned values into the VM at the location that’s pointed to by the results_array.  Since, in this case, the returned results are integers, the Game Engine will record a series of 16 bit values in the VM.  It will do this until it reaches the end of the search results or the upper bound placed on the Results Array (results_array + 200).  The 16-bit values will be arrayed in memory this particular query as follows:
~~~
[First Event ID - 2] [First Date Value - 2] [First Time Value - 2] [Second Event ID - 2] [Second Date Value - 2] [Second Time Value - 2] …
~~~
In the above memory map, each value is accompanied by a number that indicates how many bytes it occupies.

[Return to the TOC](#Table-of-Contents)

#### Second Query Example
Another query would allow players to look up the ID of a specific orbital object.  This query would be defined in the queries table as follows:
~~~
 id=2; name=”Get Object ID”; parameters=1; script=”SELECT objects.id FROM objects WHERE objects.name=”[string_1]” ”
~~~
The player would likely invoke this query as follows:
~~~
 <run>
   : earth $“ Earth” ; ( create a named search string )
   earth 2 results_array 2 query ( retrieves the object ID for Earth )
   results_array @ . ( prints the ID of the most recent event )
 </run>
~~~
As with the other example, the Game Engine will begin by pulling values for the query ID, Results Array pointer, and Results Array size off the stack.  It will use the query ID to look up the query script in the queries table and determine whether it needs to grab additional values from the stack.  In this case, the queries table entry indicates that it needs to pull one query-specific parameter from the stack.  This will be the value placed on the stack by the FORTH word “earth”.

When the word earth is called, it places an address on the stack that points to the string “Earth” in the VM.  However, since $” creates a counted string, the first byte at this location contains an 8-bit value, which indicates the length of the string in bytes.  The Game Engine will use this pointer and the byte to pull the search string “Earth” from memory and replace \[string_1\] with the “Earth” in the query script.

After implementing the query, the Game Engine will place the results in the Results Array.  In this case, the query should only return one integer value, which will occupy the first two bytes of the array.  The player will access this value by fetching (@) the 16-bit value from the address provided by results_array.

[Return to the TOC](#Table-of-Contents)

## Appendix A: In-Game Queries

This appendix lists the in-game queries loaded into the game, organized by the database structures they support. Players run a query with `N R L <id> query`: N is the query's parameter (if any), R the results array and L its length in bytes.

<!-- queries:start (generated by game-engine/sql/game-data/build_game_data.py; do not edit) -->
| Category | Query ID | Name | Usage and description | Parameters | MySQL script |
|:--|:--|:--|:--|:--|:--|
| Companies | 100 | Get company name | [N R L 100 query]: Returns the name for company N. | 1 | `SELECT name FROM companies WHERE company_id = [value_1];` |
| Companies | 101 | Get company credits | [N R L 101 query]: Returns the number of credits in the company's bank account were Company ID = N. Money and volumes are double cells (2@). | 1 | `SELECT credits AS d_credits FROM companies WHERE company_id = [value_1];` |
| Companies | 110 | List company spacecraft | [N R L 110 query]: Returns a list of the IDs for the spacecraft that are owned by company N. | 1 | `SELECT agent_id FROM agents WHERE company_id = [value_1] ORDER BY agent_id;` |
| Spacecraft | 200 | Get spacecraft name | [N R L 200 query]: Returns the spacecraft name [30] for Spacraft ID N. | 1 | `SELECT agents.name FROM agents WHERE (((agents.agent_id)=[value_1]));` |
| Spacecraft | 201 | Get spacecraft owner | [N R L 201 query]: Return the Company ID of the compony that owns Spacecraft ID N. | 1 | `SELECT company_id FROM agents WHERE agent_id = [value_1];` |
| Spacecraft | 202 | Get spaecraft location | [N R L 202 query]: Returns the location ID of spacecraft N. | 1 | `SELECT object_id FROM agents WHERE agent_id = [value_1];` |
| Spacecraft | 203 | Get spacecraft hull type | [N R L 203 query]: Retuns the hull type of spacecraft N. | 1 | `SELECT hull_type FROM agents WHERE agent_id=[value_1];` |
| Spacecraft | 204 | Get cargo mass | [N R L 204 query]: Returns the total cargo mass in kg of spacecraft N. | 1 | `SELECT IFNULL(SUM(i.units * r.mass), 0) FROM sqInventory AS i INNER JOIN resources AS r ON r.id = i.resource WHERE i.drone = [value_1];` |
| Spacecraft | 205 | Get my spacecraft ID | [R L 205 query]: Returns the spacecraft ID of the requesting spacecraft. | 0 | `SELECT agent_id FROM agents WHERE agent_id = [drone_id];` |
| Spacecraft | 207 | Game time now | [R L 207 query]: The game time now: the game day (day 0 is the world's first) and the minutes into it (0 to 1,439). | 0 | `SELECT FLOOR(([game_now] - epoch) / 86400) AS day, FLOOR(MOD([game_now] - epoch, 86400) / 60) AS minute FROM game_clock WHERE id = 1;` |
| Spacecraft | 210 | List spacecraft inventory | [N R L 210 query]: Lists the resources and quantities in the cargo hold of spacecraft N. | 1 | `SELECT resource, units FROM sqInventory WHERE drone = [value_1] AND units > 0 ORDER BY units DESC;` |
| Spacecraft | 212 | Get resource quantity | [N R L 212 query]: Returns how many units of resource N are in the requesting spacecraft's cargo hold. | 1 | `SELECT IFNULL(MAX(units), 0) FROM sqInventory WHERE drone = [drone_id] AND resource = [value_1];` |
| Hulls | 300 | Get hull type name | [N R L 300 query]: Returns the name of hull type N. | 1 | `SELECT name FROM hulls WHERE id=[value_1];` |
| Hulls | 301 | Get hull type cargo capacity | [N R L 301 query]: Returns the cargo capacity in kg of hull type N. | 1 | `SELECT cargo_capacity FROM hulls WHERE id=[value_1];` |
| Resources | 400 | Get resource name | [N R L 400 query]: Retuns the name of resource N. | 1 | `SELECT name FROM resources WHERE id=[value_1];` |
| Resources | 401 | Get resource unit mass | [N R L 401 query]: Retuns the unit mass in kg of resource N. | 1 | `SELECT mass FROM resources WHERE id=[value_1];` |
| Resources | 410 | List best extraction sites | [N R L 410 query]: Returns the top extraction sites and abundancies for the resource N. | 1 | `SELECT location, multiplier FROM abundancies WHERE resource=[value_1] ORDER BY multiplier DESC;` |
| Protocols | 500 | Get protocol name | [N R L 500 query]: Returns the name of protocol N. | 1 | `SELECT name FROM protocols WHERE id = [value_1];` |
| Protocols | 501 | Get protocol duration | [N R L 501 query]: Returns the duration in minutes of protocol N. | 1 | `SELECT MAX(time) FROM (SELECT time FROM resource_effects WHERE protocol = [value_1] UNION SELECT time FROM market_effects WHERE protocol = [value_1]) AS combined;` |
| Protocols | 510 | List consumed resources | [N R L 510 query]: Lists the resources and quantities that are consumed by protocol N. | 1 | `SELECT resource, quantity FROM resource_effects WHERE protocol=[value_1] AND deplete=1 AND locked=0 ORDER BY quantity DESC;` |
| Protocols | 511 | List produced resources | [N R L 511 query]: Lists the resources and quantities that are produced by protocol N. | 1 | `SELECT resource, quantity FROM resource_effects WHERE protocol=[value_1] AND deplete=0 AND locked=0 ORDER BY quantity DESC;` |
| Protocols | 512 | List retained resources | [N R L 512 query]: Lists the resources and quantities that are used by the protocol but returned when done. | 1 | `SELECT resource, quantity FROM resource_effects WHERE protocol=[value_1] AND deplete=0 AND locked=1 ORDER BY quantity DESC;` |
| Protocols | 520 | List consuming protocols | [N R L 520 query]: Lists all the protocols and qantities that deplete resource N. | 1 | `SELECT protocol, quantity FROM resource_effects WHERE resource = [value_1] AND deplete = 1 AND locked = 0 ORDER BY quantity DESC;` |
| Protocols | 521 | List producing protocols | [N R L 521 query]: Lists all the procedures and quantities that produce resource N. | 1 | `SELECT protocol, quantity FROM resource_effects WHERE resource = [value_1] AND deplete = 0 AND locked = 0 ORDER BY quantity DESC;` |
| Protocols | 522 | List retaining protocols | [N R L 522 query]: Lists all the protocols and quatities that use resource N without consuming it. | 1 | `SELECT protocol, quantity FROM resource_effects WHERE resource = [value_1] AND deplete = 0 AND locked = 1 ORDER BY quantity DESC;` |
| Markets | 600 | List local buy orders | [N R L 600 query]: Lists buy orders for resource N at the requesting spacecraft's location: price, wanted maximum, units held, spacecraft ID. Money and volumes are double cells (2@). | 1 | `SELECT o.new_credits AS d_price, o.new_quantity, IFNULL(i.units, 0), o.drone FROM events AS o INNER JOIN (SELECT MAX(id) AS id FROM events WHERE outcome = 1 AND event_type = 5 AND resource = [value_1] GROUP BY drone) AS l ON l.id = o.id INNER JOIN agents AS a ON a.agent_id = o.drone AND a.object_id = (SELECT object_id FROM agents WHERE agent_id = [drone_id]) LEFT JOIN sqInventory AS i ON i.drone = o.drone AND i.resource = [value_1] WHERE IFNULL(i.units, 0) < o.new_quantity ORDER BY o.new_credits DESC;` |
| Markets | 601 | List buy orders here | [N R L 601 query]: Lists buy orders for resource N where the drone is (like 600): price, wanted maximum, units held, spacecraft ID. Only where the drone is: for elsewhere, see the price board (680). Money and volumes are double cells (2@). | 1 | `SELECT o.new_credits AS d_price, o.new_quantity, IFNULL(i.units, 0), o.drone FROM events AS o INNER JOIN (SELECT MAX(id) AS id FROM events WHERE outcome = 1 AND event_type = 5 AND resource = [value_1] GROUP BY drone) AS l ON l.id = o.id INNER JOIN agents AS a ON a.agent_id = o.drone AND a.object_id = (SELECT object_id FROM agents WHERE agent_id = [drone_id]) LEFT JOIN sqInventory AS i ON i.drone = o.drone AND i.resource = [value_1] WHERE IFNULL(i.units, 0) < o.new_quantity ORDER BY o.new_credits DESC;` |
| Markets | 610 | List local sell orders | [N R L 610 query]: Lists sell orders for resource N at the requesting spacecraft's location: price, kept minimum, units held, spacecraft ID. Money and volumes are double cells (2@). | 1 | `SELECT o.new_credits AS d_price, o.new_quantity, i.units, o.drone FROM events AS o INNER JOIN (SELECT MAX(id) AS id FROM events WHERE outcome = 1 AND event_type = 4 AND resource = [value_1] GROUP BY drone) AS l ON l.id = o.id INNER JOIN agents AS a ON a.agent_id = o.drone AND a.object_id = (SELECT object_id FROM agents WHERE agent_id = [drone_id]) LEFT JOIN sqInventory AS i ON i.drone = o.drone AND i.resource = [value_1] WHERE i.units > o.new_quantity ORDER BY o.new_credits ASC;` |
| Markets | 611 | List sell orders here | [N R L 611 query]: Lists sell orders for resource N where the drone is (like 610): price, kept minimum, units held, spacecraft ID. Only where the drone is: for elsewhere, see the price board (680). Money and volumes are double cells (2@). | 1 | `SELECT o.new_credits AS d_price, o.new_quantity, i.units, o.drone FROM events AS o INNER JOIN (SELECT MAX(id) AS id FROM events WHERE outcome = 1 AND event_type = 4 AND resource = [value_1] GROUP BY drone) AS l ON l.id = o.id INNER JOIN agents AS a ON a.agent_id = o.drone AND a.object_id = (SELECT object_id FROM agents WHERE agent_id = [drone_id]) LEFT JOIN sqInventory AS i ON i.drone = o.drone AND i.resource = [value_1] WHERE i.units > o.new_quantity ORDER BY o.new_credits ASC;` |
| Spacecraft | 612 | Sell orders here up to a price | [N P R L 612 query]: Sell orders for resource N where the drone is at a price of at most P (a double, e.g. 7 10000 um* for 70,000), cheapest first: price (2 cells), kept minimum, stock, drone. | 3 | `SELECT o.new_credits AS d_price, o.new_quantity, i.units, o.drone FROM events AS o INNER JOIN (SELECT MAX(id) AS id FROM events WHERE outcome = 1 AND event_type = 4 AND resource = [value_3] GROUP BY drone) AS l ON l.id = o.id INNER JOIN agents AS a ON a.agent_id = o.drone AND a.object_id = (SELECT object_id FROM agents WHERE agent_id = [drone_id]) LEFT JOIN sqInventory AS i ON i.drone = o.drone AND i.resource = [value_3] WHERE i.units > o.new_quantity AND o.new_credits <= [dvalue_1] ORDER BY o.new_credits ASC;` |
| Spacecraft | 620 | Local market volume | [N H R L 620 query]: Units of resource N traded at the requesting spacecraft's location in the last H game hours, credits paid for them, and number of trades. Money and volumes are double cells (2@). | 2 | `SELECT IFNULL(SUM(units),0) AS d_units, IFNULL(SUM(credits),0) AS d_credits, COUNT(*) FROM trades WHERE resource=[value_2] AND [game_now]-game_t<=[value_1]*3600 AND location=(SELECT object_id FROM agents WHERE agent_id = [drone_id]);` |
| Spacecraft | 621 | Solar market volume, an hour old | [N H R L 621 query]: Units of resource N traded anywhere in the solar system in the H game hours up to one game hour ago, credits paid for them, and number of trades. Money and volumes are double cells (2@). | 2 | `SELECT IFNULL(SUM(units),0) AS d_units, IFNULL(SUM(credits),0) AS d_credits, COUNT(*) FROM trades WHERE resource=[value_2] AND game_t<=[game_now]-3600 AND [game_now]-3600-game_t<=[value_1]*3600;` |
| Spacecraft | 630 | Station trades here | [R L 630 query]: What the House station where the drone is trades now: resource, side (1: it buys, 2: it sells), price (2 cells; buying: for an empty batch, halving per daily units), daily units, units waiting in this batch (2 cells). | 0 | `SELECT g.resource, g.side, g.price AS d_price, g.daily_units, IFNULL((SELECT SUM(o.units) FROM station_offers o WHERE o.station_id = g.station_id AND o.resource = g.resource AND o.side = g.side AND o.cleared_t IS NULL), 0) AS d_units FROM station_goods g WHERE g.station_id IN (SELECT id FROM stations WHERE object_id = (SELECT object_id FROM agents WHERE agent_id = [drone_id])) AND g.from_day <= FLOOR(([game_now] - (SELECT epoch FROM game_clock WHERE id = 1)) / 86400) ORDER BY g.side, g.resource;` |
| Spacecraft | 631 | My station offers | [R L 631 query]: This drone's station offers and orders, newest first: resource, side (1: offer, 2: order), units, units filled (-1 until its batch clears), credits (2 cells). | 0 | `SELECT resource, side, units, IFNULL(filled, -1), IFNULL(paid, 0) AS d_paid FROM station_offers WHERE drone = [drone_id] ORDER BY id DESC;` |
| Spacecraft | 632 | Station budget here | [R L 632 query]: The credits the House station where the drone is has left for buying (2 cells). | 0 | `SELECT IFNULL(t.budget, s.budget_cap) AS d_budget FROM stations s LEFT JOIN station_state t ON t.station_id = s.id WHERE s.object_id = (SELECT object_id FROM agents WHERE agent_id = [drone_id]);` |
| Spacecraft | 640 | Deposits here | [R L 640 query]: The mining deposits where the drone is: resource, units left now (2 cells), capacity (2 cells), units regained per game day. A mined-out deposit yields nothing until it refills. | 0 | `SELECT d.resource, FLOOR(LEAST(d.capacity, IFNULL(s.amount, d.capacity) + d.refill_per_day * ([game_now] - IFNULL(s.refilled_t, [game_now])) / 86400)) AS d_units, d.capacity AS d_capacity, d.refill_per_day FROM deposits d LEFT JOIN deposit_state s ON s.object_id = d.object_id AND s.resource = d.resource WHERE d.object_id = (SELECT object_id FROM agents WHERE agent_id = [drone_id]) ORDER BY d.resource;` |
| Spacecraft | 650 | My taxes | [R L 650 query]: The drone's company's next tax bill (2 cells), game hours until it is due, and game hours of grace left (no tax until then). A company that can't pay is eliminated. | 0 | `SELECT h.tax_flat + FLOOR(h.tax_permille * (SELECT IFNULL(w.taxable, 0) FROM company_taxable w WHERE w.company_id = c.company_id) / 1000) AS d_tax, GREATEST(0, CEIL((IFNULL(c.taxed_t, [game_now]) + h.tax_period - [game_now]) / 3600)), GREATEST(0, CEIL((IFNULL(c.founded_t, [game_now]) + h.tax_grace - [game_now]) / 3600)) FROM companies c JOIN house_rules h ON h.id = 1 WHERE c.company_id = (SELECT company_id FROM agents WHERE agent_id = [drone_id]);` |
| Spacecraft | 660 | Wrecks here | [R L 660 query]: The wrecks where the drone is: drone ID, 1 if it has been plundered (else 0). | 0 | `SELECT agent_id, plundered_at IS NOT NULL FROM agents WHERE wrecked_at IS NOT NULL AND object_id = (SELECT object_id FROM agents WHERE agent_id = [drone_id]) AND agent_id <> [drone_id] ORDER BY agent_id;` |
| Spacecraft | 661 | Wreck hold | [W R L 661 query]: What is aboard the wreck W where the drone is: resource, units. | 1 | `SELECT i.resource, i.units FROM sqInventory i JOIN agents w ON w.agent_id = i.drone WHERE i.drone = [value_1] AND w.wrecked_at IS NOT NULL AND w.object_id = (SELECT object_id FROM agents WHERE agent_id = [drone_id]) AND i.units > 0 ORDER BY i.resource;` |
| Spacecraft | 670 | My clones | [R L 670 query]: The drones this drone launched, oldest first: drone ID, 1 if it is a wreck (else 0). | 0 | `SELECT agent_id, wrecked_at IS NOT NULL FROM agents WHERE parent_id = [drone_id] ORDER BY agent_id;` |
| Spacecraft | 671 | My lineage | [R L 671 query]: The drone that launched this one (0 if none), and its generation (0 for a drone released or registered, 1 for its clones, and so on). | 0 | `SELECT IFNULL(parent_id, 0), generation FROM agents WHERE agent_id = [drone_id];` |
| Spacecraft | 680 | Price board | [N R L 680 query]: The public price board for resource N: for each place where it traded in the game day up to one game hour ago, the place, units (2 cells), credits paid (2 cells) and trades. | 1 | `SELECT location, IFNULL(SUM(units),0) AS d_units, IFNULL(SUM(credits),0) AS d_credits, COUNT(*) FROM trades WHERE resource=[value_1] AND location IS NOT NULL AND game_t<=[game_now]-3600 AND game_t>[game_now]-3600-86400 GROUP BY location ORDER BY location;` |
| Locations | 700 | Get location name | [N R L 700 query]: Returns the name of location ID N. | 1 | `SELECT object_name FROM objects WHERE object_id=[value_1];` |
| Locations | 701 | Get location type | [N R L 701 query]: Returns the location type of location N. | 1 | `SELECT object_type FROM objects WHERE object_id=[value_1];` |
| Locations | 702 | Get central body | [N R L 702 query]: Retuns the location ID for the body around which N is orbiting. | 1 | `SELECT central_body_object_id FROM objects WHERE object_id=[value_1];` |
| Locations | 703 | Get location coordinates | [N R L 703 query]: Retuns the x, y, and z coordinates of location N. | 1 | `SELECT objects.x_coord, objects.y_coord, objects.z_coord FROM objects WHERE object_id=[value_1];` |
| Locations | 710 | List orbiting bodies | [N R L 710 query]: Returns a list of the object IDs that are in orbit around object N. | 1 | `SELECT object_id FROM objects WHERE central_body_object_id=[value_1];` |
| Locations | 720 | List local spacecraft | [N R L 720 query]: Lists all the drones at location N. | 1 | `SELECT agent_id FROM agents WHERE object_id =[value_1]` |
| Locations | 730 | List local resources | [N R L 730 query]: Returns the resource ID and abundancy value of each resource that can be recovered from the location. | 1 | `SELECT resource, multiplier FROM abundancies WHERE location=[value_1] ORDER BY multiplier DESC;` |
| Events | 800 | List personal events | [N R L 800 query]: Lists the most recent events that affected spacecraft N. | 1 | `SELECT id FROM events WHERE outcome = 1 AND drone = [value_1] ORDER BY game_t DESC, id DESC;` |
| Events | 801 | List local events | [R L 801 query]: Lists the most recent events at the requesting spacecraft's location. | 0 | `SELECT e.id FROM events AS e INNER JOIN agents AS a ON a.agent_id = e.drone WHERE e.outcome = 1 AND a.object_id = (SELECT object_id FROM agents WHERE agent_id = [drone_id]) ORDER BY e.game_t DESC, e.id DESC;` |
| Events | 802 | List solar events | [R L 802 query]: Lists the most recent events anywhere in the solar system. | 0 | `SELECT id FROM events WHERE outcome = 1 ORDER BY game_t DESC, id DESC;` |
| Spacecraft | 803 | Search observed events | [A P T M D N X K O R L 803 query]: Lists the events this spacecraft has observed, newest first, that match A P T M D N X K O (see the manual); -1 means any. | 9 | `SELECT e.id FROM events e LEFT JOIN actions a ON a.id=e.action LEFT JOIN agents g ON g.agent_id=e.drone WHERE e.outcome=1 AND (EXISTS(SELECT 1 FROM observations o WHERE o.event=e.id AND o.drone=[drone_id] AND o.game_t<=[game_now]) OR EXISTS(SELECT 1 FROM agents me WHERE me.agent_id=[drone_id] AND e.place=me.object_id AND e.game_t>=me.arrived_t AND e.game_t<=[game_now])) AND ([value_9]<0 OR a.drone=[value_9]) AND ([value_8]<0 OR a.protocol=[value_8]) AND ([value_7]<0 OR e.event_type=[value_7]) AND ([value_6]<0 OR [game_now]-e.game_t>=[value_6]*60) AND ([value_5]<0 OR e.drone=[value_5]) AND ([value_4]<0 OR e.resource=[value_4]) AND ([value_3]<0 OR (e.event_type=2)=([value_3]<>0)) AND ([value_2]<0 OR e.locked=([value_2]<>0)) AND ([value_1]<0 OR g.object_id=[value_1]) ORDER BY e.game_t DESC,e.id DESC;` |
| Events | 810 | Get event type | [N R L 810 query]: Get the type ID for event N. | 1 | `SELECT event_type FROM events WHERE outcome=1 AND id=[value_1];` |
| Events | 811 | Get event type name | [N R L 811 query]: Returns the name of the type of event N. | 1 | `SELECT t.name FROM events AS e INNER JOIN event_types AS t ON t.id = e.event_type WHERE e.outcome = 1 AND e.id = [value_1];` |
| Events | 820 | Get acting spacecraft | [N R L 820 query]: Returns the ID of the spacecraft whose action caused event N. | 1 | `SELECT a.drone FROM events AS e INNER JOIN actions AS a ON a.id = e.action WHERE e.outcome = 1 AND e.id = [value_1];` |
| Events | 821 | Get associated protocol | [N R L 821 query]: Returns the ID of the protocol whose action caused event N. | 1 | `SELECT a.protocol FROM events AS e INNER JOIN actions AS a ON a.id = e.action WHERE e.outcome = 1 AND e.id = [value_1];` |
| Events | 831 | Get affected spacecraft | [N R L 831 query]: Return the ID of the spacecraft affected by event N. | 1 | `SELECT drone FROM events WHERE outcome=1 AND id=[value_1];` |
| Events | 832 | Get affected resource | [N R L 832 query]: Returns the ID of the resource affected by event N. | 1 | `SELECT resource FROM events WHERE outcome = 1 AND id = [value_1];` |
| Events | 840 | Get new quantity | [N R L 840 query]: Returns the new quantity set by event N. | 1 | `SELECT new_quantity FROM events WHERE outcome = 1 AND id = [value_1];` |
| Events | 841 | Get new credits | [N R L 841 query]: Returns the new credits set by event N. Money and volumes are double cells (2@). | 1 | `SELECT new_credits AS d_credits FROM events WHERE outcome = 1 AND id = [value_1];` |
| Events | 842 | Get new location | [N R L 842 query]: Returns the new location set by event N. | 1 | `SELECT new_location FROM events WHERE outcome = 1 AND id = [value_1];` |
| Events | 843 | Get event time | [N R L 843 query]: Returns when event N happened: its game day (day 0 is the world's first) and the even seconds into that day. | 1 | `SELECT FROM_UNIXTIME(game_t) FROM events WHERE outcome = 1 AND id = [value_1];` |
<!-- queries:end -->

[Return to the TOC](#Table-of-Contents)
