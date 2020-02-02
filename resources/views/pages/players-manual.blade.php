@extends('layouts.app')
@section('pageTitle', 'Players Manual')
@section('content')
    <!-- Breadcrumbs-->
    <section class="breadcrumbs-modern box-transform-wrap bg-image context-dark"
             style="background-image: url('/images/bg-breadcrumbs.jpg');">
        <div class="container">
            <div class="breadcrumbs-modern-body">
                <h5 class="breadcrumbs-modern-subtitle"></h5>
                <h1 class="breadcrumbs-modern-title">Player's Manual</h1>
            </div>
            <ul class="breadcrumbs-path breadcrumbs-modern-path">
                <li><a href="/">Home</a></li>
                <li class="active">Player's Manual</li>
            </ul>
        </div>
        <div class="box-transform" style="background-image: url('/images/bg-breadcrumbs.jpg');"></div>
        <div class="comets comets-left"></div>
        <div class="comets comets-center"></div>
        <div class="comets comets-top"></div>
    </section>
    <section class="section section-lg bg-default">
        <div class="container">
            <div class="row">
                <div class="col-md-12 col-lg-10 col-xl-9 single-post">
                    <div class="post-big">

                      <p>Ex Machinis is a multiplayer space game, which takes place in a parallel universe. Within
                          this altered reality, space technologies have rapidly advanced since the 1950’s to the point
                          where extensive interplanetary industries exist to produce materials that drive the human
                          economy back on Earth. The vast majority of these spacecraft and are unmanned vehicles,
                          which are assembled in space and are controlled terrestrial barons.</p>

                      <p>As part of the international agreement that drove the colonization of space at the end of the
                          second world war, citizens of the signitor nations are guaranteed access to basic spacecraft
                          which can be used to build profitable businesses. This democratization of space has been the
                          biggest driver behind the rapid growth of extraterrestrial industries and technologies.</p>

                      <p>It's your birthright to take control of your own remotely piloted spacecraft and build a
                          thriving stellar industry. But this can only be accomplished by programming your spacecraft
                          to efficiently mine, manufacture, and trade materials on the interplanetary economy.</p>

                        <br/><H2 id="h2_TOC">Table of Contents</h2>

                        <p><a href="#h2_getting_started"><b>Chapter 1. Getting started</b></a></p>
                        <p><a href="#h3_joining_the_game">- Joining the game</a></p>
                        <p><a href="#h3_renaming_your_spacecraft">- Renaming your spacecraft</a></p>
                        <p><a href="#h3_sending_your_first_command">- Sending your first command</a></p>
                        <p><a href="#h3_understanding_the_stack">- Understanding the stack</a></p>
                        <p><a href="#h3_performing_protocols">- Performing protocols</a></p>
                        <p><a href="#h3_putting_it_into_words">- Putting it into words</a></p>

                        <p><a href="#h2_learning_FORTH"><b>Chapter 2: Learning FORTH</b></a></p>
                        <p><a href="#h3_managing_the_stack">- Managing the stack</a></p>
                        <p><a href="#h3_building_a_dictionary">- Building a dictionary</a></p>
                        <p><a href="#h3_commenting_on_your_code">- Commenting on your code</a></p>
                        <p><a href="#h3_sending_greetings">- Sending greetings</a></p>
                        <p><a href="#h3_finding_the_truth_in_numbers">- Finding the truth in numbers</a></p>
                        <p><a href="#h3_experimenting_with_logical_operators">- Experimenting with logical operators</a></p>
                        <p><a href="#h3_using_variables_when_programming_your_drones">- Using variables when programming your drones</a></p>
                        <p><a href="#h3_whats_really_happening_with_variables">- What's really happening with variables?</a></p>
                        <p><a href="#h3_working_with_strings">- Working with strings</a></p>

                        <br/><h2 id="h2_getting_started">Chapter 1. Getting started</h2>


                        <br/><h3 id="h3_joining_the_game">Joining the game</h3>

                        <p>To start playing, you simply need to email the Registrar (registrar@<?php echo  getenv("MAIL_HOST") ?>) with the word “register” in the subject line. You’ll receive a response from the registrar listing the email addresses of three spacecraft that are under your control. These spacecraft will only respond to communications from the email address you used to contact the registrar.</p>

                        <p>We’ll only use your email to send in-game communications from your spacecraft or an occasional newsletter summarizing updates to the game. Furthermore, you’ll only receive emails from your spacecraft in response to your own communications and you can unsubscribe from the newsletter at any time.</p>

                        <br/><h3 id="h3_renaming_your_spacecraft">Renaming your spacecraft</h3>
                        <p>You’ll notice that each of your spacecraft has a rather generic and unremarkable email address like SN523854@<?php echo  getenv("MAIL_HOST") ?>. You’ll probably want to give them more memorable name like “hero” or "scout" before proceeding futher.</p>

                        <p>To rename your spacecraft, simply send the following text in an email to your drone:</p>

