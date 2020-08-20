@extends('layouts.app')
@section('pageTitle', 'Getting Started')
@section('content')
    <!-- Breadcrumbs-->
    <section class="breadcrumbs-modern box-transform-wrap bg-image context-dark"
             style="background-image: url('/images/bg-breadcrumbs.jpg');">
        <div class="container">
            <div class="breadcrumbs-modern-body">
                <h5 class="breadcrumbs-modern-subtitle"></h5>
                <h1 class="breadcrumbs-modern-title">Getting Started</h1>
            </div>
            <ul class="breadcrumbs-path breadcrumbs-modern-path">
                <li><a href="/">Home</a></li>
                <li class="active">Getting Started</li>
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
                            economy back on Earth and Mars. The vast majority of these spacecraft and are unmanned vehicles,
                            which are assembled in space and are controlled terrestrial barons.</p>

                        <p>As part of the international agreement that drove the colonization of space at the end of the
                            second world war, citizens of the signitor nations are guaranteed access to basic spacecraft
                            which can be used to build profitable businesses. This democratization of space has been the
                            biggest driver behind the rapid growth of extraterrestrial industries and technologies.</p>

                        <p>It's your birthright to take control of your own remotely piloted spacecraft and build a
                            thriving stellar industry. But this can only be accomplished by programming your spacecraft
                            to efficiently mine, manufacture, and trade materials on the interplanetary economy.</p>


                        <br/><h3 id="h3_joining_the_game">Joining the game</h3>

                        <p>Joining the game is simple and free. When you <a href="https://<?php echo  getenv("MAIL_HOST") ?>/register">register</a> with Ex Machinis, you'll receive an email from the registrar listing the addresses of three spacecraft that are under your control. These spacecraft will only respond to communications from the email address you used in your registration form.</p>

                        <br/><h3 id="h3_renaming_your_spacecraft">Renaming your spacecraft and your company</h3>
                        <p>You’ll notice that each of your spacecraft has a rather generic and unremarkable email address like SN523854@<?php echo  getenv("MAIL_HOST") ?>. You’ll probably want to give them more memorable name like “hero” or "scout" before proceeding futher.</p>

                        <p>To rename your spacecraft, simply send the following text in an email to your drone:</p>

<pre><code>&lt;rename&gt;hero&lt;/rename&gt;</code></pre>

                        <p>You’ll actually receive a response from hero@<?php echo  getenv("MAIL_HOST") ?> confirming the name change. You can now send your instructions to this email address instead of the original!</p>

                        <p>You can also change your company name at anytime by emailing a rebrand instruction to one of your drones:</p>

<pre><code>&lt;rebrand&gt;Avalon&lt;/rebrand&gt;</code></pre>

                        <p>This will immediately change the name of your company in the <a href="https://<?php echo  getenv("MAIL_HOST") ?>/companies">company directory</a>.</p>

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

                        <p>You can use this command to go elsewhere in the solarsystem as well.  All you need is the Object ID for your destination.  This can be found in the <a href="http://<?php echo  getenv("MAIL_HOST") ?>/objects">Space Objects table</a>.</p>

                        <br/><h3 id="h3_putting_it_into_words">Putting it into words</h3>

                        <p>FORTH makes it very easy to define and apply new functions on the run.  In FORTH, functions are known as words and they'll be added to you're spacecraft's dictionary as soon as you define them.  Once in the dictionary, they can be invoked in any future script you send to your drone.</p>

                        <p>A FORTH definition consists of a colon followed by the word you're defining and then the values and words that comprise the definition. The definition ends with a semicolon.  Here's an example of how you can define a word that returns your spacecraft to Earth (Object ID = 3).
<pre><code>&lt;run&gt;
  : come_home 3 5 perform ;
&lt;/run&gt;</code></pre>

                        <p>Now, whenever you want to call your spacecraft back to Earth you simply need to send it this command.</p>

<pre><code>&lt;run&gt;
  come_home
&lt;/run&gt;</code></pre>

                        <p>Here are some things to keep in mind when defining a new word:</p>
                        <p>Your word can contain any characters other than white-space characters.  This is becuase FORTH uses whitespace to seperate words and numbers from one another.</p>

                        <p>Because whitespaces seperate words and numbers, it's important to include spaces between all your programming elements, inludein colons and semicolons.  In the above example, FORTH will interpret "perform;" as a single word if the space is omitted.</p>

                        <p>You can pass values to a word by placing them on the stack. In other words, anything that's on the stack when the word is called can be pulled from the stack by one of the words that are called from within the definition.</p>

                        <p>For example, suppose you wanted to define a more general movement command.  You could create a new word uses the last number placed on the stack as the Object ID:</p>

<pre><code>&lt;run&gt;
  : go_there 5 perform ;
&lt;/run&gt;</code></pre>

                        <p>Now you can send your spacecraft to Jupiter (Object ID = 5), or any other location, by placing the destination's object ID on the stack before invoking your new command:

<pre><code>&lt;run&gt;
  5 go_there ( go to Jupiter )
  3 go_there ( go to Earth )
&lt;/run&gt;</code></pre>

<br/><h3 id="h3_getting_out_of_trouble">Getting out of trouble</h3>
<p>It's definately possible to break your drone by sending it code that puts it into an endless loop or damages an important part of its memory. In these cases your spacecraft will become completely undresponsive to subsequent &lt;run&gt; commands. However, it's not hard to recover from these situations if you know what to do.  Here are two metacommands that can resue you from almost any software crash:</p>

<p>Sending "&lt;abort&gt;" to your spacecraft will cause it to imediately terminate any currently active code, allowing you to break it free from an endless loop.</p>

<p>Sending "&lt;reset&gt;" to your drone will not only stop any active scrpts, it will also reset your spacecraft's internal memory to its "factory setting," erasing any damage you might have done to it's internal memory. This metacommand will also erase any words or variables you defined earlier.  But sometimes its good to start with a clean slate.</p>


<br/><h3>Learning more about the game</h3>
<p>Your spacecraft can perform far more complex computations than those described here. While many of the basic functions have been highlighted in this quick start guide, we’re also developing a more complete Player’s Manual, which will cover every aspect of the game in greater detail. The Player’s Manual is available to our sponsors who support this project with a monthly contribution of $2 or more.  If you like what we’re doing with Ex Machinis, please consider following us on Patreon and lending your support to the project.</p>

                    </div>
                </div>
            </div>
    </section>
@endsection
