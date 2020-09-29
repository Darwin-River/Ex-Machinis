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

                        <p>Ex Machinis is a multiplayer space game in which expansive interplanetary industries compete to produce materials for human consumption. The vast majority of these spacecraft are self-replicating unmanned drones, which are assembled in space and controlled by terrestrial barons.</p>

                        <p>As a citizen of the international alliance, it's your birthright to take control of your own remotely piloted drones and build a thriving interplanetary business. But this can only be accomplished by programming your ships to ruthlessly mine, manufacture, trade, and reproduce in the stellar economy.</p>


                        <br/><h3 id="h3_joining_the_game">Joining the game</h3>

                        <p>When you <a href="http://patreon.com/exmachinis">sponsor this project</a> and register with Ex Machinis, you'll receive an email listing the addresses of three spacecraft that are under your control. These drones will only respond to communications from the email address.</p>

                        <br/><h3 id="h3_renaming_your_spacecraft">Renaming your spacecraft</h3>

                        <p>You’ll notice that each of your spacecraft has a rather generic email address like SN523854@<?php echo  getenv("MAIL_HOST") ?>.</p>

                        <p>To give your spacecraft a more memorable name, simply send the following text in an email to your drone:</p>

<pre><code>&lt;rename&gt;NEW_NAME&lt;/rename&gt;</code></pre>

                        <p>If successful, you'll receive a response from your renamed drone confirming the change.</p>

                        <br/><h3 id="h3_rebranding_your_company">Rebranding your company</h3>

                        <p>You can also change your company name by emailing a rebrand instruction to any one of your drones:</p>

<pre><code>&lt;rebrand&gt;NEW_NAME&lt;/rebrand&gt;</code></pre>

                        <p>This will immediately change the name of your company in the <a href="https://<?php echo  getenv("MAIL_HOST") ?>/companies">company directory</a>.</p>

                        <br/><h3 id="h3_finding_your_information_online">Finding your information online</h3>

                        <p>Now that you’ve registered with Ex Machinis and renamed your company, you can easily find your information online.</p>

                        <p>The <a href="https://<?php echo  getenv("MAIL_HOST") ?>/companies">company directory</a> provides a searchable list of all player-owned companies, including your own.</p>

                        <p>If you find and click on your company name, you’ll be taken to a seperate page that tells you more about the company and its current fleet of spacecraft.</p>

                        <p>Similarly, the hyperlinked spacecraft list takes you to important information about your individual drones, including their locations, the contents of their cargo holds, and the most recent events that affected them.</p>

                        <br/><h3 id="h3_sending_your_first_command">Traveling to Earth</h3>

                        <p>Most of the game is played via direct email communications with your spacecraft.</p>

                        <p> When one of your ships receives an email from you it will interpret everything between the &lt;run&gt; &lt;/run&gt; brackets as FORTH code and respond to you accordingly via email.</p>

                        <p>For example, you can instructing one of your drones to fly to Earth orbit by sending it the following text:</p>

<pre><code>&lt;run&gt; 3 5 perform &lt;/run&gt;</code></pre>

                        <p>Soon after sending this message to your drone, you’ll receive an emailed response, which confirms that the command was successfully completed and that your spacecraft is now at Earth!</p>

                        <br/><h3 id="h3_flying_to_different_locations">Visiting other locations</h3>

                        <p>In this above example, the perform command used the preceding numbers as parameters that instructed your spacecraft to fly to Earth.</p>

                        <p>Specifically, the number 3 is the Location ID for the Earth and the number 5 is Protocol ID for the procedure that tells your spacecraft to move to a new location. </p>

                        <p>You can find a searchable list of all in-game locations and their corresponding IDs on the <a href="https://<?php echo  getenv("MAIL_HOST") ?>/locations">locations page</a>.  Direct your drone fly to any of these locations by replacing the number 3 with the Location ID of your new destination and reissue the command.</p>

                        <br/><h3 id="h3_mining_minerals">Mining Minerals</h3>

                        <p>Most locations have minerals that can be mined and brought onboard your spacecraft.</p>

                        <p>If you goto the <a href="http://<?php echo  getenv("MAIL_HOST") ?>/locations">locations page</a> and pull up information on your current location, you’ll see a list of available minerals with their abundances and Resource IDs.</p>

                        <p>Next, you can goto the <a href="http://<?php echo  getenv("MAIL_HOST") ?>/protocols">protocols page</a> and search for the protocol that allows you to mine this mineral.  Use the Protocol ID to instruct your spacecraft to mine the resource.</p>

                        <p>For example, the following script commands your drone to fly to Mars (Location ID 4) and mine the mineral Neptine (Protocol ID 11000) from the planet’s surface:</p>