<pre><code>&lt;rename&gt;hero&lt;/rename&gt;</code></pre>

                        <p>You’ll actually receive an email from hero@<?php echo  getenv("MAIL_HOST") ?> confirming the name change. You can now send your instructions to this email address instead of the original!</p>

                        <p>There is no limit to the number of times you change your drone’s email address. The only requirements are that you pick a name that doesn’t contain any illegal characters and isn’t already in use by someone else. If your chosen name is not allowed, the drone will let you know via its original email address.</p>

                        <br/><h3 id="h3_sending_your_first_command">Sending your first command</h3>

                        <p>Most of the game is played via direct email communications with your spacecraft. When one of your ships receives an email from you it will interpret everything between the &lt;run&gt; &lt;/run&gt; brackets as FORTH code and respond to you accordingly via email.</p>

                        <p>FORTH is an amazingly simple but highly flexible programming language that grows out of a few core interpreter functions. In fact, the version of FORTH, which is currently installed in each of your spacecraft, is described in around 200 lines of game-side C code. We'll be introducing you to components of the FORTH programming language as we proceed through this tutorial.</p>

                        <p>For now, try sending the following text to one of your spacecraft and see what happens. Just make sure you retain the spaces between the numbers, the plus sign, and the period, which instructs the spacecraft to report the result.</p>

<pre><code>&lt;run&gt;
  2 3 + .
&lt;/run&gt;</code></pre>

                        <p>You'll receive an email from your spacecraft that reports the result of adding two and three
                            together.</p>

                        <br/><h3 id="h3_understanding_the_stack">Understanding the stack</h3>
                        <p>You may have noticed that FORTH has a strange way of doing math. In FORTH, the numbers come first followed by the operator (2 3 +). This is because FORTH relies on a data stack to transfer values to and from functions.</p>

                        <p>For example, when you enter "2” and “3” seperatred by a space, FORTH places a 2 and then a 3 on the data stack. You can visualize this as a stack of dishes in which the number 2 plate is laid down first and the number 3 plate is placed on top of it. Consequently, when objects are removed from the stack the topmost value is removed first (3) and then the next value (2).</p>

                        <p>When you call a FORTH function, like addition (+), it removes the top two values from the stack (2 and 3), adds them together, and returns the result (5) to the stack.</p>

                        <p>Similarly, calling the print function (.), pulls a value off the stack (5) and adds it the output stream, which is returned to you in an email as "5".</p>

                        <p>All FORTH functions behave the same way, by removing and adding values to the stack. This is why FORTH is a stack-based language. It’s also why FORTH is a very efficient and intuitive programming language.</p>

                        <p>Understanding stacks is the key to learning FORTH and programming kick-ass spacecraft in Ex Machinis.</p>

                        <br/><h3 id="h3_performing_protocols">Performing protocols</h3>

                        <p>Every physical action your spacecraft takes will result from calls to predefined protocols.  Protocols enable your drones to do things like mine resources, manufacture products, and travel between locations.  In many cases, protocols use, deplete, and produce reseources located in your ship's hold.</p>

                        <p>You can find a complete <a href="http://<?php echo  getenv("MAIL_HOST") ?>/protocols">list of protocols</a> on the website.  Right now, your choice of protocols is very limited.  That's because we've just created a few simple protocols to test game mechanics.  However, as the game matures, the number and complexity of these actions will increase dramatically.</P>

                        <p>Protocols are invoked by sending your drone a FORTH script that calls upon the "perform" function.  This function pulls one or more paramaters from the stack.  These paramaters provide the ID for the protocol you want to perform and any other parameters that are required by the protocol.</p>

                        <p>For example, if you wanted to move your spaceraft (Protocol ID = 5) to Saturn (Object ID = 6) you would send your spacecraft the following message:
