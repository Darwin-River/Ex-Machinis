@extends('layouts.app')
@section('pageTitle', 'FORTH Dictionary')
@section('content')
    <!-- Breadcrumbs-->
    <section class="breadcrumbs-modern box-transform-wrap bg-image context-dark"
             style="background-image: url('/images/bg-breadcrumbs.jpg');">
        <div class="container">
            <div class="breadcrumbs-modern-body">
                <h5 class="breadcrumbs-modern-subtitle"></h5>
                <h1 class="breadcrumbs-modern-title">Ex Machinis Player's Manual</h1>
            </div>
            <ul class="breadcrumbs-path breadcrumbs-modern-path">
                <li><a href="/">Home</a></li>
                <li class="active">Ex Machinis Player's Manual</li>
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

                        <p class="has-line-data" data-line-start="2" data-line-end="3">The Player’s Manual provides a
                            detailed description of available game-play mechanics and strategies. This manual will grow
                            with the game. As more features are added, they will be introduced below. Currently, the
                            manual focuses primarily on coding spacecraft with the fully developed FORTH interpreter.
                            However, as we develop the more physical components of Ex Machinis game play more details
                            will be provided on navigation, resource extraction, refining, and production. Thank you for
                            your support and please stay tuned for future developments!</p>
                        <p class="has-line-data" data-line-start="4" data-line-end="5"><strong>CAUTION:</strong> This
                            version of the Player’s Manual pertains to the development server, which is located at <a
                                    href="http://advolition.com">advolition.com</a>. You’re welcome to use the
                            development server to play-test the latest features. However, there’s a chance your player
                            account will be corrupted or destroyed as we frequently update the system.</p>
                        <h2 class="code-line" data-line-start=6 data-line-end=7><a id="Table_of_Contents_6"></a>Table of
                            Contents</h2>
                        <ul>
                            <li class="has-line-data" data-line-start="7" data-line-end="10"><a href="#Overview">Overview</a>
                                <ul>
                                    <li class="has-line-data" data-line-start="8" data-line-end="9"><a
                                                href="#Your-fortune-awaits">Your fortune awaits!</a></li>
                                    <li class="has-line-data" data-line-start="9" data-line-end="10"><a
                                                href="#We-still-have-light-years-to-go">We still have light years to
                                            go</a></li>
                                </ul>
                            </li>
                            <li class="has-line-data" data-line-start="10" data-line-end="15"><a
                                        href="#Getting-started">Getting started</a>
                                <ul>
                                    <li class="has-line-data" data-line-start="11" data-line-end="12"><a
                                                href="#Requesting-your-spacecraft">Requesting your spacecraft</a></li>
                                    <li class="has-line-data" data-line-start="12" data-line-end="13"><a
                                                href="#Contacting-your-spacecraft">Contacting your spacecraft</a></li>
                                    <li class="has-line-data" data-line-start="13" data-line-end="14"><a
                                                href="#Choosing-better-names">Choosing better names!</a></li>
                                    <li class="has-line-data" data-line-start="14" data-line-end="15"><a
                                                href="#Commanding-your-fleet">Commanding your fleet</a></li>
                                </ul>
                            </li>
                            <li class="has-line-data" data-line-start="15" data-line-end="27"><a href="#Learning-FORTH">Learning
                                    FORTH</a>
                                <ul>
                                    <li class="has-line-data" data-line-start="16" data-line-end="17"><a
                                                href="#Doing-Simple-Math">Doing Simple Math</a></li>
                                    <li class="has-line-data" data-line-start="17" data-line-end="18"><a
                                                href="#Managing-the-stack">Managing the stack</a></li>
                                    <li class="has-line-data" data-line-start="18" data-line-end="19"><a
                                                href="#Building-a-dictionary">Building a dictionary</a></li>
                                    <li class="has-line-data" data-line-start="19" data-line-end="20"><a
                                                href="#Commenting-on-your-code">Commenting on your code</a></li>
                                    <li class="has-line-data" data-line-start="20" data-line-end="21"><a
                                                href="#Sending-greetings">Sending greetings</a></li>
                                    <li class="has-line-data" data-line-start="21" data-line-end="22"><a
                                                href="#Finding-the-truth-in-numbers">Finding the truth in numbers</a>
                                    </li>
                                    <li class="has-line-data" data-line-start="22" data-line-end="23"><a
                                                href="#Experimenting-with-logical-operators">Experimenting with logical
                                            operators</a></li>
                                    <li class="has-line-data" data-line-start="23" data-line-end="24"><a
                                                href="#Using-variables-when-programming-your-drones">Using variables
                                            when programming your drones</a></li>
                                    <li class="has-line-data" data-line-start="24" data-line-end="25"><a
                                                href="#Whats-really-happening-with-variables">What’s really happening
                                            with variables?</a></li>
                                    <li class="has-line-data" data-line-start="25" data-line-end="27"><a
                                                href="#Working-with-strings">Working with strings</a></li>
                                </ul>
                            </li>
                        </ul>
                        <h2 class="code-line" data-line-start=27 data-line-end=28><a id="Overview_27"></a>Overview</h2>
                        <h3 class="code-line" data-line-start=29 data-line-end=30><a id="Your_fortune_awaits_29"></a>Your
                            fortune awaits!</h3>
                        <p class="has-line-data" data-line-start="30" data-line-end="31">Ex Machinis is a multiplayer
                            space game, which takes place in a parallel universe. Within this altered reality, space
                            technologies have rapidly advanced since the 1950’s to the point where extensive
                            interplanetary industries exist to produce materials that drive the human economy back on
                            Earth. The vast majority of these spacecraft and are unmanned vehicles, which are assembled
                            in space and are controlled terrestrial barons.</p>
                        <p class="has-line-data" data-line-start="32" data-line-end="33">As part of the international
                            agreement that drove the colonization of space at the end of the second world war, citizens
                            of the signitor nations are guaranteed access to basic spacecraft which can be used to build
                            profitable businesses. This democratization of space has been the biggest driver behind the
                            rapid growth of extraterrestrial industries and technologies.</p>
                        <p class="has-line-data" data-line-start="34" data-line-end="35">It is your birthright to take
                            control of your own remotely piloted spacecraft and build a thriving stellar industry. But
                            this can only be accomplished by programming your spacecraft to efficiently mine,
                            manufacture, and trade materials on the interplanetary economy.</p>
                        <p class="has-line-data" data-line-start="36" data-line-end="37"><a href="#Table-of-Contents">Return
                                to TOC</a></p>
                        <h3 class="code-line" data-line-start=38 data-line-end=39><a
                                    id="We_still_have_light_years_to_go_38"></a>We still have light years to go</h3>
                        <p class="has-line-data" data-line-start="39" data-line-end="40">Ex Machinis is in the very
                            early stages of development but we want to make it available to potential players so they
                            can kick the tires and provide us with valuable feedback and suggestions.</p>
                        <p class="has-line-data" data-line-start="41" data-line-end="42">We currently have a functional
                            email server that allows players to create an account and code a fleet of three spacecraft
                            using a FORTH compiler. The spacecraft are able to process simple FORTH commands but can’t
                            execute any in game activities yet.</p>
                        <p class="has-line-data" data-line-start="43" data-line-end="44">Right now we’re developing the
                            Event Engine that will govern the physical actions of spacecraft. When the Event Engine
                            comes online, your drones will be able manufacture materials move from one location to
                            another.</p>
                        <p class="has-line-data" data-line-start="45" data-line-end="46">This player’s guide is a work
                            in progress. It will be updated as new features become available in the game. Please follow
                            us on <strong><a href="http://patreon.com/exmachinis">Patreon</a></strong> to receive
                            updates and find the latest version of this guide.</p>
                        <p class="has-line-data" data-line-start="47" data-line-end="48"><a href="#Table-of-Contents">Return
                                to TOC</a></p>
                        <h2 class="code-line" data-line-start=49 data-line-end=50><a id="Getting_started_49"></a>Getting
                            started</h2>
                        <h3 class="code-line" data-line-start=51 data-line-end=52><a
                                    id="Requesting_your_spacecraft_51"></a>Requesting your spacecraft</h3>
                        <p class="has-line-data" data-line-start="52" data-line-end="53">As an immersive multiplayer
                            space simulation, there’s no logging into or out of the game. Your assets are always in
                            play. To communicate with your spacecraft, simply send them emails and they will respond in
                            real time. Keep in mind that it will take longer for transmissions to reach spacecraft on
                            the outer edge of the solar system.</p>
                        <p class="has-line-data" data-line-start="54" data-line-end="55">To start playing, you need to
                            email the Registrar (<a href="mailto:registrar@advolition.com">registrar@advolition.com</a>)
                            with the word “register” in the subject line. You’ll receive a response from the registrar
                            listing the email addresses of three spacecraft that are under your control. These
                            spacecraft will only respond to communications from the email address you used to contact
                            the registrar.</p>
                        <p class="has-line-data" data-line-start="56" data-line-end="57">Also, please be assured that
                            we’ll only use your email to send in-game communications from your spacecraft or an
                            occasional newsletter summarizing updates to the game. Furthermore, you’ll only receive
                            emails from your spacecraft in response to your own communications and you can unsubscribe
                            from the newsletter at any time.</p>
                        <p class="has-line-data" data-line-start="58" data-line-end="59"><a href="#Table-of-Contents">Return
                                to TOC</a></p>
                        <h3 class="code-line" data-line-start=60 data-line-end=61><a
                                    id="Contacting_your_spacecraft_60"></a>Contacting your spacecraft</h3>
                        <p class="has-line-data" data-line-start="61" data-line-end="63">Most of the game is played via
                            direct email communications with your spacecraft. When one of your ships receives an email
                            from you it will interpret everything between the &lt;code&gt;&lt;run&gt;&lt;/code&gt; and
                            &lt;code&gt;&lt;/run&gt;&lt;/code&gt; brackets as code and respond to you accordingly via
                            email.<br>
                            For example, try sending the following text to one of your spacecraft and see what happens.
                            Just make sure you retain the spaces between the numbers, the plus sign, and the period,
                            which instructs the spacecraft to report the result.</p>
                        <pre><code class="has-line-data" data-line-start="64" data-line-end="66">&lt;run&gt; 2 3 + . &lt;/run&gt;