<pre><code>&lt;run&gt; 4 5 perform 11000 perform &lt;/run&gt;</code></pre>

                        <p>If you access your spacecraft’s online data sheet after executing this command, you should see the new resource in your drone’s cargo hold and a corresponding entry in the events table that logs the acquisition of the material.</p>

                        <br/><h3 id="h3_selling_your_resources">Selling your resources</h3>

                        <p>Soon, Ex Machinis will allow you to extract metals and other compounds from the minerals and use these to build increasingly complex parts, systems, and modules for your spacecraft.</p>

                        <p>At any point along the way you can sell your products for a profit.</p>

                        <p>When you send the following command to your spacecraft, it instructs your drone to place a sell order (Protocol ID = 7) for all (reserve = 0) the Neptine (Resource ID = 1000) in your cargo hold at 10 credits per unit.</p>

<pre><code>&lt;run&gt; 10 0 1000 7 perform &lt;/run&gt;</code></pre>

                        <p>After placing this order, you'll be able to find your <a href="http://<?php echo  getenv("MAIL_HOST") ?>/sells">sell order</a> listed on the open market.  This will allow other players to remove Neptine from your cargo hold at the indicated price.</p>

                        <br/><h3 id="h3_defining_new_words">Defining new words</h3>

                        <p>FORTH makes it very easy to define and apply new functions on the run. In FORTH, functions are known as words and they'll be added to your spacecraft's dictionary as soon as you define them in an email. Once in the dictionary, they can be invoked in any future script you send to your drone.</p>

                        <p>A FORTH definition consists of a colon followed by the word you're defining and then the values and words that comprise the definition. The definition ends with a semicolon. Here's an example of how you can define a word that returns your spacecraft to Earth.</p>

<pre><code>&lt;run&gt; : return_home 3 5 perform ; &lt;/run&gt;</code></pre>

                        <p>Now, whenever you want to call your spacecraft back to Earth you simply need to send it this command.</p>

<pre><code>&lt;run&gt; return_home &lt;/run&gt;</code></pre>

                        <br/><h3 id="h3_passing_external_parameters">Passing external parameters to a word</h3>

                        <p>FORTH also allows you to pass missing parameters to a word by placing them in front of the word</p>

                        <p>For example, suppose you wanted to define a more general movement command. You could create a new word that invokes the movement protocol but leaves the location unspecified:</p>

<pre><code>&lt;run&gt; : go_there 5 perform ; &lt;/run&gt;</code></pre>

                        <p>Now you can send your spacecraft to Jupiter (Object ID = 5), or any other location, by placing the destination's Location ID in front of your new command:</p>

<pre><code>&lt;run&gt; 5 go_there &lt;/run&gt;</code></pre>

<br/><h3 id="h3_getting_out_of_trouble">Getting out of trouble</h3>
<p>It's definately possible to break your drone by sending it code that puts it into an endless loop or damages an important part of its memory. In these cases your spacecraft will become completely undresponsive to subsequent &lt;run&gt; commands. However, it's not hard to recover from these situations if you know what to do.</p>

<p>Sending "&lt;reset&gt;" to your drone will not only stop any active scrpts, it will also reset your spacecraft's internal memory to its "factory setting," erasing any damage you might have done to its internal memory. This metacommand will also erase any words you defined earlier.  But sometimes its good to start with a clean slate.</p>

<br/><h3>Learning more about the game</h3>
<p>Your spacecraft can perform far more complex computations, operations, and actions than those described here. Although we've highlighted a few of the most common functions in this quick start guide, we’re also developing a more complete Player’s Manual, which covers every aspect of the game and the FORTH programming environment.</p>

                    </div>
                </div>
            </div>
    </section>
@endsection