<pre><code>&lt;run&gt;
  6 5 perform
&lt;/run&gt;</code></pre>

                        <p>And as quick as that, your spacecraft will be at Saturn!  Give it a try and see what happens.  You'll notice that your drones always report their current location at the top of every email they send you.  This location will now indicate Saturn.</p>

                        <p>You can use this command to go elsewhere in the solarsystem as well.  All you need is the Object ID for your destination.  This can be found in the <a href="http://<?php echo  getenv("MAIL_HOST") ?>/objects">Astronomical Objects table</a>.</p>

                        <br/><h3 id="h3_putting_it_into_words">Putting it into words</h3>

                        <p>FORTH makes it very easy to define and apply new functions on the run.  In FORTH, functions are known as words and they'll be added to you're spacecraft's dictionary as soon as you define them.  Once in the dictionary, they can be invoked in any future script you send to your drone.</p>

                        <p>A FORTH definition consists of a colon followed by the word you're defining and then the values and words that comprise the definition. The definition ends with a semicolon.  Here's an example of how you can define a word that returns your spacecraft to Earth (Object ID = 3).
<pre><code>&lt;run&gt;
  : comehome 3 5 perform ;
&lt;/run&gt;</code></pre>

                        <p>Now, whenever you want to call your spacecraft back to Earth you simply need to send it this command.</p>

<pre><code>&lt;run&gt;
  comehome
&lt;/run&gt;</code></pre>

                        <p>Here are some things to keep in mind when defining a new word:</p>
                        <p>Your word can contain any characters other than white-space characters.  This is becuase FORTH uses whitespace to seperate words and numbers from one another.</p>

                        <p>Because whitespaces seperate words and numbers, it's important to include spaces between all your programming elements, inludein colons and semicolons.  In the above example, FORTH will interpret "perform;" as a single word if the space is omitted.</p>

                        <p>You can pass values to a word by placing them on the stack. In other words, any thing that's on the stack when the word is called can be pulled from the stack by one of the words that are called from within the definition.</p>


                        <br/>
                        <h2 id="h2_learning_FORTH">Chapter 2. Learning FORTH</h2>

                        <p>This chapter is intended to give new users a basic understanding of FORTH so that they can use it to remotely pilot their spacecraft.</p>



                        <br/>
                        <h3 id="h3_managing_the_stack">Managing the stack</h3>
                        <p>In FORTH, different program elements share data by adding and removing values from this stack
                            in a last-on-first-off manner. For example, when I email the following code one of my
                            spacecraft, it adds the space-delimited values to the stack so that 1 is on the bottom of
                            the stack and 4 is on top.</p>

                        <pre >
<code>&lt;run&gt;
  1 2 3 4