</code></pre>
                        <p class="has-line-data" data-line-start="67" data-line-end="68"><a href="#Table-of-Contents">Return
                                to TOC</a></p>
                        <h3 class="code-line" data-line-start=69 data-line-end=70><a id="Choosing_better_names_69"></a>Choosing
                            better names!</h3>
                        <p class="has-line-data" data-line-start="70" data-line-end="71">You’ll notice that each of your
                            spacecraft as a rather generic and unremarkable email address like <a
                                    href="mailto:SN523854@advolition.com">SN523854@advolition.com</a>. You’ll probably
                            want to give it a more memorable name like “hero” since you’ll be interacting with it on a
                            regular basis. To rename your spacecraft, simply send the following text in an email to your
                            drone:</p>
                        <pre><code class="has-line-data" data-line-start="72" data-line-end="74">&lt;rename&gt;hero&lt;/rename&gt;
</code></pre>
                        <p class="has-line-data" data-line-start="74" data-line-end="75">You’ll actually receive an
                            email from <a href="mailto:hero@advolition.com">hero@advolition.com</a> confirming the name
                            change. You can now send your instructions to this email address instead of the original!
                        </p>
                        <p class="has-line-data" data-line-start="76" data-line-end="77">There is no limit to the number
                            of times you change your drone’s email address. The only requirements are that you pick a
                            name that doesn’t contain any illegal characters and isn’t already in use by someone else.
                            If your chosen name is not allowed, the drone will let you know via its original email
                            address.</p>
                        <p class="has-line-data" data-line-start="78" data-line-end="79"><a href="#Table-of-Contents">Return
                                to TOC</a></p>
                        <h3 class="code-line" data-line-start=80 data-line-end=81><a id="Commanding_your_fleet_80"></a>Commanding
                            your fleet</h3>
                        <p class="has-line-data" data-line-start="81" data-line-end="82">Unlike most space games,
                            players succeed in Ex Machinis by avoiding the grind. Although it’s possible to send
                            individual commands to your drones, it’s far more efficient to develop advanced routines for
                            coordinating the actions of your multiple ships in distant space. Remember, the game doesn’t
                            stop when you’re AFK and you don’t want to wait for your communications to bounce back and
                            forth across the void.</p>
                        <p class="has-line-data" data-line-start="83" data-line-end="84">You’ll be controlling your
                            spacecraft via a simple but highly efficient programming language known as FORTH.</p>
                        <p class="has-line-data" data-line-start="85" data-line-end="86"><a href="#Table-of-Contents">Return
                                to TOC</a></p>
                        <h2 class="code-line" data-line-start=87 data-line-end=88><a id="Learning_FORTH_87"></a>Learning
                            FORTH</h2>
                        <p class="has-line-data" data-line-start="88" data-line-end="89">FORTH is an amazingly simple
                            but highly flexible programming language that grows out of a few core interpreter functions.
                            In fact, the version of FORTH, which is currently installed in each of your spacecraft, is
                            described in around 200 lines of game-side C code.</p>
                        <p class="has-line-data" data-line-start="90" data-line-end="91">This simple architecture has
                            encouraged many FORTH loyalists to develop their own interpreters and led others to muse
                            that a true FORTH master is one who builds his own interpreter from scratch.</p>
                        <p class="has-line-data" data-line-start="92" data-line-end="93">Consequently, FORTH will give
                            advanced Ex Machinis players a tremendous amount of flexibility in developing a programming
                            environment that works for them. However, it also means that beginners can start the game
                            with a more standard dictionary of intuitive FORTH routines, which facilitate their entry
                            into the game and it’s dynamic programming environment.</p>
                        <p class="has-line-data" data-line-start="94" data-line-end="95">This chapter is intended to
                            give new users a basic understanding of FORTH so that they can use it to remotely pilot
                            their spacecraft.</p>
                        <p class="has-line-data" data-line-start="96" data-line-end="97"><a href="#Table-of-Contents">Return
                                to TOC</a></p>
                        <h3 class="code-line" data-line-start=98 data-line-end=99><a id="Doing_Simple_Math_98"></a>Doing
                            Simple Math</h3>
                        <p class="has-line-data" data-line-start="99" data-line-end="100">You may have already noticed
                            that FORTH has a strange way of doing math. In FORTH, the numbers come first followed by the
                            operator (&lt;code&gt;2 3 +&lt;/code&gt;). This is because FORTH relies on a data stack to
                            transfer values to and from functions.</p>
                        <p class="has-line-data" data-line-start="101" data-line-end="102">For example, when you enter
                            &quot;2” and “3” seperatred by a space, FORTH places a 2 and then a 3 on the data stack. You
                            can visualize this as a stack of dishes in which the number 2 plate is laid down first and
                            the number 3 plate is placed on top of it. Consequently, when objects are removed from the
                            stack the topmost value is removed first (3) and then the next value (2).</p>
                        <p class="has-line-data" data-line-start="103" data-line-end="104">When you call a FORTH
                            function, like addition (&lt;code&gt;+&lt;/code&gt;), it removes the top two values from the
                            stack (2 and 3), adds them together, and returns the result (5) to the stack.</p>
                        <p class="has-line-data" data-line-start="105" data-line-end="106">Similarly, calling the print
                            function (&lt;code&gt;.&lt;/code&gt;), pulls a value off the stack (5) and adds it the
                            output stream, which is returned to you in an email as “5”.</p>
                        <p class="has-line-data" data-line-start="107" data-line-end="108">All FORTH functions behave
                            the same way, by removing and adding values to the stack. This is why FORTH is a stack-based
                            language. It’s also why FORTH is a very efficient and intuitive programming language.</p>
                        <p class="has-line-data" data-line-start="109" data-line-end="110">Understanding stacks is the
                            key to learning FORTH and programming kick-ass spacecraft in Ex Machinis.</p>
                        <p class="has-line-data" data-line-start="111" data-line-end="112"><a href="#Table-of-Contents">Return
                                to TOC</a></p>
                        <h3 class="code-line" data-line-start=113 data-line-end=114><a id="Managing_the_stack_113"></a>Managing
                            the stack</h3>
                        <p class="has-line-data" data-line-start="114" data-line-end="115">In FORTH, different program
                            elements share data by adding and removing values from this stack in a last-on-first-off
                            manner. For example, when I email the following code one of my spacecraft, it adds the
                            space-delimited values to the stack so that 1 is on the bottom of the stack and 4 is on
                            top.</p>
                        <pre><code class="has-line-data" data-line-start="116" data-line-end="120">&lt;run&gt;
  1 2 3 4
