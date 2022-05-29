# Ex Machinis Game Design Manual

_Ex Machinis_ is an online multiplayer space simulation designed to expose players to programming and robotics. In the game, which takes place against the backdrop of the real-time solar system, players program remotely piloted spacecraft to mine, manufacture, and trade goods and services.

The game uses augmented reality to project a parallel universe upon our own. In the game's re-imagined post-war history, world leaders embraced space exploration and colonization as a means of protecting mankind form the global threats that emerged in the wake of the second world war.

This wiki is used to organize the project, which began in August 2017.  It contains multiple sections which describe the game's design and function in great detail. These are the blueprints we follow when coding and creating content.

This document lays out the plans for developing Ex Machinis, an online space simulation, which promotes the acquisition of programming skills.

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
  * [Information travels at the speed of light](#Information-travels-at-the-speed-of-light)
  * [All ingame events result from actions taken by drones](#All-ingame-events-result-from-actions-taken-by-drones)
  * [Players use FORTH to control their drones](#Players-use-FORTH-to-control-their-drones)
  * [Players advance by developing more complex codes](#Players-advance-by-developing-more-complex-codes)
  * [The Game Engine cycles through player run virtual machines](#The-Game-Engine-cycles-through-player-run-virtual-machines)
  * [Equipment](#Equipment)
* [Players and Resources](#Players-and-Resources)
  * [Player and Drone Tables](#Player-and-Drone-Tables)
    * [Table-users](#Table-users)
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
* Realistic time scales.  Although advanced technologies are able to compress the time it takes for drones to travel long distances and complete industrial activities, it is still expected that many activities will span the course of hours and days.
* Communications limited to the speed of light. Communications with distant drones is not instantaneous.

[Return to the TOC](#Table-of-Contents)

### Functional Components
Ex Machinis has several functional components which operate independently to support gameplay.

#### Mail Handler
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

[Return to the TOC](#Table-of-Contents)

## General Mechanics

### All objects are defined by their orbits
Every natural and manmade object in the game will be in orbit around the sun, a planet, or a moon.  Static objects are bodies that are locked in a fixed orbit, which does not change during the game.  Drones are dynamic objects, which can move from one orbit to the next. The Physics Engine routinely updates the cartesian coordinates of static and dynamic objects based on their orbital parameters.

[Return to the TOC](#Table-of-Contents)

### Information travels at the speed of light
A drone will not know what is happening at a distance until the light or radio transmission actually reaches it.  This can take minutes to hours depending on the drone’s distance from the observed event. Ex Machinis will handle this phenomena via the tEvents and tObservations tables in the Central Database.

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
Since there will be a rather large number of drones running FORTH programs at any given time, the Game Engine (GE) will need to continuously and efficiently cycle through each of the virtual machines. The GE will do this by systematically inspecting each drone and considering whether it has an active program that needs to be attended to.  This will be apparent by Central Database fields, which indicate the last time the GE attended to a drone and the current state of the drone:
* Inactive: The drone is not currently running a FORTH routine.
* Active: The drone is in the middle of running and active FORTH routine.  When the GE encounters a drone in this state it will automatically upload the VM from the CD and continue processing the routine where it had left off.
* Passive: The drone is actively running a FORTH routine but has paused its operations (via the CatchEvent command) to await an event.  When the GE encounters a drone in this state it will only upload and process the VM if the drone just experienced an event as indicated by the Discovery Table.

The drone’s VM  and Input fields should contain all the data the GE needs to pickup and continue processing a drone’s code from where it last left off.  All the stack items and variables will already be encoded in the VM.  Furthermore, there will be a pointer in the VM indicating the last command that was processed by the drone.

The GE will process a set number of instructions before updating the VM in the Game Database and moving on to the next drone that requires attention.  The number of processed commands will be empirically set to optimize the overall performance of the GE.

If the GE encounters a command that requires it to perform an ingame activity (change orbits, mine ores, transfer cargos, etc…) it will compute times and resource requirements and log the necessary start and stop events.

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
* credits [INT(10)] - This is the number of credits that the player has in her bank account.

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

[Return to the TOC](#Table-of-Contents)

#### Table: hulls
Drones are defined by their hulls and the materials and equipment contained within them. Both cargo, in the form of consumable resources, and functional modules affect what the drone can do.  The different varieties of hulls and their hold capacities are described in the tHull table and specifically linked to each drone in the agents table.
* id [INT(2)] - Table index  Max 99.
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
* object_type [VARCHAR(16)] - This classifies the object as a star, planet, moon, asteroid, etc...
* gravitational_parameter [DOUBLE] (μ) - The gravitational parameter of the central body expressed in m3/s2.
* central_body_object_id [INT(11)] -  This is the ID of the object that is being orbited.
* mean_angular_motion [DECIMAL(13,9)]  (n) - This is the average angular motion expressed in degrees per day.  It can be derived from the gravitational parameter as described below.
* epoch [DATETIME] (t0) - The time at which the orbital parameters were defined.  This is typically J2000.
* eccentricity [DECIMAL(10,9)] (e) - Orbital eccentricity between 0 and 1.
* semimajor_axis [DOUBLE] (a) - Half of the major axis of the orbital ellipse expressed in meters.
* inclination [DECIMAL(12,9)]  (i) - Defines the angle in degrees between the orbital plane and the reference plane.
* ascending_node_longitude [DECIMAL(12,9)]  (Ω) - defines the angle in degrees between the reference direction and the upward crossing of the orbit on the reference plane (the ascending node).
* periapsis_argument [DECIMAL(12,9)]  (ω) - defines the angle in degrees between the ascending node and the periapsis.
* mean_anomaly [DECIMAL(12,9)]  (M0) - defines the position of the orbiting body along the trajectory at the epoch, measured in degrees from periapsis.
* computed_on [DATETIME] - This is the time at which the object’s cartesian coordinates were last computed by the Physics Engine.
* x_coord (DOUBLE) - This is the most recent x coordinate of the object in kilometers.
* y_coord (DOUBLE) - This is the most recent y coordinate of the object in kilometers.
* z_coord (DOUBLE) - This is the most recent z coordinate of the object in kilometers.
* The Inclination, Longitude of the Ascending Node, and Argument of Periapsis help orient the orbital plane in space (Figure 2) whereas the other parameters are used to locate the orbital body on the plane.


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

Players generally interact with their spacecraft via email.  Each spacecraft has its own email address and players use this address to send their drones meta-commands.  Meta-commands are always bracketed by greater than and less than signs (ie. “\<command\>”) to distinguish them from other email content, which is ignored by the drones. Most meta-commands enclose a single parameter, which is being sent to the spacecraft.  In this case, the meta-command would be rendered as “\<command\> parameter \</command\>.”  Spacecraft will only execute the first recognizable meta-command in an email.

Spacecraft recognize a few basic meta-commands, which allow players to execute FORTH scripts, reset errant code, and reconfigure user settings.  These commands are as follows:

* \<run\> parameter \</run\> tells the drone to execute the FORTH script contained within the parameter string.  This parameter can contain line breaks and other special characters.
* \<abort\> forces the drone to immediately stop any script that it is currently running and return control to the player.  The drone will return an email confirming that it has aborted the script.
* \<reset\> stops the currently running script and resets the drone’s virtual memory to its factory setting, erasing any code that the player may have entered.  The drone will return an email to confirm that it has successfully reset its memory.
* \<rename\> parameter \</rename\> changes the email address of the drone to parameter@exmachinis.com.  Consequently, the parameter must not contain characters that generate an invalid email address and cannot be in use by any other drone or player.  The drone will return an email confirming its name change.  It will also report any failure to change the name and the reason why the proposed name is unacceptable.
* \<rebrand\> parameter \</rebrand\> changes the name of the player’s company.  The chosen name must be unique and not exceed the maximum lengths prescribed by the SQL field. The drone will return an email confirming the company’s name change.  It will also report any failure to change the name and the reason why the proposed name is unacceptable.

[Return to the TOC](#Table-of-Contents)

## Protocols

Every physical action in Ex Machinis results from a drone executing a specific protocol. Protocols are procedures that a drone can perform provided they have the required resources, modules, and cargo space.  Most protocols consume and produce a certain amount of resources with the help of installed modules.  Protocols generally take a finite time to complete.  If any of the modules or reagents are missing or currently in use by another protocol, the protocol will not be begin.

[Return to the TOC](#Table-of-Contents)

### Performing a Protocol
Each protocol has a unique ID and is invoked from FORTH by placing the process multiplier and protocol ID on the top of the stack and calling the perform function.  Many protocols also have additional parameters, which must be set using values on the stack.

[Return to the TOC](#Table-of-Contents)

### Process Multiplier
The process multiplier is an integer, which indicates the number of consecutive times a particular protocol will be applied before stopping.  The multiplier will be applied to each of the resources that are consumed and produced by the protocol as well as the corresponding time intervals.  The only thing it will not affect is the number of modules that are required to perform the action.  The process multiplier is an optional variable that is only pulled from the stack if the process modifier flag is true in the protocols table

[Return to the TOC](#Table-of-Contents)

### Protocol ID
Each protocol has a unique numeric ID, which is used to invoke the specific action via the perform function.  This number is identical to the Protocol ID in the protocols table, described below. The protocols table and other action tables are used by the perform routine to determine the costs and outcomes of each protocol.

[Return to the TOC](#Table-of-Contents)

### Protocol Parameters
Protocols often require parameters to further define their operations. These parameters are pulled from the stack when the protocol is performed. The parameters are placed in a C++ array according to the order in which they were pulled from the stack. Sometimes the values in this array are modified by the Game Engine prior to use.  Other times they are not.  However, in most cases the array values are used in place of variable markers found in the action tables, which are described below.

[Return to the TOC](#Table-of-Contents)

### Returned Value
Every time the perform command is called, it returns a value to the stack, which indicates whether or not it succeeded in performing the action.  If the action was successfully initiated, it will place the Action ID (see below) on the stack.  If the drone was unable to initiate the action, it will return a negative value that indicates the source of the error.  The error codes are as follows:

* -1: Unknown protocol
* -2: Insufficient parameters
* -3: Invalid destination
* -4: Can’t install resource

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

[Return to the TOC](#Table-of-Contents)

##### Table: events
The dynamic events table is used to track what has and will happen in the game.  Most events represent either the beginning or end of a particular action and have specific effects on the drone and their environments.  In general, each resource_effects, market_effects, and location_effects entry will be converted to an event when the corresponding protocol is performed by the drone.

The fields in the events table can be grouped according to whether they describe the event, the affected object, or the effect.

The fields which describe the event itself are:
* id [INT(10)] - This is the ID of the current event.
* event_type [INT(2)] - This is the type of event.
* time (DATETIME) - This is the time at which the event occurs.
* action [INT(10)] - This is the action that caused the event.
* logged [BOOLEAN] - This field is true if the Observation Engine has already logged the event as an observation.
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
2. The perform function pulls the second value off the stack for use as a process multiplier. This determines how many times the process is performed in a row without stopping.  Many protocols have bulk modifiers, which reduce the amount of time it takes to perform repetitive actions.
3. Create a new actions table entry for the executed protocol.  Set the aborted field to false unless the action is aborted as a result of an error at some point during the following procedure or later in the game.
4. Next, use the protocol table’s parameter field to determine how many additional values need to be pulled from the stack and stored in a parameter array for future reference. Abort the protocol and return and error if there are not enough values on the stack.
5. Use the ProtocolID to determine whether a particular activity needs any special treatment.  For example, you may need to call a custom C++ routine to determine the travel time between two locations and correct the time parameter accordingly.  These routines will generally make situation-specific changes to some of the values stored in the parameter array.  They will be hard coded into the C++ perform routine.
6. When performing a multiplexed action, multiply all consumed and produced resources by the process multiplier.  Similarly multiply the time offsets for each of the events by the process multiplier and then by the bulk modifier to account for bulk process efficiencies.
7. Sequentially search for the Protocol ID in each of the effects tables. Apply variable parameters where indicated (fields with negative numbers). Generate a corresponding event table entry for each effect.

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

[Return to the TOC](#Table-of-Contents)

## The Event Engine

The Event Engine (EE) is a C++ application that runs continuously in the background and monitors the Events Table for events that have occurred but have not yet been processed (outcome = 0).  When it finds such an event, the EE makes sure the event can still take place (i.e. the required conditions and resources exist), converts changed quantities to total quantities, determines if and when other spacecraft will observe the event, and cleans up expired events.  

The Events Engine takes the following steps when evaluating events:

1. The EE scans the events table for any unprocessed events (outcome = 0) that occurred earlier than the current game time and are not associated with an aborted action.

2. Whenever the EE processes a harvest event (events.event_type = 1), the it multiplies the events.new_quantity value by the abundancies.multiplier value for the first entry in the abundancies table with the same drone location and resource values as indicated by the event.  If a matching entry is not found in the table, events.new_quantity is set to zero, indicating that the resource cannot be harvested from that location.

3. When it finds such an event, the EE will update the resource, credit, and cargo quantities so they represent total values rather than changes in the total value.  Important: This adjustment will NOT be performed on events associated with market actions.  Depending on the affected quantity, the EE will compute the totals as follows:
  * new_quantities. The EE will search for the last processed event with the same drone, resource, installed, and locked values (if such an event exists) and add that quantity to the new_quantities field of the current event.  If the resulting value is less than zero the event will not occur.
  * new_cargo.  The EE will search for the last processed event with the same drone and add that quantity to the new_cargo field of the current event.
  * new_credit.  The EE will search for the last processed event for any drone belonging to the owner of the current drone and add the quantity of the new_credit field of the current event.

4. Next, the EE will ensure that the conditions are sufficient for the event to take place.  It will do so by checking for the following errors:
  * The local field is set to true but the affected drone is not local. The outcome will be set to -1 if this error is detected.
  * The affected ship contains insufficient resources of the indicated state (identical resource, locked, and installed values) to accommodate a depletion event. This happens when the newly computed new_quantity field is less than zero. The outcome will be set to -2 if this error is detected.
  * There affected ship contains insufficient cargo space to accommodate the accumulation event. This happens when the recently computed new_cargo value is greater than the cargo capacity of the drone (found in hull table).  The outcome will be set to -3 if this error is detected.
  * The depletion event will violate the affected drone’s self imposed lower limit for the resource. This only applies to cases in which the acting and affected ships are different. The outcome will be set to -5 if this error is detected.
  * The accumulation event will violate the affected drone’s self imposed upper limit for the resource. This only applies to cases in which the acting and affected ships are different. The outcome will be set to -6 if this error is detected.
  * The Depletion Price for items being removed from the cargo bay of a non-allied ship is equal to or greater than the price the player has set on those items. The outcome will be set to -7 if this error is detected.
  * The Accumulation Price for items being added to the cargo bay of a non-allied ship is less than or equal to the price the player has set for those items. The outcome will be set to -8 if this error is detected.

5. If any of these errors are detected, the EE will update the event’s outcome with the indicated value and flag the corresponding action in the action table as aborted.

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

When the Event Engine adds or subtracts credits from a user's account, it does so by incrementing or decrementing the users.credits field by the indicated amount and creating a new events table entry to record this change:

- The user.credits field can go negative as a result of a transaction.
- Whem creating the corresponding events table entry, the Events Engine will set events.time to the current date/time, events.drone to the ID of the affected drone, events.action to the ID of the related action, events.logged to true, events outcome to 1, and events.event-type to 6 if credits are being added and 7 if credits are being removed. The Events Engine will also set events.new_credits to the new value in users.credits. All other values will be null.

### A Trade Example

If a player decides to sell iron ore from one of his drones, he will invoke a protocol, which pulls three values from the stack.  The first value is the Resource ID of the material he wants to sell, the second value is minimum amount of the resource he wants to maintain in the drone's cargo hold (ie. a quantity of iron ore that is NOT for sale), and the third value indicates the unit price that he is requiring for the ore.  The Game Engine uses this information to create a sell-type event, which sets minimums for quantity and cost on the indicated resource via the events.new_quantity and events.new_redits fields.  When the Event Engine processes this event, it simply marks it as complete (events.outcome=1). It doesn't need to make any checks on the availability of resources because no resources are being created or depleted.

When another player wants to buy iron ore he will search the database for sell events and discover that the drone described above is selling some amount of ore (ore for sale = total amount in cargo - minimum amount in sell event) for a certain price (indicated in the sell event's new_credits field).  Consequently, the buyer will send one of his drones to the location of the drone that's selling the resource and invoke a transfer protocol, which moves cargo from the hold of the selling drone to the hold of his own drone. This transfer protocol will use resource_effects table entries to deplete a specified amount of the ore from the selling drone's cargo and add an equivalent amount of the resource to its own cargo.  

When the Event Engine processes the resulting depletion event (removing the ore from the seller's cargo), it will ensure that the indicated amount of resources are available for sale. In other words, there must be enough resources in the cargo hold to accommodate the depletion without allowing the total quantity to drop below the level set by the sell order. If there are enough resources, the depletion event will occur and the Event Engine will create two new contemporaneous events to remove credits from the buyer and give them to the seller.  Otherwise, the event will be blocked and the other events associated with that action will be aborted.

Provided the depletion event occurs without a problem, a subsequent event associated with the cargo transfer action will add the depleted resources to the buyer's cargo hold if there is enough room in the hold. This will be handled like any other event which adds resources to the cargo hold.

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

* [value_1] will be replaced by the first query specific parameter that is pulled from the stack and rendered as a decimal integer. [value_2], [value_3], and [value_4] will do the same with the second, third, and fourth query-specific parameters that are pulled from the stack.
* [string_1] will be replaced by the string that is pointed to by the first query-specific parameter on the stack.  The ASCII string will be pulled from the drone’s memory location pointed to by the user supplied address. [string_2], [string_3], and [string_4] will do the same for the second, third, and fourth user-supplied values found on the stack.
* [drone_id] will be replaced by the integer ID of the drone that is performing the query.
* [time_delay] will be replaced by the distance in light minutes that the drone is from Earth.

[Return to the TOC](#Table-of-Contents)

#### The Query Algorithm
The query command provides a general mechanism for players to query portions of the Game Database from within the FORTH programming environment.  As mentioned above, the query command pulls three or more values off the stack, which are used to define the query and its output.  Whenever the FORTH VM executes a query command, the Game Engine performs the following steps:

1. The Game Engine begins by pulling the first value off of the stack and using it to look up the player-evoked query in the queries table via queries.id.  This entry will tell the Game Engine how many query-specific parameters to pull off the stack and what SQL script to run when executing the query.
2. Next, the Game Engine retrieves the Results Array Pointer from the stack. This pointer tells the Game Engine where to report the query results.  
3. The Game Engine will also pull a value off the stack that lists the maximum size of the Results Array in bytes. The Game Engine will not write to the Results Array beyond this point.
4. The Game Engine will likely also retrieve query-specific values from the stack. The number of values that are pulled from the stack is specified in queries.parameters.  If there aren’t enough values on the stack to complete the query, the Game Engine will place a -1 on the stack and end the query routine.
5. Once it has retrieved all the required parameters from the stack, the Game Engine replaces each of the variables in a copy of the queries.script string with the corresponding user and Game Engine supplied values as indicated in the preceding Query Variables section.
6. The Game Engine executes the resulting SQL query.  If the query call produces an error, the Game Engine places -2 on the stack and ends the FORTH-invoked query routine.  Otherwise, it places a 1 on the stack and parses the query results into the VM, starting at the location indicated by the pointer it pulled from the stack in Step 2.
7. When parsing the results, the Game Engine will step through each returned value and determine the data type (as defined by the specific SQL DB filed ).  Integers will be placed in memory as 16-bit values.  Text will be imported into the VM as strings equal in length to the size allocated for the text in the SQL database.  Times will be placed in memory as two 16-bit values.  The first value will count the number of days from 1 January 2000,  The second value will count the number of even seconds (every other second) since midnight. These data will be placed one after another in the VM until all the columns and rows have been processed or the Game Engine reaches the end of the Results Array as indicated by the value collected in step 3.

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

This appendix lists the specific in-game queries that are being proposed and developed for Ex Machinis.  These queries are organized in terms of the database structures that they support.

### Company Queries

| Category | Query ID | Name | Description | MySQL Script|
|:---------|:---------|:-----|:------------|:------------|
| Companies | 100 | Get company name | Returns the name of the company with the specified Company ID. | SELECT users.name FROM users WHERE (((users.user_id)=[value_1]));
| Companies | 101 | Get company credits | Returns the number of credits owned by the specified Company ID. | SELECT users.credits FROM users WHERE (((users.user_id)=[value_1]));
| Companies | 110 | List company drones | Lists the IDs of the spacecraft owned by company N. | SELECT agents.agent_id FROM agents WHERE (((agents.user_id)=[value_1])) ORDER BY agents.agent_id;
| Spacecraft | 200 | Get drone name | Returns the name of the spacecraft with the specified Spacecraft ID. | SELECT agents.name FROM agents WHERE (((agents.agent_id)=[value_1]));
| Spacecraft | 201 | Get spacecraft owner | Returns the Company ID of the company that owns the specified Spacecraft ID. | SELECT agents.user_id FROM agents WHERE (((agents.agent_id)=[value_1]));
| Spacecraft | 202 | Get drone location | Returns the Location ID of the spacified Spacecraft ID. | SELECT new_location FROM events WHERE event_type=3 AND drone=[value_1] AND outcome=1 ORDER BY timestamp DESC;
| Spacecraft | 203 | Get hull type | Returns the hull type ID for the indicated spacecraft. | SELECT hull_type FROM agents WHERE agent_id=[value_1];
| Spacecraft | 204 | Get cargo mass | Returns the total mass of the resources in the cargo hold of the specified spacecraft. | SELECT  o.new_cargo FROM (SELECT MAX(timestamp) AS maxtime FROM events WHERE drone = [value_1] AND outcome = 1 AND (event_type =1 OR event_type = 2) GROUP BY drone) AS f INNER JOIN events AS o ON o.timestamp = f.maxtime WHERE o.drone = [value_1];
| Spacecraft | 205 | Get my spacecraft ID | Returns the spacecraft ID of the current drone. | SELECT agent_id FROM agents WHERE agent_id  =  [drone_id];
| Spacecraft | 210 | List spacecraft inventory | Returns the resorce ID and unit qantity for each item in the specified drone | SELECT o.resource, o.new_quantity FROM (SELECT resource, MAX(timestamp) AS maxtime FROM events WHERE drone = [value_1] AND outcome = 1 AND (event_type =1 OR event_type = 2) GROUP BY drone, resource) AS f INNER JOIN events AS o ON o.timestamp = f.maxtime AND o.resource = f.resource WHERE o.drone = [value_1] ORDER BY o.new_quantity DESC;
| Hulls | 300 | Get hull name | Returns the hull name for the indicated hull type. | SELECT name FROM hulls WHERE id=[value_1];
|Hulls | 301 | Get cargo capacity | Returns the cargo capacity of the indicated hull type. | SELECT cargo_capacity FROM hulls WHERE id=[value_1];
| Resources | 400 | Get resource name | Returns the name of the indicated resource. | SELECT name FROM resources WHERE id=[value_1];
| Resources | 401 | Get resource mass | Returns the unit mass of the resource in kg. | SELECT mass FROM resources WHERE id=[value_1];
| Resources | 410 | List best extraction sites | Returns a list of the best extraction sites abundancies for the specified resource | SELECT location, multiplier FROM abundancies WHERE resource=[value_1] ORDER BY multiplier DESC;
| Protocols | 500	| Get protocol name | Returns the name of the specified protocol | SELECT name FROM protocols WHERE id = [value_1];
| Protocols | 501	| Get protocol duration	| Returns the duration in minutes of the specified protocol | SELECT MAX(time) FROM (SELECT time FROM resource_effects WHERE protocol = [value_1] UNION SELECT time FROM market_effects WHERE protocol = [value_1])  AS combined;
| Protocols | 510	| List consumed resources | Lists resources and their quantities that are consumed by the specified protocol | SELECT resource, quantity FROM resource_effects WHERE protocol=[value_1] AND deplete=1 AND locked=0 ORDER BY quantity DESC;
| Protocols | 511	| List produced resources | Lists resources and their quantities that are produced by the specified protocol |	SELECT resource, quantity FROM resource_effects WHERE protocol=[value_1] AND deplete=0 AND locked=0 ORDER BY quantity DESC;
| Protocols | 512	| List retained resources | Lists resources and their quantities that are used but not consumed by the specified protocol |	SELECT resource, quantity FROM resource_effects WHERE protocol=[value_1] AND deplete=0 AND locked=1 ORDER BY quantity DESC;
| Protocols | 520	| List consuming protocols	| Lists all the protocols that consume the specified resource | SELECT protocol, quantity FROM resource_effects WHERE resource = [value_1] AND deplete = 1 AND locked = 0 ORDER BY quantity DESC;
| Protocols | 521	| List producing protocols	| Lists all the protocols that produce the specified resource | SELECT protocol, quantity FROM resource_effects WHERE resource = [value_1] AND deplete = 0 AND locked = 0 ORDER BY quantity DESC;
| Protocols | 522	| List retaining protocols	| Lists all the protocols that use but don't consume the specified resource | SELECT protocol, quantity FROM resource_effects WHERE resource = [value_1] AND deplete = 0 AND locked = 1 ORDER BY quantity DESC;
| Markets | 600	| List local buy orders	| Return a list of best local buy orders along with price and quantity for the specified resource.
| Markets | 601	| List solar buy orders	| Return a list of best solar system wide buy orders along with price and quantity for the specified resource.
| Markets | 610	| List local sell orders	| Return a list of best local sell orders along with price and quantity for the specified resource.
| Markets | 611	| List solar sell orders	| Return a list of best solar system wide sell orders along with price and quantity for the specified resource.
| Locations | 700 | Get location name | Returns the name of the specified location. | SELECT object_name FROM objects WHERE object_id=[value_1];
| Locations | 701 | Get location type | Returns the type of the specified location. | SELECT object_type FROM objects WHERE object_id=[value_1];
| Locations | 702 | Get location central body | Returns the central body of the specified locaiton. | SELECT central_body_object_id FROM objects WHERE object_id=[value_1];
| Locations | 710 | List orbting bodies | Lists the location IDs of the objects orbiting the specified location. | SELECT object_id FROM objects WHERE central_body_object_id=[value_1];
| Locations | 720 | List local drones | Lists all the drones at the current location | SELECT agent_id FROM agents WHERE object_id =[value_1];
| Locations | 730 | List local resources | Lists the minable resources and their abundancies at the specified location. | SELECT resource, multiplier FROM abundancies WHERE location=[value_1] ORDER BY multiplier DESC;
| Events | 800	| List personal events	| Lists most recent events that affect the specified drone. | SELECT id FROM events WHERE outcome=1 AND drone=[value_1] ORDER BY timestamp DESC;
| Events | 801	| List recent events	| Lists most recent events. | SELECT id FROM events WHERE outcome=1 ORDER BY timestamp DESC;
| Events | 810	| Get event type | Returns the event type ID of the specified event | SELECT event_type FROM events WHERE outcome=1 AND id=[value_1];
| Events | 811	| Get event type name	|
| Events | 820	| Get acting spacecraft	|
| Events | 821	| Get associated protocol	|
| Events | 831	| Get affected spacecraft	| Returns ID of the spacecraft that was affected by the specified event | SELECT drone FROM events WHERE outcome=1 AND id=[value_1];
| Events | 832	| Get affected resource	| Returns the ID and quantity of the resource that was affected by the specified event | SELECT resource, new_quantity FROM events WHERE outcome=1 AND id=[value_1];
| Events | 840	| Get new credits	| Returns the new credit value that results from the specified event. | SELECT new_credits FROM events WHERE outcome=1 AND id=[value_1];
| Events | 841	| Get new location	| Returns the new location that results from the specified event. | SELECT new_location FROM events WHERE outcome=1 AND id=[value_1];
| Events | 842	| Get event time	|


[Return to the TOC](#Table-of-Contents)