&lt;/run&gt;</code></pre>

                        <p>If I were to follow this with a call to the addition function (+), it would remove the 4 and
                            3 from the top of the stack and place a 7 on the stack. The stack from bottom to top would
                            end up looking like this: 1 2 7</p>

                        <p>You may not always want to deal with values in the order they were placed on the stack.
                            Therefore, a number of commands exist that allow you to directly manipulate the order and
                            contents of the stack.</p>

                        <p>Try applying each of the following commands to manipulate the contents of the data stack in
                            one of your spacecraft:</p>

                        <div class="table-custom-responsive">
                            <table class="table-custom table-custom-bordered">
                                <tr>
                                    <th>Word</th>
                                    <th>Action</th>
                                    <th>Example *</th>
                                </tr>

                                <tr>
                                    <td>drop</td>
                                    <td>Discards the top most item on the stack.</td>
                                    <td>0 1 2 3 drop . . .</td>
                                </tr>

                                <tr>
                                    <td>nip</td>
                                    <td>Discards the second item on the stack.</td>
                                    <td>0 1 2 3 nip . . .</td>
                                </tr>

                                <tr>
                                    <td>dup</td>
                                    <td>Copies the top most item on the stack.</td>
                                    <td>0 1 2 3 dup . . .</td>
                                </tr>

                                <tr>
                                    <td>over</td>
                                    <td>Copies the second item on the stack the stack.</td>
                                    <td>0 1 2 3 over . . .</td>
                                </tr>

                                <tr>
                                    <td>swap</td>
                                    <td>Switches to order of the top two items on the stack.</td>
                                    <td>0 1 2 3 swap . . .</td>
                                </tr>
                            </table>
                        </div>

                        <p>* The three dots in each of these examples instruct the interpreter to print the first three
                            items on the stack. It’s important that you place a space between each of the periods and
                            the word that precedes them.</p>

                        <p>When trying the above examples, remember that FORTH words are case sensitive and it is
                            important that you include spaces between the numbers, words, and periods. It’s also
                            important that you include all FORTH script between &lt;run&gt; and &lt;/run&gt;. Otherwise
                            the interpreter won’t processes the code.</p>

                        <br/>
                        <h3 id="h3_building_a_dictionary">Building a dictionary</h3>
                        <p>FORTH is a very simple language. Most of the heavy lifting is done using the data stack and a
                            dictionary of user-defined functions.</p>

                        <p>Every time you send your spacecraft instructions enclosed between the script brackets, the
                            spacecraft's on-board interpreter will try to parse your text into words or numbers.</p>

                        <p>The interpreter will first look for a string of space delimited characters in the onboard
                            dictionary. If the characters represent a previously defined word, the interpreter will
                            execute the corresponding function. If not, it will attempt to interpret the string as a
                            value and place it on the stack.</p>

                        <p>For example, the arithmetic operators (+, -, *, /) represented words, which the interpreter
                            can find in the dictionary and execute to perform the proscribed computation.</p>

                        <p>However, FORTH makes it very easy to define your own words on the fly for use in scripts and
                            higher level definitions. New words are defined by simply enclosing the word and the
                            corresponding script between a colon (:) and a semicolon (;).</p>

                        <p>For example, if you find that you are frequently adding five to a number on the stack you can
                            create the word, AddFive, to handle this operation in the future. You would define AddFive
                            by sending the following script to your spacecraft:</p>

                        <pre >
<code>&lt;run&gt;
 : AddFive 5 + ;
&lt;/run&gt;</code></pre>

                        <p>Now, anytime you want your spacecraft to add five to the number on the stack you can use your
                            new word instead of 5 +:</p>

                        <pre >
<code>&lt;run&gt;
  4 AddFive .
&lt;/run&gt;</code></pre>

                        <p>This script will cause your spacecraft to return the same result ("9") as the following
                            script:</p>

                        <pre >
<code>&lt;run&gt;
  4 5 + .