&lt;/run&gt;
</code></pre>
                        <p class="has-line-data" data-line-start="120" data-line-end="121">If I were to follow this with
                            a call to the addition function (&lt;code&gt;+&lt;/code&gt;), it would remove the 4 and 3
                            from the top of the stack and place a 7 on the stack. The stack from bottom to top would end
                            up looking like this: 1 2 7</p>
                        <p class="has-line-data" data-line-start="122" data-line-end="123">You may not always want to
                            deal with values in the order they were placed on the stack. Therefore, a number of commands
                            exist that allow you to directly manipulate the order and contents of the stack.</p>
                        <p class="has-line-data" data-line-start="124" data-line-end="125">Try applying each of the
                            following commands to manipulate the contents of the data stack in one of your
                            spacecraft:</p>
                        <table class="table table-striped table-bordered">
                            <thead>
                            <tr>
                                <th style="text-align:left">Word</th>
                                <th style="text-align:left">Action</th>
                                <th style="text-align:left">Example *</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td style="text-align:left">drop</td>
                                <td style="text-align:left">Discards the top most item on the stack.</td>
                                <td style="text-align:left">0 1 2 3 drop . . .</td>
                            </tr>
                            <tr>
                                <td style="text-align:left">nip</td>
                                <td style="text-align:left">Discards the second item on the stack.</td>
                                <td style="text-align:left">0 1 2 3 nip . . .</td>
                            </tr>
                            <tr>
                                <td style="text-align:left">dup</td>
                                <td style="text-align:left">Copies the top most item on the stack.</td>
                                <td style="text-align:left">0 1 2 3 dup . . .</td>
                            </tr>
                            <tr>
                                <td style="text-align:left">over</td>
                                <td style="text-align:left">Copies the second item on the stack the stack.</td>
                                <td style="text-align:left">0 1 2 3 over . . .</td>
                            </tr>
                            <tr>
                                <td style="text-align:left">swap</td>
                                <td style="text-align:left">Switches to order of the top two items on the stack.</td>
                                <td style="text-align:left">0 1 2 3 swap . . .</td>
                            </tr>
                            </tbody>
                        </table>
                        <p class="has-line-data" data-line-start="134" data-line-end="135">* The three dots in each of
                            these examples instruct the interpreter to print the first three items on the stack. It’s
                            important that you place a space between each of the periods and the word that precedes
                            them.</p>
                        <p class="has-line-data" data-line-start="136" data-line-end="137">When trying the above
                            examples, remember that FORTH words are case sensitive and it is important that you include
                            spaces between the numbers, words, and periods. It’s also important that you include all
                            FORTH script between &lt;code&gt;&lt;run&gt;&lt;/code&gt; and &lt;code&gt;&lt;/run&gt;&lt;/code&gt;.
                            Otherwise the interpreter won’t processes the code.</p>
                        <p class="has-line-data" data-line-start="138" data-line-end="139"><a href="#Table-of-Contents">Return
                                to TOC</a></p>
                        <h3 class="code-line" data-line-start=140 data-line-end=141><a
                                    id="Building_a_dictionary_140"></a>Building a dictionary</h3>
                        <p class="has-line-data" data-line-start="141" data-line-end="142">FORTH is a very simple
                            language. Most of the heavy lifting is done using the data stack and a dictionary of
                            user-defined functions.</p>
                        <p class="has-line-data" data-line-start="143" data-line-end="144">Every time you send your
                            spacecraft instructions enclosed between the script brackets, the spacecraft’s on-board
                            interpreter will try to parse your text into words or numbers.</p>
                        <p class="has-line-data" data-line-start="145" data-line-end="146">The interpreter will first
                            look for a string of space delimited characters in the onboard dictionary. If the characters
                            represent a previously defined word, the interpreter will execute the corresponding
                            function. If not, it will attempt to interpret the string as a value and place it on the
                            stack.</p>
                        <p class="has-line-data" data-line-start="147" data-line-end="148">For example, the arithmetic
                            operators (&lt;code&gt;+&lt;/code&gt;, &lt;code&gt;-&lt;/code&gt;, &lt;code&gt;*&lt;/code&gt;,
                            &lt;code&gt;/&lt;/code&gt;) represented words, which the interpreter can find in the
                            dictionary and execute to perform the proscribed computation.</p>
                        <p class="has-line-data" data-line-start="149" data-line-end="150">However, FORTH makes it very
                            easy to define your own words on the fly for use in scripts and higher level definitions.
                            New words are defined by simply enclosing the word and the corresponding script between a
                            colon (&lt;code&gt;:&lt;/code&gt;) and a semicolon (&lt;code&gt;;&lt;/code&gt;).</p>
                        <p class="has-line-data" data-line-start="151" data-line-end="152">For example, if you find that
                            you are frequently adding five to a number on the stack you can create the word, AddFive, to
                            handle this operation in the future. You would define AddFive by sending the following
                            script to your spacecraft:</p>
                        <pre><code class="has-line-data" data-line-start="153" data-line-end="157">&lt;run&gt;
  : AddFive 5 + ;
