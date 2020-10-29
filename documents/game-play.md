# Ex Machinis Player's Manual

The Player’s Manual provides a detailed description of available game-play mechanics and strategies.  This manual will grow with the game.  As more features are added, they will be introduced below.  Currently, the manual focuses primarily on coding spacecraft with the fully developed FORTH interpreter.  However, as we develop the more physical components of Ex Machinis game play more details will be provided on navigation, resource extraction, refining, and production.  Thank you for you for [supporting us via Patreon](http://patreon.com/exmachinis) and please stay tuned for future developments!

## Table of Contents


- [Chapter 1: Getting Started](game-play.md#Chapter-1-Getting-Started)
  - [Your fortune awaits!](game-play.md#Your-fortune-awaits)
  - [Requesting your spacecraft](game-play.md#Requesting-your-spacecraft)
  - [Sending your first command](game-play.md#Sending-your-first-command)
  - [Renaming your spacecraft and your company](game-play.md#Renaming-your-spacecraft-and-your-company)
- [Chapter 2: Stacking Numbers](game-play.md#)
  - [Doing Simple Math](game-play.md#)
  - [Managing the stack](game-play.md#)
- [Chapter 3: Working with Resources](game-play.md#)
  - [Distinguishing resource sizes and technology levels](game-play.md#)
  - [Organizing resources by Resource ID](game-play.md#)
  - [Browsing the Resource Catalog](game-play.md#)
- [Chapter 4: Issuing Commands](game-play.md#)
  - [Traveling between locations](game-play.md#)
  - [Understanding how protocols are organized and numbered](game-play.md#)
  - [Extracting minerals](game-play.md#)
  - [Reprocessing minerals](game-play.md#)
  - [Synthesizing complex materials](game-play.md#)
  - [Producing more complex spacecraft components](game-play.md#)
- [Chapter 5: Building A Vocabulary](game-play.md#)
  - [Creating your own words](game-play.md#)
  - [Commenting on your code](game-play.md#)
  - [Sending greetings](game-play.md#)
- [Chapter 6: Going to Market](game-play.md#)
  - [Placing and adjusting market orders](game-play.md#)
    - [Posting buy orders](game-play.md#)
    - [Posting sell orders](game-play.md#)
    - [Adjusting your orders](game-play.md#)
  - [Transferring resources between drones](game-play.md#)
- [Chapter 7: Stringing Variables](game-play.md#)
  - [Using variables when programming your drones](game-play.md#)
  - [What’s really happening with variables?](game-play.md#)
  - [Creating and Accessing Arrays](game-play.md#)
  - [Working with strings](game-play.md#)
  - [Different ways to command your spacecraft](game-play.md#)
    - [Aborting your script](game-play.md#)
    - [Resetting your machine](game-play.md#)
- [Chapter 8: Asking Questions](game-play.md#)
- [Chapter 9: Making Decisions](game-play.md#)
  - [Finding the truth in numbers](game-play.md#)
  - [Experimenting with logical operators](game-play.md#)
- [Appendix A: FORTH Error Codes](game-play.md#)

## Chapter 1: Getting Started

### Your fortune awaits!

Ex Machinis is a multiplayer space game, which takes place in a parallel universe. Within this altered reality, space technologies have rapidly advanced since the 1950’s to the point where extensive interplanetary industries exist to produce materials that drive the human economy back on Earth. The vast majority of these spacecraft are unmanned vehicles, which are assembled in space and are controlled terrestrial barons.

As part of the international agreement that drove the colonization of space at the end of the second world war, citizens of the signitor nations are guaranteed access to basic spacecraft which can be used to build profitable businesses.  This democratization of space has been the biggest driver behind the rapid growth of extraterrestrial industries and technologies.

It is your birthright to take control of your own remotely piloted spacecraft and build a thriving stellar industry.  But this can only be accomplished by programming your spacecraft to efficiently mine, manufacture, and trade materials on the interplanetary economy.

[Return to TOC](game-play.md#Table-of-Contents)

### Requesting your spacecraft

As an immersive multiplayer space simulation, there’s no logging into or out of the game.  Your assets are always in play.  To communicate with your spacecraft, simply send them emails and they will respond in real time.  Keep in mind that it will take longer for transmissions to reach spacecraft on the outer edge of the solar system.

To start playing, you need to email the Registrar ([registrar@exmachinis.com](mailto:registrar@exmachinis.com)) with the word “register” in the subject line.  You’ll receive a response from the registrar listing the email addresses of three spacecraft that are under your control.  These spacecraft will only respond to communications from the email address you used to contact the registrar. 

Also, please be assured that we’ll only use your email to send in-game communications from your spacecraft or an occasional newsletter summarizing updates to the game.  Furthermore, you’ll only receive emails from your spacecraft in response to your own communications and you can unsubscribe from the newsletter at any time.

[Return to TOC](game-play.md#Table-of-Contents)

### Sending your first command

Most of the game is played via direct email communications with your spacecraft. When one of your ships receives an email from you it will interpret everything between the `&lt;run>` and `&lt;/run>` brackets as code and respond to you accordingly via email.  

For example, try sending the following text  to one of your spacecraft and see what happens. Just make sure you retain the spaces between the numbers, the plus sign, and the period, which instructs the spacecraft to report the result.


```
<run> 2 3 + . </run>
```


You'll receive an email from your spacecraft that reports the result of adding two and three together.

[Return to TOC](game-play.md#Table-of-Contents)

### Renaming your spacecraft and your company

You’ll notice that each of your spacecraft has a rather generic and unremarkable email address like [SN523854@exmachinis.com](mailto:SN0230854@exmachinis.com).  You’ll probably want to give it a more memorable name like “hero” since you’ll be interacting with it on a regular basis.  To rename your spacecraft, simply send the following text in an email to your drone:


```
<rename>hero</rename>
```


You’ll actually receive an email from [hero@exmachinis.com](mailto:hero@exmachinis.com) confirming the name change.  You can now send your instructions to this email address instead of the original!

There is no limit to the number of times you change your drone’s email address. The only requirements are that you pick a name that doesn’t contain any illegal characters and isn’t already in use by someone else.  If your chosen name is not allowed, the drone will let you know via its original email address.

You can also change your company name at anytime by emailing a rebrand instruction to one of your drones:


```
<rebrand>Avalon</rebrand>
```


This will immediately change the name of your company in the company directory ([http://exmachinis.com/companies](http://exmachinis.com/companies?utm_source=patreon&utm_campaign=pm)).

[Return to TOC](game-play.md#Table-of-Contents)

## Chapter 2: Stacking Numbers

FORTH is an amazingly simple but highly flexible programming language that grows out of a few core interpreter functions.  In fact, the version of FORTH, which is currently installed in each of your spacecraft, is described in around 200 lines of game-side C code. 

This simple architecture has encouraged many FORTH loyalists to develop their own interpreters and led others to muse that a true FORTH master is one who builds his own interpreter from scratch. 

Consequently, FORTH will give advanced Ex Machinis players a tremendous amount of flexibility in developing a programming environment that works for them. However, it also means that beginners can start the game with a more standard dictionary of intuitive FORTH routines, which facilitate their entry into the game and it's dynamic programming environment.

This chapter is intended to give new users a basic understanding of FORTH so that they can use it to remotely pilot their spacecraft.

[Return to TOC](game-play.md#Table-of-Contents)

### Doing Simple Math

You may have already noticed that FORTH has a strange way of doing math. In FORTH, the numbers come first followed by the operator (`2 3 +`).  This is because FORTH relies on a data stack to transfer values to and from functions.  

For example, when you  enter "2” and “3” seperatred by a space, FORTH places a 2 and then a 3 on the data stack. You can visualize this as a stack of dishes in which the number 2 plate is laid down first and the number 3 plate is placed on top of it. Consequently, when objects are removed from the stack the topmost value is removed first (3) and then the next value (2).



<p id="gdcalert3" ><span style="color: red; font-weight: bold">>>>>>  gd2md-html alert: inline image link here (to images/image1.png). Store image on your image server and adjust path/filename/extension if necessary. </span><br>(<a href="#">Back to top</a>)(<a href="#gdcalert4">Next alert</a>)<br><span style="color: red; font-weight: bold">>>>>> </span></p>


![alt_text](images/image1.png "image_tooltip")


When you call a FORTH function, like addition (`+`), it removes the top two values from the stack (2 and 3), adds them together, and returns the result (5) to the stack.

Similarly, calling the print function (`.`), pulls a value off the stack (5) and adds it to the output stream, which is returned to you in an email as "5".

All FORTH functions behave the same way, by removing and adding values to the stack.  This is why FORTH is a stack-based language.  It’s also why FORTH is a very efficient and intuitive programming language.

Understanding stacks is the key to learning FORTH and programming kick-ass spacecraft in Ex Machinis.

[Return to TOC](game-play.md#Table-of-Contents)

### Managing the stack

In FORTH, program elements share data by adding and removing values from this stack in a last-on-first-off manner. For example, when I email the following code to one of my spacecraft, it adds the space-delimited values to the stack so that 1 is on the bottom of the stack and 4 is on top. 


```
<run>
	1 2 3 4
</run>
```


If I were to follow this with a call to the addition function (`+`),  it would remove the 4 and 3 from the top of the stack and place a 7 on the stack.  The stack from bottom to top would end up looking like this:  `1 2 7`

You may not always want to deal with values in the order they were placed on the stack. Therefore, a number of commands exist that allow you to directly manipulate the order and contents of the stack.

Try applying each of the following commands to manipulate the contents of the data stack in one of your spacecraft:


<table>
  <tr>
   <td><strong>Word</strong>
   </td>
   <td><strong>Action</strong>
   </td>
   <td><strong>Example *</strong>
   </td>
  </tr>
  <tr>
   <td><strong><code>drop</code></strong>
   </td>
   <td>Discards the top most item on the stack.
   </td>
   <td><code>0 1 2 3 drop . . . </code>
   </td>
  </tr>
  <tr>
   <td><strong><code>nip</code></strong>
   </td>
   <td>Discards the second item on the stack.
   </td>
   <td><code>0 1 2 3 nip . . .</code>
   </td>
  </tr>
  <tr>
   <td><strong><code>dup</code></strong>
   </td>
   <td>Copies the top most item on the stack.
   </td>
   <td><code>0 1 2 3 dup . . . </code>
   </td>
  </tr>
  <tr>
   <td><strong><code>over</code></strong>
   </td>
   <td>Copies the second item on the stack the stack.
   </td>
   <td><code>0 1 2 3 over . . .</code>
   </td>
  </tr>
  <tr>
   <td><strong><code>swap</code></strong>
   </td>
   <td>Switches to order of the top two items on the stack.
   </td>
   <td><code>0 1 2 3 swap . . .</code>
   </td>
  </tr>
  <tr>
   <td colspan="3" >* The three dots in each of these examples instruct the interpreter to print the first three items on the stack. It’s important that you place a space between each of the periods and the word that precedes them.
   </td>
  </tr>
</table>


When trying the above examples, remember that FORTH words are case sensitive and it is important that you include spaces between the numbers, words, and periods.  It’s also important that you include all FORTH scripts between `&lt;run>` and `&lt;/run>`.  Otherwise the interpreter won’t process the code.

[Return to TOC](game-play.md#Table-of-Contents)

## Chapter 3: Working with Resources

In Ex Machinis, spacecraft use resources to mine, produce, and sell other resources.  In fact, it’s the player-driven supply and demand for these materials that powers the in-game economy.

Examples of resources include minerals, chemicals, alloys, fasteners, rods, plates, gears, pulleys, motors, computers, mining bots, weapons systems, and spacecraft hulls.  

Like most things in Ex Machinis, each resource has been assigned a unique ID, which is used by spacecraft to access and manipulate the item.  Each resource also has a unit mass, which determines how much can be stored in a spacecraft’s cargo hold.

[Return to TOC](game-play.md#Table-of-Contents)

### Distinguishing resource sizes and technology levels

Manufactured resources also have an associated size and technology level.  These are indicated by a number/letter combination (such as A1, A2, B1, etc..), which is generally affixed to the resource name. The letter ascends with the relative size of the resource while increasing numbers indicate more technologically advanced versions of the resource. For example, Computer (A1), represents a small, technologically primitive version of a computer, while Computer (C3) is a large, highly sophisticated, supercomputer. Mined resources, which don’t have a size or technology associated with them, are annotated with the letter N, to indicate that they are natural materials. 


<table>
  <tr>
   <td rowspan="3" ><strong>Size</strong>
   </td>
   <td><strong>C1</strong>
   </td>
   <td><strong>C2</strong>
   </td>
   <td><strong>C3</strong>
   </td>
  </tr>
  <tr>
   <td><strong>B1</strong>
   </td>
   <td><strong>B2</strong>
   </td>
   <td><strong>B3</strong>
   </td>
  </tr>
  <tr>
   <td><strong>A1</strong>
   </td>
   <td><strong>A2</strong>
   </td>
   <td><strong>A3</strong>
   </td>
  </tr>
  <tr>
   <td>
   </td>
   <td colspan="3" ><strong>Technology</strong>
   </td>
  </tr>
</table>


**Size and Technology Table**

[Return to TOC](game-play.md#Table-of-Contents)

### Organizing resources by Resource ID

Resource IDs have been assigned in a manner that helps to organize and categorize the resources.

The **thousands **digit is used to divide the resources into the broad categories of minerals (1000), materials (2000), parts (3000), systems (4000), modules (5000), and spacecraft hulls (6000).


<table>
  <tr>
   <td><strong>Resource ID</strong>
   </td>
   <td><strong>Resource Category</strong>
   </td>
  </tr>
  <tr>
   <td>1000-1999
   </td>
   <td>Minerals
   </td>
  </tr>
  <tr>
   <td>2000-2999
   </td>
   <td>Materials
   </td>
  </tr>
  <tr>
   <td>3000-3999
   </td>
   <td>Parts
   </td>
  </tr>
  <tr>
   <td>4000-4999
   </td>
   <td>Systems
   </td>
  </tr>
  <tr>
   <td>5000-5999
   </td>
   <td>Modules
   </td>
  </tr>
  <tr>
   <td>6000-6999
   </td>
   <td>Hulls
   </td>
  </tr>
</table>


The **hundreds **digit further groups resources into subcategories. For example, systems are divided into mechanical (100), electromagnetic (200), hydraulic (300), chemical (400), and nuclear (500) functions.

The **tens **digit is unique for each resource within a subcategory.

The **ones **value is used to distinguish between the size and tech level of a particular resource. In general, 1-3 will be reserved for A-sized resources of each tech level, 4-5 will be assigned to B-sized resources, and C sizes will be encoded by IDs whose one’s digits range from 7-9.

[Return to TOC](game-play.md#Table-of-Contents)

### Browsing the Resource Catalog

You can browse and search the available resources online by going to the Resource Catalog ([http://exmachinis.com/resources](http://exmachinis.com/resources?utm_source=patreon&utm_campaign=pm)).  The catalog provides a filterable list of all the resources in the game along with their unique ID, a brief description of the item, and a unit mass in kilograms.  Clicking on a particular resource will take you to a details page, which lists all of the item’s active buy or sell orders.

[Return to TOC](game-play.md#Table-of-Contents)

## Chapter 4: Issuing Commands


### Traveling between locations

Every physical action your spacecraft takes will result from calls to predefined protocols. These protocols allow your drones to do things like mine resources, manufacture products, and travel between locations. In many cases, protocols use, deplete, and produce resources located in your ship's hold.

You can find a current list of protocols on the website ([http://exmachinis.com/protocols](http://exmachinis.com/protocols?utm_source=patreon&utm_campaign=pm)). Right now, your choice of protocols is very limited. That's because we've just created a few simple protocols to test game mechanics. However, as the game matures, the number and complexity of these actions will increase dramatically.

Protocols are invoked by sending your drone a FORTH script that calls upon the `perform `function. This function pulls one or more parameters from the stack. These parameters provide the ID for the protocol you want to perform and any other parameters that are required by the protocol.

For example, if you wanted to move your spacecraft (Protocol ID = 5) to Saturn (Object ID = 6) you would send your spacecraft the following instruction:


```
<run>
  6 5 perform
</run>
```


Give it a try and see what happens. You'll notice that your drones always report their current location at the top of every email they send you. This location will now indicate Saturn.

You can use this command to go elsewhere in the solar system as well. All you need is the Location ID for your destination. This can be found in the Space Objects table ([http://exmachinis.com/locations](http://exmachinis.com/locations)).

[Return to TOC](game-play.md#Table-of-Contents)

### Understanding how protocols are organized and numbered

Protocols are algorithms that spacecraft follow when performing a particular action.  Therefore, most outcomes have at least one protocol associated with them and many outcomes have several related protocols, each associated with a different technology or approach.

Protocols are generally numbered based on the ID of the primary resource they produce, process, or use. 

The Protocol IDs of processes that produce a particular resource are derived by adding 10000, 30000, or 50000 to the Resource ID of the manufactured item.  For example, the protocol that mines wolfite (Resource ID 1150) from the surface of a moon has a Protocol ID of 11150 (1150 + 10000) because it produces wolfite as a result of the process.

Similarly, the Protocol IDs of procedures that break down a resource into its components are obtained by adding 20000, 40000, or 60000 to the Resource ID of the reprocessed material. Consequently, the protocol that breaks down wolfite to its constituent elements will be assigned a Protocol ID of 21150.

Finally, protocols that simply used a particular resource to produce a certain result, have Protocol IDs that are identical to the Resource IDs of the leveraged resource.  A good example of this would be a transport protocol (Protocol ID 5011) that uses a shuttle (Resource ID 5011) to move resources between spacecraft.

Of course, some of these protocols, like the transport option, don’t exist yet.  However, it’s easy to compute their IDs because we know what they do or how they will function.  These conventions are just as convenient for players and drones that want to quickly identify or derive the protocol required to complete a particular task.

[Return to TOC](game-play.md#Table-of-Contents)

### Extracting minerals

Every production process begins with raw materials, which are available from planets, moons, comets, and asteroids.  These resources are generally extracted from orbital bodies in the form of minerals, which contain many of the different elements and compounds required for making construction materials.  

If you call up a particular location online, you’ll be able to see what minerals are available at that location.  For example, Phobos ([http://exmachinis.com/locations/11](http://exmachinis.com/locations/11)) contains kunspar, yalspar, vladnium, and other minerals.  Each of these minerals is accompanied by a value, which indicates the relative abundance of the resource at that location.  The higher the number, the more units you’ll recover from a single mining run.

To mine a particular mineral, send your drone to a location that contains the mineral and perform the protocol associated with mining the resource.  As indicated in the previous section the desired protocol ID will be the resource ID plus 10000. The following script sends a spacecraft to Mars (Location ID 4) to mine magmite (Resource ID 1000):


```
<run> 
  4 5 perform   \ Travel to Mars.
  11000 perform \ Mine magmite from the planet's surface.
</run>
```


[Return to TOC](game-play.md#Table-of-Contents)

### Reprocessing minerals

Minerals can be reprocessed to extract the purified metallic, organic, and inorganic components that are required for the manufacturing process.  Since reprocessing essentially breaks down the minerals into their constituent parts, the IDs for protocols associated with reprocessing minerals are derived by adding 20000 to the mineral’s resource ID.  For example, protocol 21000 produces 2 units A1 inorganics, 1 unit A1 metals, and 1 unit A1 organics from 20 units magmite.  

Provided you have enough magmite onboard, this is easily accomplished by sending the command to your drone:


```
<run>
  21000 perform \ Refine magmite into valuable metals and organics.
</run>
```

[Return to TOC](game-play.md#Table-of-Contents)


### Synthesizing complex materials

Of course, advanced technologies are often made from more complex materials like alloys and polymers.  So, there is a whole set of protocols that are designed to build increasingly advanced substances from the materials you pull out of mined minerals.

Since these are synthetic processes, the associated protocol IDs are derived by adding 10000 to the resource ID of the material that you are planning to manufacture.    Take for example, the protocol that produces 1 unit of simple A1 polymer (resource ID 2051) from 1 unit A1 inorganics and 1 unit A1 organics.  As a synthetic process, this activity is invoked by calling upon protocol ID 12051 (10000 + 2051):


```
<run>
  12051 perform \ Synthesize A1 inorganics.
</run>
```

[Return to TOC](game-play.md#Table-of-Contents)


### Producing more complex spacecraft components

In a similar manner, you can use protocols to synthesize parts from materials, systems from parts, and modules and hulls from systems.  In each case, the process is invoked by calling upon your drone to perform a protocol whose ID is derived by adding ten thousand to the resource ID of the resource that you’re trying to produce. You can find a complete list of these protocols, as well as the resources that are required for the synthetic processes, on the online [protocols page](https://exmachinis.com/protocols).

[Return to TOC](game-play.md#Table-of-Contents)

## Chapter 5: Building A Vocabulary


### Creating your own words

FORTH is a very simple language.  Most of the heavy lifting is done using the data stack and a dictionary of user-defined functions.  

Every time you send your spacecraft instructions, enclosed between the run brackets, the spacecraft's on-board interpreter will try to parse your text into words or numbers.

The interpreter will first look for a string of space delimited characters in the onboard dictionary.  If the characters represent a previously defined word, the interpreter will execute the corresponding function. If not, it will attempt to interpret the string as a value and place it on the stack. 

For example, the arithmetic operators (`+, -, *, /`) represented words, which the interpreter can find in the dictionary and execute to perform the proscribed computation. 

However, FORTH makes it very easy to define your own words on the fly for use in scripts and higher level definitions.  New words are defined by simply enclosing the word and the corresponding script between a colon (`:`) and a semicolon `(;`).

For example, if you find that you are frequently adding five to a number on the stack you can create the word, `AddFive`, to handle this operation in the future.   You would define `AddFive` by sending the following script to your spacecraft:


```
<run>
  : AddFive 5 + ;
</run>
```


Now, anytime you want your spacecraft to add five to the number on the stack you can use your new word instead of `5 +`:


```
<run>
  4 AddFive .
</run>
```


This script will cause your spacecraft to return the same result ("9") as the following script:


```
<run>
  4 5 + .
</run>
```


In this example, the word `AddFive `really doesn't offer much advantage over simply typing `5 +`.  However, you can easily imagine defining words that perform more complex functions that you wouldn't want to retype every time.

Give this a try with your own spacecraft!  I'm sure you can come up with more useful definitions than the one provided here. 

[Return to TOC](game-play.md#Table-of-Contents)

### Commenting on your code

As with most programming languages, FORTH offers for some convenient mechanisms for commenting on  your code.  These techniques can be very handy in reminding yourself and others of the intentent and function of your definitions. There are two primary ways to add comments to your script that will be ignored by the interpreter:



*   When you start a line with a backslash (`\`) the interpreter will ignore everything else on that line.
*   The interpreter will also ignore any text enclosed between parentheses, regardless of where they occur in the line or whether they enclose multiple lines. 

Remember that the interpreter uses white space to deliminate words.  Therefore, backslashes and parentheses will only be recognized as such by the interpreter if there are spaces between them and other characters.

[Return to TOC](game-play.md#Table-of-Contents)

### Sending greetings

Having your spacecraft output text in an email to you is actually quite easy to do and obviously very useful. There are a couple ground rules though:  First, this technique can only be called from within a word definition (ie. function).  Second, it’s critical that white space separates your quotes from the text that is contained between them.

With that in mind, the following definition creates a word that, when called, instructs the drone to print a specific string in its email response to you.

Start by emailed the following text to one of your spacecraft:


```
<run>
  : helloworld ." Greetings Earthlings! " ;
</run>
```


The spacecraft will respond to this email with a simple "`ok`" to acknowledge that it has received the new definition and incorporated it into its on-board dictionary.  Remember, the spaces in the above example are very important since they allow the interpreter to delineate words and numbers.

Next, envoke your new word by sending the following email to your spacecraft:


```
<run> helloworld </run>
```


Sending this script to your drone will result in a heartfelt greeting from your favorite spacecraft!  I guess there's no surprises there.  But this will obviously be a useful tool when having your ships provide intelligible status reports.

The important components of this command are the dot-quote (`."`), which instructs the craft to print the text that follows and the end-quote (`"`), which ends the printed string.  Just remember that because the FORTH interpreter views the dot and end quotes as distinct words, they must be separated from everything around them by a white-space.

[Return to TOC](game-play.md#Table-of-Contents)

## Chapter 6: Going to Market

Ex Machinis markets work based on a two-part system.  First, trade begins when individual drones post public bids to buy or sell materials from their cargo holds at fixed prices.  Then, these orders are fulfilled when other drones respond to these ads by physically transferring the materials between their holds in exchange for credits. The market system ensures that amounts of resources and credits that change hands under these circumstances conform to the terms of an open market bid.

[Return to TOC](game-play.md#Table-of-Contents)

### Placing and adjusting market orders

Any spacecraft can place an order to buy or sell a resource at a set price by performing a buy or sell protocol.  Each of these protocols accepts three parameters, in addition to the protocol ID, that indicate the resource that is being bought or sold, the proposed unit price of the materials,  and an upper or lower bound on the number of units being traded.

[Return to TOC](game-play.md#Table-of-Contents)

#### Posting buy orders

When posting a buy order, the programmer begins by placing an integer on the stack that represents the per-unit price in credits that he is willing to pay for a particular resource.  The next value to go on the stack is the maximum number of units that the player wants to have on hand. The order will be paused as soon as the amount of this resource in the drone’s cargo bay reaches this level.. The third value to go on the stack is the ID of the resource being requested. Finally an 8 is placed on the stack to invoke the buy protocol and the `perform `command is executed.

As an example, the following code instructs the drone to request iron ore at 10 credits per unit, with the total amount of ore in its cargo hold not to exceed 50 units:


```
<run> 
  10 ( credits per unit )
  50 ( unit cut off ) 
  1 ( iron ore ID ) 
  8 ( buy order ID ) 
  perform 
</run>
```


When executed, this script will post a buy order, which will be publicly displayed online and available for other drones to act on provided the total amount of iron ore in the drone’s cargo bay is under the 50 unit maximum.  In fact, the publicly advertised volume for the trade will always be equal to the difference between the order limit and the amount of resources already available in the spacecraft’s cargo hold.

[Return to TOC](game-play.md#Table-of-Contents)

#### Posting sell orders

Sell orders are placed in much the same way as buy orders and represent a public request to sell a resource at a fixed per unit price.  Also, in the case of sell orders, the limit represents the minimum amount of the resource that must remain in the drone’s cargo bay after the sale is complete.

The following code will place a bid to sell iron ore at 12 credits each. The sales order will remain in effect while the amount of ore in the spacecraft’s hold is greater than 5 units.


```
<run> 
  12 ( credits per unit )
  5 ( unit cut off ) 
  1 ( iron ore ID ) 
  9 ( sell order ID ) 
  perform 
</run>
```

[Return to TOC](game-play.md#Table-of-Contents)


#### Adjusting your orders

It’s possible for a drone to simultaneously have a buy and sell order in place for the same resource. Furthermore, the volumes of these orders will automatically adjust themselves based on the order limits and the amount of resource currently in the drone’s cargo hold.  Once these limits are reached, the saturated order will go dormant until a change in the ship’s cargo manifest makes them relevant again.

Buy and sell orders cannot be canceled.  However they can be replaced by more restrictive orders. Whenever a drone posts a buy order for a specific resource, it replaces the previous buy order for the resource, if one exists.  The same rule applies for sell orders.  Basically, there can only be one buy order and one sell order posted for the same resource at once.

[Return to TOC](game-play.md#Table-of-Contents)

### Transferring resources between drones

The second part of a trade, involves physically transferring the resource between ships. 

Players are able transfer resources between spacecraft using the push and pull protocols.  As with other protocols, these operations are executed by placing several parameters on the stack and then calling the `perform` command. In both instances the first parameter specifies the number of resources being transferred, the second parameter indicates the ID of the transferred resource and the third parameter indicates the type of protocol that is being performed.

In the following example, a drone is instructed to push 5 units of iron ore into the hold of another spacecraft and then pull 10 units of copper ore from that spacecraft into its own hold.


```
<run>
  \ Push 5 units of iron ore into drone 52's cargo hold.
  5 ( quantity ) 1 ( resource ID ) 52 ( target drone ID ) 
  10 ( push protocol ) perform
  \ Pull 10 units of copper ore from drone 52's cargo hold.
  10 ( quantity ) 5 ( resource ID ) 52 ( target drone ID ) 
  11 ( pull protocol ) perform
</run>

```


If the acting and affected drones are owned by the same player, these actions can be performed without referring to active market bids.

However, if the affected spacecraft is owned by a different player, the push and pull commands will only be allowed to take place if the affected drone has posted buy and sell orders for these resources.   In that case, the transaction will be constrained to the limits set by the market order and the total appropriate number of credits will be transferred from the buyer’s to the seller’s bank account.

You can find a current list of active [buy orders](http://exmachinis.com/buys) and [sell orders](http://exmachinis.com/sells) on the Ex Machinis website.

[Return to TOC](game-play.md#Table-of-Contents)

## Chapter 7: Stringing Variables


### Using variables when programming your drones

In FORTH, most values are stored and passed between program elements using the stack.  However, the language also allows users to define simple variables using the `variable `command.  

When a variable is declared, the FORTH interpreter creates an entry in the dictionary using the variable's name.  This new definition serves as a placeholder for any values stored in that variable.

Values can then be stored in and retrieved from a variable using the store (`!`) and fetch (`@`) commands.  In the following example, we define a variable called "box" and then store, fetch, and print the number 2 using this variable.


```
<run>
    variable box ( Define the box variable )
    2 box ! ( Store the number two in the variable )
    box @ . ( Retrieve the contents and print the value )
</run>
```


That's all there is to it!  Try running this script and variations of it on one of your drones.  

Of course, like most things in FORTH, there are some interesting things going on behind the scenes when you declare a variable and store and retrieve values from it.  We'll get into some of this in the next section when we address the creation of arrays.

[Return to TOC](game-play.md#Table-of-Contents)

### What’s really happening with variables?

So let’s talk a little bit about what’s happening behind the scenes with variables.  When you declare a variable with the statement, `variable box`, you’re essentially telling the FORTH interpreter to define a new word for you called `box`.  This definition tells the interpreter that whenever a box** **is called, it should place an address on the stack, which points to a four byte block at the end of the definition.  This location is where your data is stored.

You can confirm this is happening by calling `box `and then printing the contents of the stack. When you do so, your drone will return the address of the place in memory where your value is being stored.  The variable, `box`, will always return the same value no matter what’s actually being stored in the variable.


```
<run>
    box . ( Print the address that is returned by box )
</run>
```


With this in mind, consider that the true functions of fetch (`@`) and store (`!`) are to read and write values to a specified location in memory.  Fetch works by pulling an address off the stack and returning the four-byte value that is stored in that location.  Store pulls an address and then a value from the stack and writes the value to the designated memory slot.

Fetch and store are very powerful (and dangerous!) functions because they allow you to read from and write to ANY location in your drone’s memory!  However, until you’re sure of what you are doing, I suggest that you only use these functions in conjunction with a previously defined variable like `box`.

So why am I telling you about these functions now?  Because understanding how FORTH uses variables is the key to defining and using more powerful strings and arrays!

[Return to TOC](game-play.md#Table-of-Contents)

### Creating and Accessing Arrays

When you define a variable in FORTH, the interpreter automatically sets aside two bytes of memory for the value or character to be stored in the variable.  This is enough room to hold a value between 0 and 65525 or two ASCII characters.  But what if you want the variable to hold more than two characters or one two byte number?

To permit a variable to hold more than one value, you’ll need to instruct the interpreter to set aside additional space in your drones internal memory to accommodate the expanded data set.  This is accomplished by invoking the `allot` command.  This command pulls a value from the stack and tells the interpreter to reserve that many bytes of additional memory for the data.  Since the interpreter reserves this space from the topmost address occupied by the program onward, it’s important that you invoke the `allot` command immediately after declaring the variable.

For example, to create a variable, `box`, with enough room for five two-byte numbers or ten ASCII characters (10 bytes total), you would need to send the following script to your drone:


```
<run> variable box 10 allot </run>
```


Reading and writing to an array that was created using `allot` is actually very simple once you realize that you’re directly accessing a reserved section of memory whose initial location is placed on the stack when you call the variable name.

For example, to add or retrieve a value from the first part of your new `box` array, simply use the variable name in conjunction with the fetch (`@`) and store (`!`) commands described above.


```
<run> 
  box 10 ! \ stores the value 10 in the variable
  box @ . \ retrieves and prints the contents of the variable box
</run>
```


If you want to store and retrieve a value from the second or third position position of the `box` array, you just need to add two or four bytes to the address that’s placed on the stack when you call on the `box` variable:


```
<run> 
box 2 + 10 ! \ stores the value 10 in the second array slot
box 2 + @ . \ retrieves and prints the contents of the second array slot
box 4 + 10 ! \ stores the value 10 in the third array slot
box 4 + @ . \ retrieves and prints the contents of the third array slot
</run>
```

[Return to TOC](game-play.md#Table-of-Contents)


### Working with strings

It's important to understand how strings are formed and managed in FORTH, because your drone will use them to query a database about information on its environment and to report information back to you.

In FORTH, strings of characters are stored in a memory structure known as a counted string. In this structure, the first byte of memory holds a value that indicates the size of the string and subsequent bytes hold individual characters from that string.

Strings are created using the `$"` word, which stores everything up to the next quotation mark in memory and returns an address, which points to the location of the string.

As with the word, `."`, which was introduced above, `$" `can only be used within another definition. However, rather than forcing the string to be added to the output buffer, it causes a pointer to the string to be placed on the stack.

Thus, a string can be stored in a word via a simple definition:


```
<run>
  : myhome $" Earth" ;
</run>
```


This definition creates a word called myhome. When this word is used in another script, it simply places an address on the stack which points to the location of the string "Earth". Since this creates a counted string, the section of memory that is referenced by the address would look like this:


<table>
  <tr>
   <td><strong>Address</strong>
   </td>
   <td><strong>Value/Character</strong>
   </td>
  </tr>
  <tr>
   <td><code>a</code>
   </td>
   <td><code>5</code>
   </td>
  </tr>
  <tr>
   <td><code>a + 1</code>
   </td>
   <td><code>E</code>
   </td>
  </tr>
  <tr>
   <td><code>a + 2</code>
   </td>
   <td><code>a</code>
   </td>
  </tr>
  <tr>
   <td><code>a + 3</code>
   </td>
   <td><code>r</code>
   </td>
  </tr>
  <tr>
   <td><code>a + 4</code>
   </td>
   <td><code>t</code>
   </td>
  </tr>
  <tr>
   <td><code>a + 5</code>
   </td>
   <td><code>h</code>
   </td>
  </tr>
</table>


There are many ways to access this string, which we'll cover at a later point. But one important method is by using the words `count `and `type `to print the string. The word `count `functions by returning the first byte of an address to the stack. In the case of a counted string, this would be the size of the string. The word `type `pulls an address and a length from the stack and prints out the contents of that memory range as characters.

Consequently, the following script will instruct your drone to print the string which is pointed to by the previously defined word:


```
<run>
  myhome count type
</run>
```


Assuming that you've already added home to your drone's dictionary, running the above script will generate an email from your drone that says "Earth".

[Return to TOC](game-play.md#Table-of-Contents)

### Different ways to command your spacecraft

Up until this point, all of the FORTH scripts we’ve been sending to our drones have been bracketed between the `&lt;run>...&lt;/run> `tags.  The `&lt;run>` command specifically tells the spacecraft to process the code in the context of its current machine state.  This means that your drone will not process the script if another program is already running.  Furthermore, if it does process code sent to it between the `&lt;run>...&lt;/run> `tags, the new code will have access to the dictionary, variables, and stack values that were left there by the previous script.  So, if you used the last script to define the word `helloworld`, put 5 in the variable, `box`, or leave 11 on the stack, the next script you execute using the `&lt;run>...&lt;/run>` tags will have access to the same same resources and values.

Although, there are benefits to running your new script in a pre-existing machine state, there will also be times when you’ll need to break into an active script or start over with a clean slate. In these cases you would want to use the `&lt;abort>` and `&lt;reset>` metacommands instead of `&lt;run>`.

As with `&lt;run>`, `&lt;abort> `and <code>&lt;reset><strong> </strong></code>can be used to bracket any code that you’re sending to your spacecraft.  However, <code>&lt;abort></code> and <code>&lt;reset></code> behave very differently with respect to how they handle the drone’s current machine state. 

[Return to TOC](game-play.md#Table-of-Contents)

#### Aborting your script 

The `&lt;abort>` metacommand will stop any code that is running, before executing the script that is bracketed between the `&lt;abort>...&lt;/abort>` tags.  However it will not affect the dictionary, variables, or stack contents that were left behind by the aborted script.  A single `&lt;abort> `tag can also be sent without code, if you just want to stop the current process.

The `&lt;abort>` command is very important for stopping code that is caught in an endless loop.

[Return to TOC](game-play.md#Table-of-Contents)

#### Resetting your machine

The `&lt;reset>` metacommand will stop any active processes and reset the machine to its native state before executing the code contained between the `&lt;reset>...&lt;/reset>` tags. You can also use a single `&lt;reset>` tag to reset the drone without sending any new code.

The `&lt;reset>` command comes in handy when dealing with a drone whose internal memory has been corrupted by a misdirected routine.  This is easy to do if you accidently use the store command (`!`) to overwrite an essential part of memory.

However, the &lt;`reset>` metacommand is more commonly used to start afresh when developing and testing a new set of routines.  In this context, you would store and edit your entire FORTH dictionary in a word processor application and paste it into your email, nested between the `&lt;reset>...&lt;/reset>` tags every time you want to upload and test the modified code in your drone. In fact, you’ll find yourself using the `&lt;reset>` command far more than the run command when programming your drone to perform more complex behaviors.

[Return to TOC](game-play.md#Table-of-Contents)

## Chapter 8: Asking Questions

In Ex Machinis, spacecraft learn about their environment through queries to an onboard database, which contains private information about events that directly affect the drone and public information on the markets and the activities of other ships.

Queries are fairly complex commands because they require you to access your drone's on-board memory (where the results will be stored) and often use strings to define your search.  Furthermore, both of these components need to be passed to the query command via pointers.

The website has a [queries page](https://exmachinis.com/queries), which lists all the queries that are currently available for use by your drones.  These queries invoke predefined SQL scripts, which incorporate user-supplied parameters and return the results to a specified memory location for later retrieval.  The  web-page lists the specific parameters you'll need to invoke a query and details how the results will be arranged in memory.

For example, if I want to find the type of an astronomical object (Query number 3), I'll need to supply the query command with a pointer to a query string (such as "Earth"), a pointer to an array that will receive the query results, a value that limits the size of the results array, and the ID of the query you want to run (3 in this case). 

The following script shows how you would use this query to report the object type for Earth:


```
<run>
  : search_string $" Earth" ; \ search string
  variable results_array 100 allot \ results array
  search_string results_array 100 3 query
  results_array count type \ prints the results
</run>
```


Try sending this script to your drone, with variations on the search term to see how this works.

As we develop more complex queries, we’ll provide additional examples of how spacecraft can use queries to probe and respond to their environment.

[Return to TOC](game-play.md#Table-of-Contents)

## Chapter 9: Making Decisions


### Finding the truth in numbers

FORTH is very loose with numbers and doesn't have specially defined boolean values.  Rather, logical operators interpret all non-zero numbers as true.  Only zero is used to represent the boolean equivalent of false.

A good way to demonstrate this and introduce you to simple branching structures is by defining a new word, which uses an if-else-then statement to make a value judgement about any number that precedes it.

Try emailing the following text to one of your spacecraft:


```
<run>

  ( define true? )
  : true? if ." true " else ." false " then ;

  ( test true? )
  -1 true?
   0 true?
   1 true?

</run>
```


Remember that spaces are very important, so start by copying and pasting the above script directly into your email, including the` &lt;run> ... &lt;/run>` brackets. 

The first part of the emailed script defines a new word, true?, which will return a "true" if the word is preceded by a number other than zero and a "false" if it follows a zero.  In the FORTH syntax, if begins a branched statement, which implements one of two else-delimited options based on the value on the top of the stack.  The word, then, closes the statement.

The second part of this statement invokes true? after placing each of three different values (-1, 0, and 1) on the stack.  This will cause your spacecraft to evaluate each of the numbers as a boolean value and respond accordingly.  

After sending the above code to your spacecraft, you should receive an email from your drone, which contains the following response:


```
true false true ok.
```


By the way, once you define a word like `true?`, your spacecraft will remember it, allowing you to use it in future emails.  So, true evaluating some other integers with the word.  

We'll be using `true?` in the next section to explore logical (`and, or, xor`) and comparison (<code>&lt;, >,<strong> </strong>=</code>) operators.

[Return to TOC](game-play.md#Table-of-Contents)

### Experimenting with logical operators

In the previous section, we used an if else then statement to demonstrate how FORTH, which is used to program player-controlled spacecraft in Ex Machinis, loosely interprets 0 as false and any other number as true.  In the process, we defined the word true?, which returns "false" if the value on the stack is zero and "true" if it is any other number.  

If you haven't already done so, you can define the word `true?` by emailing the following script to your spacecraft.


```
<run>
  : true? if ." true " else ." false " then ;
</run>
```


It turns out that FORTH has three logical operators (`and`, `or`, `xor`) which allow you to combine Boolean values in different ways.  Therefore, if we use 0 for false and 1 for true, you can test these operators by sending the following script to your drone:


```
<run>
  0 1 and true?
  0 1 or true?
  0 1 xor true?
</run>
```


You may not recall that FORTH uses a Reverse Polish Notation (RPN) in which you enter the numbers first (i.e. "0 1") before the function or mathematical operator, `and which` processes them.  Finally, we need to use our previously defined word, true?, to report back the value of the number.  Since "0 and 1" is false, "0 or 1" is true, and "0 xor 1" is also true, your drone will respond to the above script with:


```
true true true ok.
```


Using the above example, instruct your spacecraft to process other combinations of 0's and 1's with and, or, and xor to make sure these logical operators behave as expected.

You may have noticed that there is one very important operator that is missing from the version of FORTH that is currently running on your spacecraft.  There is no not operator.  However, the beauty of FORTH is that you can easily define your own not operator as follows:


```
<run>
  : not 1 xor ; ( define not )

  0 not true? ( see how not works on 0 )
  1 not true? ( see how not works on 1 )
</run>
```


Try sending the above code to your spacecraft to see how it works.

[Return to TOC](game-play.md#Table-of-Contents)

## Appendix A: FORTH Error Codes

Your drone will occasionally return an error message indicating a fault in the code that it’s trying to process.  These errors are reported as negative integers, which can be interpreted using the following table.


<table>
  <tr>
   <td colspan="3" ><strong>FORTH Error Codes and Their Meanings</strong>
   </td>
  </tr>
  <tr>
   <td><strong><span style="text-decoration:underline;">Hex</span></strong>
   </td>
   <td><strong><span style="text-decoration:underline;">Dec</span></strong>
   </td>
   <td><strong><span style="text-decoration:underline;">Message</span></strong>
   </td>
  </tr>
  <tr>
   <td><code>FFFF</code>
   </td>
   <td><code>-1</code>
   </td>
   <td>ABORT
   </td>
  </tr>
  <tr>
   <td><code>FFFE</code>
   </td>
   <td><code>-2</code>
   </td>
   <td>ABORT"
   </td>
  </tr>
  <tr>
   <td><code>FFFD</code>
   </td>
   <td><code>-3</code>
   </td>
   <td>stack overflow
   </td>
  </tr>
  <tr>
   <td><code>FFFC</code>
   </td>
   <td><code>-4</code>
   </td>
   <td>stack underflow
   </td>
  </tr>
  <tr>
   <td><code>FFFB</code>
   </td>
   <td><code>-5</code>
   </td>
   <td>return stack overflow
   </td>
  </tr>
  <tr>
   <td><code>FFFA</code>
   </td>
   <td><code>-6</code>
   </td>
   <td>return stack underflow
   </td>
  </tr>
  <tr>
   <td><code>FFF9</code>
   </td>
   <td><code>-7</code>
   </td>
   <td>do-loops nested too deeply during execution
   </td>
  </tr>
  <tr>
   <td><code>FFF8</code>
   </td>
   <td><code>-8</code>
   </td>
   <td>dictionary overflow
   </td>
  </tr>
  <tr>
   <td><code>FFF7</code>
   </td>
   <td><code>-9</code>
   </td>
   <td>invalid memory address
   </td>
  </tr>
  <tr>
   <td><code>FFF6</code>
   </td>
   <td><code>-10</code>
   </td>
   <td>division by zero
   </td>
  </tr>
  <tr>
   <td><code>FFF5</code>
   </td>
   <td><code>-11</code>
   </td>
   <td>result out of range
   </td>
  </tr>
  <tr>
   <td><code>FFF4</code>
   </td>
   <td><code>-12</code>
   </td>
   <td>argument type mismatch
   </td>
  </tr>
  <tr>
   <td><code>FFF3</code>
   </td>
   <td><code>-13</code>
   </td>
   <td>undefined word
   </td>
  </tr>
  <tr>
   <td><code>FFF2</code>
   </td>
   <td><code>-14</code>
   </td>
   <td>interpreting a compile-only word
   </td>
  </tr>
  <tr>
   <td><code>FFF1</code>
   </td>
   <td><code>-15</code>
   </td>
   <td>invalid FORGET
   </td>
  </tr>
  <tr>
   <td><code>FFF0</code>
   </td>
   <td><code>-16</code>
   </td>
   <td>attempt to use zero-length string as a name
   </td>
  </tr>
  <tr>
   <td><code>FFEF</code>
   </td>
   <td><code>-17</code>
   </td>
   <td>pictured numeric output string overflow
   </td>
  </tr>
  <tr>
   <td><code>FFEE</code>
   </td>
   <td><code>-18</code>
   </td>
   <td>parsed string overflow
   </td>
  </tr>
  <tr>
   <td><code>FFED</code>
   </td>
   <td><code>-19</code>
   </td>
   <td>definition name too long
   </td>
  </tr>
  <tr>
   <td><code>FFEC</code>
   </td>
   <td><code>-20</code>
   </td>
   <td>write to a read-only location
   </td>
  </tr>
  <tr>
   <td><code>FFEB</code>
   </td>
   <td><code>-21</code>
   </td>
   <td>unsupported operation
   </td>
  </tr>
  <tr>
   <td><code>FFEA</code>
   </td>
   <td><code>-22</code>
   </td>
   <td>control structure mismatch
   </td>
  </tr>
  <tr>
   <td><code>FFE9</code>
   </td>
   <td><code>-23</code>
   </td>
   <td>address alignment exception
   </td>
  </tr>
  <tr>
   <td><code>FFE8</code>
   </td>
   <td><code>-24</code>
   </td>
   <td>invalid numeric argument
   </td>
  </tr>
  <tr>
   <td><code>FFE7</code>
   </td>
   <td><code>-25</code>
   </td>
   <td>return stack imbalance
   </td>
  </tr>
  <tr>
   <td><code>FFE6</code>
   </td>
   <td><code>-26</code>
   </td>
   <td>loop parameters unavailable
   </td>
  </tr>
  <tr>
   <td><code>FFE5</code>
   </td>
   <td><code>-27</code>
   </td>
   <td>invalid recursion
   </td>
  </tr>
  <tr>
   <td><code>FFE4</code>
   </td>
   <td><code>-28</code>
   </td>
   <td>user interrupt
   </td>
  </tr>
  <tr>
   <td><code>FFE3</code>
   </td>
   <td><code>-29</code>
   </td>
   <td>compiler nesting
   </td>
  </tr>
  <tr>
   <td><code>FFE2</code>
   </td>
   <td><code>-30</code>
   </td>
   <td>obsolescent feature
   </td>
  </tr>
  <tr>
   <td><code>FFE1</code>
   </td>
   <td><code>-31</code>
   </td>
   <td>>BODY used on non-CREATEd definition
   </td>
  </tr>
  <tr>
   <td><code>FFE0</code>
   </td>
   <td><code>-32</code>
   </td>
   <td>invalid name argument (e.g., TO xxx)
   </td>
  </tr>
  <tr>
   <td><code>FFDF</code>
   </td>
   <td><code>-33</code>
   </td>
   <td>block read exception
   </td>
  </tr>
  <tr>
   <td><code>FFDE</code>
   </td>
   <td><code>-34</code>
   </td>
   <td>block write exception
   </td>
  </tr>
  <tr>
   <td><code>FFDD</code>
   </td>
   <td><code>-35</code>
   </td>
   <td>invalid block number
   </td>
  </tr>
  <tr>
   <td><code>FFDC</code>
   </td>
   <td><code>-36</code>
   </td>
   <td>invalid file position
   </td>
  </tr>
  <tr>
   <td><code>FFDB</code>
   </td>
   <td><code>-37</code>
   </td>
   <td>file I/O exception
   </td>
  </tr>
  <tr>
   <td><code>FFDA</code>
   </td>
   <td><code>-38</code>
   </td>
   <td>non-existent file
   </td>
  </tr>
  <tr>
   <td><code>FFD9</code>
   </td>
   <td><code>-39</code>
   </td>
   <td>unexpected end of file
   </td>
  </tr>
  <tr>
   <td><code>FFD8</code>
   </td>
   <td><code>-40</code>
   </td>
   <td>invalid BASE for floating point conversion
   </td>
  </tr>
  <tr>
   <td><code>FFD7</code>
   </td>
   <td><code>-41</code>
   </td>
   <td>loss of precision
   </td>
  </tr>
  <tr>
   <td><code>FFD6</code>
   </td>
   <td><code>-42</code>
   </td>
   <td>floating-point divide by zero
   </td>
  </tr>
  <tr>
   <td><code>FFD5</code>
   </td>
   <td><code>-43</code>
   </td>
   <td>floating-point result out of range
   </td>
  </tr>
  <tr>
   <td><code>FFD4</code>
   </td>
   <td><code>-44</code>
   </td>
   <td>floating-point stack overflow
   </td>
  </tr>
  <tr>
   <td><code>FFD3</code>
   </td>
   <td><code>-45</code>
   </td>
   <td>floating-point stack underflow
   </td>
  </tr>
  <tr>
   <td><code>FFD2</code>
   </td>
   <td><code>-46</code>
   </td>
   <td>floating-point invalid argument
   </td>
  </tr>
  <tr>
   <td><code>FFD1</code>
   </td>
   <td><code>-47</code>
   </td>
   <td>compilation word list deleted
   </td>
  </tr>
  <tr>
   <td><code>FFD0</code>
   </td>
   <td><code>-48</code>
   </td>
   <td>invalid POSTPONE
   </td>
  </tr>
  <tr>
   <td><code>FFCF</code>
   </td>
   <td><code>-49</code>
   </td>
   <td>search-order overflow
   </td>
  </tr>
  <tr>
   <td><code>FFCE</code>
   </td>
   <td><code>-50</code>
   </td>
   <td>search-order underflow
   </td>
  </tr>
  <tr>
   <td><code>FFCD</code>
   </td>
   <td><code>-51</code>
   </td>
   <td>compilation word list changed
   </td>
  </tr>
  <tr>
   <td><code>FFCC</code>
   </td>
   <td><code>-52</code>
   </td>
   <td>control-flow stack overflow
   </td>
  </tr>
  <tr>
   <td><code>FFCB</code>
   </td>
   <td><code>-53</code>
   </td>
   <td>exception stack overflow
   </td>
  </tr>
  <tr>
   <td><code>FFCA</code>
   </td>
   <td><code>-54</code>
   </td>
   <td>floating-point underflow
   </td>
  </tr>
  <tr>
   <td><code>FFC9</code>
   </td>
   <td><code>-55</code>
   </td>
   <td>floating-point unidentified fault
   </td>
  </tr>
  <tr>
   <td><code>FFC8</code>
   </td>
   <td><code>-56</code>
   </td>
   <td>QUIT
   </td>
  </tr>
  <tr>
   <td><code>FFC7</code>
   </td>
   <td><code>-57</code>
   </td>
   <td>exception in sending or receiving a character
   </td>
  </tr>
  <tr>
   <td><code>FFC6</code>
   </td>
   <td><code>-58</code>
   </td>
   <td>[IF], [ELSE], or [THEN] exception
   </td>
  </tr>
</table>

[Return to TOC](game-play.md#Table-of-Contents)