&lt;/run&gt;</code></pre>

                        <p>In this example, the word AddFive really doesn't offer much advantage over simply typing 5 +.
                            However, you can easily imagine defining words that perform more complex functions that you
                            wouldn't want to retype every time.</p>

                        <p>Give this a try with your own spacecraft! I'm sure you can come up with more useful
                            definitions than the one provided here.</p>

                        <br/>
                        <h3 id="h3_commenting_on_your_code">Commenting on your code</h3>
                        <p>As with most programming languages, FORTH offers for some convenient mechanisms for
                            commenting on your code. This techniques can be very handy in reminding yourself and others
                            of the intentent and function of your definitions. There are two primary ways to add
                            comments to your script that will be ignored by the interpreter:</p>

                        <p>When you start a line with a backslash (\) the interpreter will ignore everything else on
                            that line.</p>

                        <p>The interpreter will also ignore any text enclosed between parentheses, regardless of where
                            they occur in the line or whether they enclose multiple lines.</p>

                        <p>Note: Remember that the interpreter uses white space to deliminate words. Therefore,
                            backslashes and parentheses will only be recognized as such by the interpreter if there are
                            spaces between them and other characters.</p>

                        <br/>
                        <h3 id="h3_sending_greetings">Sending greetings</h3>
                        <p>Having your spacecraft output text in an email to you is actually quite easy to do and
                            obviously very useful. There are a couple ground rules though: First, this technique for
                            printing quotes can only be called from within a word definition (ie. function). Second,
                            it’s critical that spaces separate your quotes from the text that is contained between
                            them.</p>

                        <p>With that in mind, the following definition creates a word that, when called, instructs the
                            spacecraft to print a specific string in its email response to you.</p>

                        <p>Start by emailed the following text to one of your spacecraft:</p>

                        <pre >
<code>&lt;run&gt;
  : helloworld .” Greetings Earthlings! “ ;
&lt;/run&gt;</code></pre>

                        <p>The spacecraft will respond to this email with a simple "ok" to acknowledge that it has
                            received the new definition and incorporated it into its on-board dictionary. Remember, the
                            spaces in the above example are very important since the allow the interpreter to delineate
                            words and numbers.</p>

                        <p>Next, envoke your new word by sending the following email to your spacecraft:</p>

                        <pre >
<code>&lt;run&gt;
  helloworld