&lt;/run&gt;
</code></pre>
                        <p class="has-line-data" data-line-start="157" data-line-end="158">Now, anytime you want your
                            spacecraft to add five to the number on the stack you can use your new word instead of &lt;code&gt;5
                            +&lt;/code&gt;:</p>
                        <pre><code class="has-line-data" data-line-start="159" data-line-end="163">&lt;run&gt;
  4 AddFive .
&lt;/run&gt;
</code></pre>
                        <p class="has-line-data" data-line-start="163" data-line-end="164">This script will cause your
                            spacecraft to return the same result (“9”) as the following script:</p>
                        <pre><code class="has-line-data" data-line-start="165" data-line-end="169">&lt;run&gt;
  4 5 + .
&lt;/run&gt;
</code></pre>
                        <p class="has-line-data" data-line-start="169" data-line-end="170">In this example, the word
                            AddFive really doesn’t offer much advantage over simply typing &lt;code&gt;5 +&lt;/code&gt;.
                            However, you can easily imagine defining words that perform more complex functions that you
                            wouldn’t want to retype every time.</p>
                        <p class="has-line-data" data-line-start="171" data-line-end="172">Give this a try with your own
                            spacecraft! I’m sure you can come up with more useful definitions than the one provided
                            here.</p>
                        <p class="has-line-data" data-line-start="173" data-line-end="174"><a href="#Table-of-Contents">Return
                                to TOC</a></p>
                        <h3 class="code-line" data-line-start=175 data-line-end=176><a
                                    id="Commenting_on_your_code_175"></a>Commenting on your code</h3>
                        <p class="has-line-data" data-line-start="176" data-line-end="177">As with most programming
                            languages, FORTH offers for some convenient mechanisms for commenting on your code. This
                            techniques can be very handy in reminding yourself and others of the intentent and function
                            of your definitions. There are two primary ways to add comments to your script that will be
                            ignored by the interpreter:</p>
                        <p class="has-line-data" data-line-start="178" data-line-end="179">When you start a line with a
                            backslash (&lt;code&gt;\&lt;/code&gt;) the interpreter will ignore everything else on that
                            line.</p>
                        <p class="has-line-data" data-line-start="180" data-line-end="181">The interpreter will also
                            ignore any text enclosed between parentheses, regardless of where they occur in the line or
                            whether they enclose multiple lines.</p>
                        <p class="has-line-data" data-line-start="182" data-line-end="183">Note: Remember that the
                            interpreter uses white space to deliminate words. Therefore, backslashes and parentheses
                            will only be recognized as such by the interpreter if there are spaces between them and
                            other characters.</p>
                        <p class="has-line-data" data-line-start="184" data-line-end="185"><a href="#Table-of-Contents">Return
                                to TOC</a></p>
                        <h3 class="code-line" data-line-start=186 data-line-end=187><a id="Sending_greetings_186"></a>Sending
                            greetings</h3>
                        <p class="has-line-data" data-line-start="187" data-line-end="188">Having your spacecraft output
                            text in an email to you is actually quite easy to do and obviously very useful. There are a
                            couple ground rules though: First, this technique for printing quotes can only be called
                            from within a word definition (ie. function). Second, it’s critical that spaces separate
                            your quotes from the text that is contained between them.</p>
                        <p class="has-line-data" data-line-start="189" data-line-end="190">With that in mind, the
                            following definition creates a word that, when called, instructs the spacecraft to print a
                            specific string in its email response to you.</p>
                        <p class="has-line-data" data-line-start="191" data-line-end="192">Start by emailed the
                            following text to one of your spacecraft:</p>
                        <pre><code class="has-line-data" data-line-start="193" data-line-end="197">&lt;run&gt;
  : helloworld .” Greetings Earthlings! “ ;
&lt;/run&gt;
</code></pre>
                        <p class="has-line-data" data-line-start="197" data-line-end="198">The spacecraft will respond
                            to this email with a simple “ok” to acknowledge that it has received the new definition and
                            incorporated it into its on-board dictionary. Remember, the spaces in the above example are
                            very important since the allow the interpreter to delineate words and numbers.</p>
                        <p class="has-line-data" data-line-start="199" data-line-end="200">Next, envoke your new word by
                            sending the following email to your spacecraft:</p>
                        <pre><code class="has-line-data" data-line-start="201" data-line-end="203">&lt;run&gt; helloworld &lt;/run&gt;
</code></pre>
                        <p class="has-line-data" data-line-start="203" data-line-end="204">Sending this script to your
                            drone will result in a heartfelt greeting from your favorite spacecraft! I guess there’s no
                            surprises there. But this will obviously be a useful tool when having your ships provide
                            intelligible status reports.</p>
                        <p class="has-line-data" data-line-start="205" data-line-end="206">The important components of
                            this command are the dot-quote (&lt;code&gt;.&quot;&lt;/code&gt;), which instructs the craft
                            to print the text that follows and the end-quote (&lt;code&gt;&quot;&lt;/code&gt;), which
                            ends the printed string. Just remember that because the FORTH interpreter views the dot and
                            end quotes as distinct words, they must be separated from everything around them by a
                            white-space.</p>
                        <p class="has-line-data" data-line-start="207" data-line-end="208"><a href="#Table-of-Contents">Return
                                to TOC</a></p>
                        <h3 class="code-line" data-line-start=209 data-line-end=210><a
                                    id="Finding_the_truth_in_numbers_209"></a>Finding the truth in numbers</h3>
                        <p class="has-line-data" data-line-start="210" data-line-end="211">FORTH is very loose with
                            numbers and doesn’t have specially defined boolean values. Rather, logical operators
                            interpret all non-zero numbers as true. Only zero is used to represent the boolean
                            equivalent of false.</p>
                        <p class="has-line-data" data-line-start="212" data-line-end="214">A good way to demonstrate
                            this and introduce you to simple branching structures is by defining a new word, which uses
                            an if-else-then statement to make a value judgement about any number that precedes it.<br>
                            Try emailing the following text to one of your spacecraft:</p>
                        <pre><code class="has-line-data" data-line-start="215" data-line-end="227">&lt;run&gt;

  ( define true? )
  : true? if .&quot; true &quot; else .&quot; false &quot; then ;

  ( test true? )
  -1 true?
   0 true?
   1 true?