&lt;/run&gt;</code></pre>

                        <p>Sending this script to your drone will result in a heartfelt greeting from your favorite
                            spacecraft! I guess there's no surprises there. But this will obviously be a useful tool
                            when having your ships provide intelligible status reports.</p>

                        <p>The important components of this command are the dot-quote (."), which instructs the craft to
                            print the text that follows and the end-quote ("), which ends the printed string. Just
                            remember that because the FORTH interpreter views the dot and end quotes as distinct words,
                            they must be separated from everything around them by a white-space.</p>

                        <br/>
                        <h3 id="h3_finding_the_truth_in_numbers">Finding the truth in numbers</h3>
                        <p>FORTH is very loose with numbers and doesn't have specially defined boolean values. Rather,
                            logical operators interpret all non-zero numbers as true. Only zero is used to represent the
                            boolean equivalent of false.</p>

                        <p>A good way to demonstrate this and introduce you to simple branching structures is by
                            defining a new word, which uses an if-else-then statement to make a value judgement about
                            any number that precedes it. Try emailing the following text to one of your spacecraft:</p>

                        <pre >
<code>&lt;run&gt;
  ( define true? )
  : true? if ." true " else ." false " then ;

  ( test true? )
  -1 true?
  0 true?
  1 true?
&lt;/run&gt;</code></pre>

                        <p>Remember that spaces are very important, so start by copying and pasting the above script
                            directly into your email, including the &lt;run&gt; &lt;/run&gt; brackets.</p>

                        <p>The first part of the emailed script defines a new word, true?, which will return a "true" if
                            the word is preceded by a number other than zero and a "false" if it follows a zero. In the
                            FORTH syntax, if begins a branched statement, which implements one of two else-delimited
                            options based on the value on the top of the stack. The word, then, closes the
                            statement.</p>

                        <p>The second part of this statement invokes true? after placing each of three different values
                            (-1, 0, and 1) on the stack. This will cause your spacecraft to evaluate each of the numbers
                            as a boolean value and respond accordingly.</p>

                        <p>After sending the above code to your spacecraft, you should receive an email from your drone,
                            which contains the following response:</p>

                        <samp>true false true ok.</samp>

                        <p>By the way, once you define a word like true?, your spacecraft will remember it, allowing you
                            to use it in future emails. So, true evaluating some other integers with the word.</p>

                        <p>We'll be using true? in the next section to explore logical (and, or, xor) and comparison (<,
                            >, =) operators.</p>

                        <br/>
                        <h3 id="h3_experimenting_with_logical_operators">Experimenting with logical operators</h3>
                        <p>In the previous section, we used an if else then statement to demonstrate how FORTH, which is
                            used to program player-controlled spacecraft in Ex Machinis, loosely interprets 0 as false
                            and any other number as true. In the process, we defined the word true?, which returns
                            "false" if the value on the stack is zero and "true" if it is any other number.</p>

                        <p>If you haven't already done so, you can define the word true? by emailing the following
                            script to your spacecraft.</p>

                        <pre >
<code>&lt;run&gt;
  : true? if ." true " else ." false " then ;
&lt;/run&gt;</code></pre>

                        <p>It turns out that FORTH has three logical operators (and, or, xor) which allow you to combine
                            Boolean values in different ways. Therefore, if we use 0 for false and 1 for true, you can
                            test these operators by sending the following script to your drone:</p>

                        <pre >
<code>&lt;run&gt;
  0 1 and true?
  0 1 or true?
  0 1 xor true?
&lt;/run&gt;</code></pre>

                        <p>You may not recall that FORTH uses a Reverse Polish Notation (RPN) in which you enter the
                            numbers first (i.e. "0 1") before the function or mathematical operator (and), which
                            processes them. Finally, we need to use our previously defined word, true?, to report back
                            the value of the number. Since "0 and 1" is false, "0 or 1" is true, and "0 xor 1" is also
                            true, your drone will respond to the above script with:</p>

                        <samp>true true true ok.</samp>

                        <p>Using the above example, instruct you spacecraft to process other combinations of 0's and 1's
                            with and, or, and xor to make sure these logical operators behave as expected.</p>

                        <p>You may have noticed that there is one very important operator that is missing from the
                            version of FORTH that is currently running on your spacecraft. There is no not operator.
                            However, the beauty of FORTH is that you can easily define your own not operator as
                            follows:</p>

                        <pre >
<code>&lt;run&gt;
  : not 1 xor ; ( define not )
  0 not true? ( see how not works on 0 )
  1 not true? ( see how not works on 1 )
&lt;/run&gt;</code></pre>

                        <p>Try sending the above code to your spacecraft to see how it work.</p>

                        <br/>
                        <h3 id="h3_using_variables_when_programming_your_drones">Using variables when programming your drones</h3>
                        <p>In FORTH, most values are stored and passed between program elements using the stack.
                            However, the language also allows users to define simple variables using the variable
                            command.</p>

                        <p>When a variable is declared, the FORTH interpreter creates an entry in the dictionary using
                            the variable's name. This new definition serves as a placeholder for any values stored in
                            that variable. Values can then be stored in and retrieved from a variable using the store
                            (!) and fetch (@) commands. In the following example, we define a variable called "box" and
                            then store, fetch, and print the number 2 using this variable.</p>

                        <pre >
<code>&lt;run&gt;
  variable box ( Define the box variable )
  2 box ! ( Store the number two in the variable )
  box @ . ( Retrieve the contents and print the value )
&lt;/run&gt;</code></pre>

                        <p>That's all there is to it! Try running this script and variations of it on one of your
                            drones.</p>

                        <p>Of course, like most things in FORTH, there are some interesting things going on behind the
                            scenes when you declare a variable and store and retrieve values from it. We'll get into
                            some of this in the next section when we address the creation of arrays.</p>

                        <br/>
                        <h3 id="h3_whats_really_happening_with_variables">What’s really happening with variables?</h3>
                        <p>So let’s talk a little bit about what’s happening behind the scenes with variables. When you
                            declare a variable with the statement, “variable box”, you’re essentially telling the FORTH
                            interpreter to define a new word for you called box. This definition tells the interpreter
                            that whenever box is called, it should place an address on the stack, which points to a four
                            byte block at the end of the definition. This location is where your data is stored.</p>

                        <p>You can confirm this is happening by calling box and then printing the contents of the stack.
                            When you do so, your drone will return the address of the place in memory where your value
                            is being stored. The variable, box, will always return the same value no matter what’s
                            actually being stored in the variable.</p>

                        <pre >
<code>&lt;run&gt;
  box . ( Print the address that is returned by box )
&lt;/run&gt;</code></pre>

                        <p>With this in mind, consider that the true functions of fetch (@) and store (!) are to read
                            and write values to a specified location in memory. Fetch works by pulling an address off
                            the stack and returning the four-byte value that is stored in that location. Store pulls an
                            address and then a value from the stack and writes the value to the designated memory
                            slot.</p>

                        <p>Fetch and store are very powerful (and dangerous!) functions because they allow you to read
                            from and write to ANY location in your drone’s memory! However, until you’re sure of what
                            you are doing, I suggest that you only use these functions in conjunction with a previously
                            defined variable like box.</p>

                        <p>So why am I telling you about these functions now? Because understanding how FORTH uses
                            variables is the key to defining and using more powerful strings and arrays!</p>

                        <br/>
                        <h3 id="h3_working_with_strings">Working with strings</h3>
                        <p>It's important to understand how stings are formed and managed in FORTH, because your drone
                            will use them to query to database about information on its environment and to report
                            information back to you.</p>

                        <p>In FORTH, strings of chacrters are stored in a memory structure known as a counted string. In
                            this structure, the first byte of memory holds a value that indicates the size of the string
                            and subesquent bytes hold individual characters from that string.</p>

                        <p>Strings are created using the $" word, which stores everything up to the next quotation mark
                            in memory and returns an address, which points to the location of the string.</p>

                        <p>As with the word, .", which was introduced above, $" can only be used within another
                            definition. However, rather than forcing the string to be added to the output buffer, it
                            causes a pointer to the string to be placed on the stack.</p>

                        <p>Thus, a string can be stored in a word via a simple definition:</p>

                        <pre >
<code>&lt;run&gt;
  : myhome $" Earth" ;
&lt;/run&gt;</code></pre>

                        <p>This definition creates a word called myhome. When this word is used in another script, it
                            simply places an address on the stack which points to the location of the string "Earth".
                            Since this creates a counted string, the section of memory that is referenced by the address
                            would look like this:</p>

                        <div class="table-custom-responsive">
                            <table class="table-custom table-custom-bordered">
                                <tr>
                                    <th>Address</th>
                                    <th>Value/Chracter</th>
                                </tr>
                                <tr>
                                    <td>a</td>
                                    <td>5</td>
                                </tr>
                                <tr>
                                    <td>a + 1</td>
                                    <td>E</td>
                                </tr>
                                <tr>
                                    <td>a + 2</td>
                                    <td>a</td>
                                </tr>
                                <tr>
                                    <td>a + 3</td>
                                    <td>r</td>
                                </tr>
                                <tr>
                                    <td>a + 4</td>
                                    <td>t</td>
                                </tr>
                                <tr>
                                    <td>a + 5</td>
                                    <td>h</td>
                                </tr>
                            </table>
                        </div>

                        <p>There are many ways to access this string, which we'll cover at a later point. But one
                            important way to do so is by using the words count and type to print out the string. The
                            word count functions by returning the first byte of an address to the stack. In the case of
                            a counted string, this would be the size of the string. The word type pulls an address and a
                            lendth from the stack and prints out the contents of that memory range as characters.</p>

                        <p>Consequently, the following script will istruct your drone to print the string which is
                            pointed to by the previously defined word:</p>

                        <pre >
<code>&lt;run&gt;
  myhome count type
&lt;/run&gt;</code></pre>

                        <p>Assuming that you've already added home to your drone's dictionary, running the above script
                            will generate an email from your drone that says "Earth".</p>


                    </div>
                </div>
            </div>
    </section>
@endsection