&lt;/run&gt;
</code></pre>
                        <p class="has-line-data" data-line-start="227" data-line-end="228">Remember that spaces are very
                            important, so start by copying and pasting the above script directly into your email,
                            including the &lt;code&gt;&lt;run&gt; &lt;/run&gt;&lt;/code&gt; brackets.</p>
                        <p class="has-line-data" data-line-start="229" data-line-end="230">The first part of the emailed
                            script defines a new word, true?, which will return a “true” if the word is preceded by a
                            number other than zero and a “false” if it follows a zero. In the FORTH syntax, if begins a
                            branched statement, which implements one of two else-delimited options based on the value on
                            the top of the stack. The word, then, closes the statement.</p>
                        <p class="has-line-data" data-line-start="231" data-line-end="233">The second part of this
                            statement invokes true? after placing each of three different values (-1, 0, and 1) on the
                            stack. This will cause your spacecraft to evaluate each of the numbers as a boolean value
                            and respond accordingly.<br>
                            After sending the above code to your spacecraft, you should receive an email from your
                            drone, which contains the following response:</p>
                        <pre><code class="has-line-data" data-line-start="234" data-line-end="236">true false true ok.
</code></pre>
                        <p class="has-line-data" data-line-start="236" data-line-end="237">By the way, once you define a
                            word like true?, your spacecraft will remember it, allowing you to use it in future emails.
                            So, true evaluating some other integers with the word.</p>
                        <p class="has-line-data" data-line-start="238" data-line-end="239">We’ll be using true? in the
                            next section to explore logical (&lt;code&gt;and&lt;/code&gt;, &lt;code&gt;or&lt;/code&gt;,
                            &lt;code&gt;xor&lt;/code&gt;) and comparison (&lt;code&gt;&lt;&lt;/code&gt;, &lt;code&gt;&gt;&lt;/code&gt;,
                            &lt;code&gt;=&lt;/code&gt;) operators.</p>
                        <p class="has-line-data" data-line-start="240" data-line-end="241"><a href="#Table-of-Contents">Return
                                to TOC</a></p>
                        <h3 class="code-line" data-line-start=242 data-line-end=243><a
                                    id="Experimenting_with_logical_operators_242"></a>Experimenting with logical
                            operators</h3>
                        <p class="has-line-data" data-line-start="244" data-line-end="245">In the previous section, we
                            used an if else then statement to demonstrate how FORTH, which is used to program
                            player-controlled spacecraft in Ex Machinis, loosely interprets 0 as false and any other
                            number as true. In the process, we defined the word true?, which returns “false” if the
                            value on the stack is zero and “true” if it is any other number.</p>
                        <p class="has-line-data" data-line-start="246" data-line-end="247">If you haven’t already done
                            so, you can define the word true? by emailing the following script to your spacecraft.</p>
                        <pre><code class="has-line-data" data-line-start="248" data-line-end="252">&lt;run&gt;
  : true? if .&quot; true &quot; else .&quot; false &quot; then ;
&lt;/run&gt;
</code></pre>
                        <p class="has-line-data" data-line-start="252" data-line-end="253">It turns out that FORTH has
                            three logical operators (&lt;code&gt;and&lt;/code&gt;, &lt;code&gt;or&lt;/code&gt;, &lt;code&gt;xor&lt;/code&gt;)
                            which allow you to combine Boolean values in different ways. Therefore, if we use 0 for
                            false and 1 for true, you can test these operators by sending the following script to your
                            drone:</p>
                        <pre><code class="has-line-data" data-line-start="254" data-line-end="260">&lt;run&gt;
  0 1 and true?
  0 1 or true?
  0 1 xor true?
&lt;/run&gt;
</code></pre>
                        <p class="has-line-data" data-line-start="260" data-line-end="261">You may not recall that FORTH
                            uses a Reverse Polish Notation (RPN) in which you enter the numbers first (i.e. “0 1”)
                            before the function or mathematical operator (&lt;code&gt;and&lt;/code&gt;), which processes
                            them. Finally, we need to use our previously defined word, true?, to report back the value
                            of the number. Since “0 and 1” is false, “0 or 1” is true, and “0 xor 1” is also true, your
                            drone will respond to the above script with:</p>
                        <pre><code class="has-line-data" data-line-start="262" data-line-end="264">true true true ok.
</code></pre>
                        <p class="has-line-data" data-line-start="264" data-line-end="265">Using the above example,
                            instruct you spacecraft to process other combinations of 0’s and 1’s with and, or, and xor
                            to make sure these logical operators behave as expected.</p>
                        <p class="has-line-data" data-line-start="266" data-line-end="267">You may have noticed that
                            there is one very important operator that is missing from the version of FORTH that is
                            currently running on your spacecraft. There is no not operator. However, the beauty of FORTH
                            is that you can easily define your own not operator as follows:</p>
                        <pre><code class="has-line-data" data-line-start="268" data-line-end="275">&lt;run&gt;
  : not 1 xor ; ( define not )

  0 not true? ( see how not works on 0 )
  1 not true? ( see how not works on 1 )
&lt;/run&gt;
</code></pre>
                        <p class="has-line-data" data-line-start="275" data-line-end="276">Try sending the above code to
                            your spacecraft to see how it work.</p>
                        <p class="has-line-data" data-line-start="277" data-line-end="278"><a href="#Table-of-Contents">Return
                                to TOC</a></p>
                        <h3 class="code-line" data-line-start=279 data-line-end=280><a
                                    id="Using_variables_when_programming_your_drones_279"></a>Using variables when
                            programming your drones</h3>
                        <p class="has-line-data" data-line-start="280" data-line-end="281">In FORTH, most values are
                            stored and passed between program elements using the stack. However, the language also
                            allows users to define simple variables using the &lt;code&gt;variable&lt;/code&gt;
                            command.</p>
                        <p class="has-line-data" data-line-start="282" data-line-end="284">When a variable is declared,
                            the FORTH interpreter creates an entry in the dictionary using the variable’s name. This new
                            definition serves as a placeholder for any values stored in that variable.<br>
                            Values can then be stored in and retrieved from a variable using the store (&lt;code&gt;!&lt;/code&gt;)
                            and fetch (&lt;code&gt;@&lt;/code&gt;) commands. In the following example, we define a
                            variable called “box” and then store, fetch, and print the number 2 using this variable.</p>
                        <pre><code class="has-line-data" data-line-start="285" data-line-end="291">&lt;run&gt;
    variable box ( Define the box variable )
    2 box ! ( Store the number two in the variable )
    box @ . ( Retrieve the contents and print the value )
&lt;/run&gt;
</code></pre>
                        <p class="has-line-data" data-line-start="291" data-line-end="292">That’s all there is to it!
                            Try running this script and variations of it on one of your drones.</p>
                        <p class="has-line-data" data-line-start="293" data-line-end="294">Of course, like most things
                            in FORTH, there are some interesting things going on behind the scenes when you declare a
                            variable and store and retrieve values from it. We’ll get into some of this in the next
                            section when we address the creation of arrays.</p>
                        <p class="has-line-data" data-line-start="295" data-line-end="296"><a href="#Table-of-Contents">Return
                                to TOC</a></p>
                        <h3 class="code-line" data-line-start=297 data-line-end=298><a
                                    id="Whats_really_happening_with_variables_297"></a>What’s really happening with
                            variables?</h3>
                        <p class="has-line-data" data-line-start="298" data-line-end="299">So let’s talk a little bit
                            about what’s happening behind the scenes with variables. When you declare a variable with
                            the statement, “&lt;code&gt;variable box&lt;/code&gt;”, you’re essentially telling the FORTH
                            interpreter to define a new word for you called box. This definition tells the interpreter
                            that whenever box is called, it should place an address on the stack, which points to a four
                            byte block at the end of the definition. This location is where your data is stored.</p>
                        <p class="has-line-data" data-line-start="300" data-line-end="301">You can confirm this is
                            happening by calling &lt;code&gt;box&lt;/code&gt; and then printing the contents of the
                            stack. When you do so, your drone will return the address of the place in memory where your
                            value is being stored. The variable, &lt;code&gt;box&lt;/code&gt;, will always return the
                            same value no matter what’s actually being stored in the variable.</p>
                        <pre><code class="has-line-data" data-line-start="302" data-line-end="306">&lt;run&gt;
    box . ( Print the address that is returned by box )
&lt;/run&gt;
</code></pre>
                        <p class="has-line-data" data-line-start="306" data-line-end="307">With this in mind, consider
                            that the true functions of fetch (&lt;code&gt;@&lt;/code&gt;) and store (&lt;code&gt;!&lt;/code&gt;)
                            are to read and write values to a specified location in memory. Fetch works by pulling an
                            address off the stack and returning the four-byte value that is stored in that location.
                            Store pulls an address and then a value from the stack and writes the value to the
                            designated memory slot.</p>
                        <p class="has-line-data" data-line-start="308" data-line-end="309">Fetch and store are very
                            powerful (and dangerous!) functions because they allow you to read from and write to ANY
                            location in your drone’s memory! However, until you’re sure of what you are doing, I suggest
                            that you only use these functions in conjunction with a previously defined variable like
                            box.</p>
                        <p class="has-line-data" data-line-start="310" data-line-end="311">So why am I telling you about
                            these functions now? Because understanding how FORTH uses variables is the key to defining
                            and using more powerful strings and arrays!</p>
                        <p class="has-line-data" data-line-start="312" data-line-end="313"><a href="#Table-of-Contents">Return
                                to TOC</a></p>
                        <h3 class="code-line" data-line-start=314 data-line-end=315><a
                                    id="Working_with_strings_314"></a>Working with strings</h3>
                        <p class="has-line-data" data-line-start="315" data-line-end="316">It’s important to understand
                            how stings are formed and managed in FORTH, because your drone will use them to query to
                            database about information on its environment and to report information back to you.</p>
                        <p class="has-line-data" data-line-start="317" data-line-end="318">In FORTH, strings of
                            chacrters are stored in a memory structure known as a counted string. In this structure, the
                            first byte of memory holds a value that indicates the size of the string and subesquent
                            bytes hold individual characters from that string.</p>
                        <p class="has-line-data" data-line-start="319" data-line-end="320">Strings are created using the
                            &lt;code&gt;$&quot;&lt;/code&gt; word, which stores everything up to the next quotation mark
                            in memory and returns an address, which points to the location of the string.</p>
                        <p class="has-line-data" data-line-start="321" data-line-end="322">As with the word, &lt;code&gt;.&quot;&lt;/code&gt;,
                            which was introduced above, &lt;code&gt;$&quot;&lt;/code&gt; can only be used within another
                            definition. However, rather than forcing the string to be added to the output buffer, it
                            causes a pointer to the string to be placed on the stack.</p>
                        <p class="has-line-data" data-line-start="323" data-line-end="324">Thus, a string can be stored
                            in a word via a simple definition:</p>
                        <pre><code class="has-line-data" data-line-start="325" data-line-end="329">&lt;run&gt;
  : myhome $&quot; Earth&quot; ;
&lt;/run&gt;
</code></pre>
                        <p class="has-line-data" data-line-start="329" data-line-end="330">This definition creates a
                            word called &lt;code&gt;myhome&lt;/code&gt;. When this word is used in another script, it
                            simply places an address on the stack which points to the location of the string “Earth”.
                            Since this creates a counted string, the section of memory that is referenced by the address
                            would look like this:</p>
                        <table class="table table-striped table-bordered">
                            <thead>
                            <tr>
                                <th style="text-align:center">Address</th>
                                <th style="text-align:center">Value/Chracter</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td style="text-align:center">a</td>
                                <td style="text-align:center">5</td>
                            </tr>
                            <tr>
                                <td style="text-align:center">a + 1</td>
                                <td style="text-align:center">E</td>
                            </tr>
                            <tr>
                                <td style="text-align:center">a + 2</td>
                                <td style="text-align:center">a</td>
                            </tr>
                            <tr>
                                <td style="text-align:center">a + 3</td>
                                <td style="text-align:center">r</td>
                            </tr>
                            <tr>
                                <td style="text-align:center">a + 4</td>
                                <td style="text-align:center">t</td>
                            </tr>
                            <tr>
                                <td style="text-align:center">a + 5</td>
                                <td style="text-align:center">h</td>
                            </tr>
                            </tbody>
                        </table>
                        <p class="has-line-data" data-line-start="340" data-line-end="341">There are many ways to access
                            this string, which we’ll cover at a later point. But one important way to do so is by using
                            the words &lt;code&gt;count&lt;/code&gt; and &lt;code&gt;type&lt;/code&gt; to print out the
                            string. The word &lt;code&gt;count&lt;/code&gt; functions by returning the first byte of an
                            address to the stack. In the case of a counted string, this would be the size of the string.
                            The word &lt;code&gt;type&lt;/code&gt; pulls an address and a lendth from the stack and
                            prints out the contents of that memory range as characters.</p>
                        <p class="has-line-data" data-line-start="342" data-line-end="343">Consequently, the following
                            script will istruct your drone to print the string which is pointed to by the previously
                            defined word:</p>
                        <pre><code class="has-line-data" data-line-start="344" data-line-end="348">&lt;run&gt;
  myhome count type
&lt;/run&gt;
</code></pre>
                        <p class="has-line-data" data-line-start="348" data-line-end="349">Assuming that you’ve already
                            added &lt;code&gt;home&lt;/code&gt; to your drone’s dictionary, running the above script
                            will generate an email from your drone that says “Earth”.</p>


                    </div>
                </div>
            </div>
    </section>
@endsection