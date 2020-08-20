@extends('layouts.app')
@section('pageTitle', 'FORTH Dictionary')
@section('content')
    <!-- Breadcrumbs-->
    <section class="breadcrumbs-modern box-transform-wrap bg-image context-dark"
             style="background-image: url(/images/bg-breadcrumbs.jpg);">
        <div class="container">
            <div class="breadcrumbs-modern-body">
                <h5 class="breadcrumbs-modern-subtitle"></h4><p>
                <h1 class="breadcrumbs-modern-title">FORTH Dictionary</h1>
            </div>
            <ul class="breadcrumbs-path breadcrumbs-modern-path">
                <li><a href="/">Home</a></li>
                <li class="active">FORTH Dictionary</li>
            </ul>
        </div>
        <div class="box-transform" style="background-image: url(/images/bg-breadcrumbs.jpg);"></div>
        <div class="comets comets-left"></div>
        <div class="comets comets-center"></div>
        <div class="comets comets-top"></div>
    </section>
    <section class="section section-lg bg-default">
        <div class="container">
            <div class="row">
                <div class="col-md-12 col-lg-10 col-xl-9 single-post">
                    <div class="post-big">

                        <h1>Overview</h1>
                        <p>This dictionary was developed and adapted from the one provided by Richard Howel with his
                            <a href="https://github.com/howerj/libforth">libforth C library</a>. Each word is also given
                            with its
                            effect on the variable stack, any other effects are documented (including the effects on
                            other stacks). Each entry looks like
                            this:</p>

                        <br/><h4>word ( y -- z )</h4><p>
                        <p>Where 'word' is the word being described, the contents between the parenthesis
                        describe the stack effects, this word expects one number to be one the stack,
                        'y', and returns a number to the stack 'z'.</p>

                        <p>
                            Some of the descriptions of the variables in the stack effect comment
                            have a meaning:
                        </p>
                        <div class="table-custom-responsive">

                            <table class="table-custom table-custom-bordered">
                                <tr>
                                    <th>Name</th>
                                    <th>Meaning</th>
                                </tr>
                                <tr>
                                    <td>
                                        addr
                                    </td>
                                    <td>
                                        An address in cells
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        c-addr
                                    </td>
                                    <td>
                                        Character address
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        r-addr
                                    </td>
                                    <td>
                                        A raw address
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        file-id
                                    </td>
                                    <td>
                                        A file id, used as a handle for file operations
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        ior
                                    </td>
                                    <td>
                                        An error status returned by file operations
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        char
                                    </td>
                                    <td>
                                        Single byte / character
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        u
                                    </td>
                                    <td>
                                        An unsigned value
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        x
                                    </td>
                                    <td>
                                        A signed value
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        c" xxx"
                                    </td>
                                    <td>
                                        The word parses a word (not a stack effect)
                                    </td>
                                </tr>
                            </table>
                        </div>


                        <p>The addressing modes complicate certain operations of Forth words. A normal
                            Forth address, as passed to words like '!', '@', 'c@' and 'c!' are relative to
                            the beginning of the start of the Forth cores memory, or at register zero. '!'
                            and '@' take their addresses in cells (*addr*), whereas 'c@' and 'c!' take
                            their addresses in character address (*c-addr*).</p>

                        <p>Raw addresses are however normal addresses, as understood by [C][] programs
                            and the computer. Operations on raw addresses can possibly result in undefined
                            behavior, and all objects they may operate on that exists outside of the Forth
                            core cannot be guaranteed to exists after a core file has been reloaded (that
                            is pointers to objects outside of Forth core should not be saved for further
                            use). Raw-addresses are also in character, not cell, units.</p>

                        <p>In future revisions of Forth this memory addressing might be simplified, if a
                            simple way of doing this can be achieved.</p>

                        <br/>
                        <h1> Internal words</h1>

                        <p>There are three types of words.</p>

                        <br/>
                        <h2> Invisible words</h2>
                        <p>
                            These invisible words have no name but are used to implement the Forth. They
                            are all <i>immediate</i> words.</p>

                        <br/><h4>push ( -- x )</h4>
                        <p>
                            Push the next value in the instruction stream onto the variable stack, advancing
                            the instruction stream.
                        </p>


                        <br/><h4>const ( -- x )</h4>
                        <p>
                            Push the next location after this instruction, not the instruction stream, onto
                            the variable stack.
                        </p>


                        <br/><h4>run ( -- )</h4>
                        <p>
                            Save the current instruction stream pointer onto the return stack and set
                            the pointer instruction stream pointer to point to value after *run*.
                        </p>

                        <br/>
                        <h2>Immediate words</h2>
                        <p>
                            These words are named and are <i>immediate</i> words.
                        </p>

                        <br/><h4>: ( c" xxx" -- )</h4>
                        <p>
                            Read in a new word from the input stream and compile it into the dictionary.
                        </p>

                        <br/><h4>immediate ( -- )</h4>
                        <p>
                            Make the previously declared word immediate. Unlike in most Forth
                            implementations this is used after the words name is given not after the
                            final ';' has been reached.
                        </p>
                        <p>So:</p>
                        <br/>

                        <code>: word immediate ... ;</code>

                        <p>Instead of:</p>

                        <br/>

                        <code>: word ... ; immediate</code>
                        <br/>


                        <br/><h4>\\ ( c" \n" -- )</h4>
                        <p>
                            A comment, ignore everything until the end of the line.
                        </p>

                        <br/><h2>Compiling words</h2>

                        <br/><h4>read ( c" xxx" -- )</h4>
                        <p>
                            <i>read</i> is a complex word that implements most of the input interpreter,
                            it reads in a [Forth][] <i>word</i> (up to 31 characters), if this <i>word</i> is in
                            the <i>dictionary</i> it will either execute the word if we are in <i>command mode</i>
                            or compile a pointer to the executable section of the word if in <i>compile
                                mode</i>. If this <i>word</i> is not in the <i>dictionary</i> it is checked if it is a
                            number, if it is then in <i>command mode</i> we push this value onto the <i>variable
                                stack</i>, if in <i>compile mode</i> then we compile a <i>literal</i> into the <i>dictionary</i>.
                            If it is none of these we print an error message and attempt to read in a
                            new word.
                        </p>


                        <br/><h4>@ ( address -- x )</h4>
                        <p>
                            Pop an address and push the value at that address onto the stack.
                        </p>


                        <br/><h4>! ( x address -- )</h4>
                        <p>
                            Given an address and a value, store that value at that address.
                        </p>


                        <br/><h4>c@ ( char-address -- char )</h4>
                        <p>
                            Pop a character address and push the character value at that address onto the
                            stack. Note that this access is not checked for being within range of the
                            virtual machines memory, but it is still relative to the start address of
                            virtual machine memory.
                        </p>


                        <br/><h4>c! ( char char-address -- )</h4>
                        <p>
                            Given a character address, store a character value at that address, like 'c@'
                            the address is relative to the virtual machines starting address.
                        </p>


                        <br/><h4>- ( x y -- z )</h4>
                        <p>
                            Pop two values, subtract 'y' from 'x' and push the result onto the stack.
                        </p>


                        <br/><h4>+ ( x y -- z )</h4>
                        <p>
                            Pop two values, add 'y' to 'x' and push the result onto the stack.
                        </p>


                        <br/><h4>and ( x y -- z )</h4>
                        <p>
                            Pop two values, compute the bitwise 'AND' of them and push the result on to
                            the stack.
                        </p>


                        <br/><h4>or ( x y -- z )</h4>
                        <p>
                            Pop two values, compute the bitwise 'OR' of them and push the result on to
                            the stack.
                        </p>


                        <br/><h4>xor ( x y -- z )</h4>
                        <p>
                            Pop two values, compute the bitwise 'XOR' of them and push the result on to
                            the stack.
                        </p>


                        <br/><h4>invert ( x y -- z )</h4>
                        <p>
                            Perform a bitwise negation on the top of the stack.
                        </p>


                        <br/><h4>lshift ( x y -- z )</h4>
                        <p>
                            Pop two values, compute 'y' shifted by 'x' places to the left and push
                            the result on to the stack.
                        </p>


                        <br/><h4>rshift ( x y -- z )</h4>
                        <p>
                            Pop two values, compute 'y' shifted by 'x' places to the right and push
                            the result on to the stack.
                        </p>


                        <br/><h4>* ( x y -- z )</h4>
                        <p>
                            Pop two values, multiply them and push the result onto the stack.
                        </p>


                        <br/><h4>/ ( x y -- z )</h4>
                        <p>
                            Pop two values, divide 'x' by 'y' and push the result onto the stack. If 'y'
                            is zero and error message is printed and 'x' and 'y' will remain on the
                            stack, but execution will continue on as normal.
                        </p>


                        <br/><h4>u< ( x y -- z )</h4>
                        <p>
                            Pop two unsigned values, compare them (y < x) and push the result onto the
                            stack, the comparison will be unsigned.
                        </p>


                        <br/><h4>u> ( x y -- z )</h4>
                        <p>
                            Pop two values, compare them (y > x) and push the result onto the stack. The
                            comparison will be unsigned.
                        </p>


                        <br/><h4>_exit ( -- )</h4>
                        <p>
                            Pop the return stack and set the instruction stream pointer to that
                            value.
                        </p>


                        <br/><h4>exit ( -- )</h4>
                        <p>
                            This does the same as '_exit', the reason there are two exits instead
                            of one is so that the word 'see', defined in 'forth.fth' can differentiate
                            between an exit that occurs in the definition of a word, and one that occurs
                            at the end of a words definition. ('see' is a decompiler for Forth).
                        </p>


                        <p>For example:</p>

                        <br/>

                        <code>test 0 begin dup 10 > if exit then dup . cr 1+ again ;</code>

                        <p>Gets Compiled to:</p>

                        <div class="table-responsive">

                            <table class="table-bordered table" id="compiled_table">
                                <tr>
                                    <th>
                                        Address
                                    </th>
                                    <th colspan="5">
                                        Contents
                                    </th>
                                </tr>
                                <tr>
                                    <td>
                                        X
                                    </td>
                                    <td class="text-center">
                                        't'
                                    </td>
                                    <td class="text-center">
                                        'e'
                                    </td>
                                    <td class="text-center">
                                        's'
                                    </td>
                                    <td class="text-center">
                                        't'
                                    </td>
                                    <td>
                                        <div class="top-border">
                                            <span class="icon mdi mdi-arrow-left"></span> Start of Word
                                        </div>
                                    </td>

                                </tr>
                                <tr>
                                    <td>
                                        X+1
                                    </td>
                                    <td class="text-center">
                                        0
                                    </td>
                                    <td class="text-center">
                                        0
                                    </td>
                                    <td class="text-center">
                                        0
                                    </td>
                                    <td class="text-center">
                                        0
                                    </td>
                                    <td>
                                        NUL terminates 'test' string
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+2
                                    </td>
                                    <td class="text-center" colspan="4">
                                        Previous Word Pointer
                                    </td>
                                    <td>
                                        AKA 'PWD' field
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+3
                                    </td>
                                    <td class="text-center" colspan="4">
                                        MISC Field
                                    </td>
                                    <td>
                                        <span class="icon mdi mdi-arrow-left"></span> Execution Starts here
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+4
                                    </td>
                                    <td class="" colspan="4">
                                        Literal
                                    </td>
                                    <td rowspan="3">
                                        Literals a compiled as a pointer to a 'literal' word and the literal in the next
                                        field.
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+5
                                    </td>
                                    <td class="" colspan="4">
                                        0
                                    </td>
                                    <td>

                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+6
                                    </td>
                                    <td class="" colspan="4">
                                        Pointer to 'dup'
                                    </td>
                                    <td>

                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+7
                                    </td>
                                    <td class="" colspan="4">
                                        literal
                                    </td>
                                    <td>

                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+8
                                    </td>
                                    <td class="" colspan="4">
                                        10
                                    </td>
                                    <td>

                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+9
                                    </td>
                                    <td class="" colspan="4">
                                        Pointer to '>'
                                    </td>
                                    <td>

                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+10
                                    </td>
                                    <td class="" colspan="4">
                                        Pointer to 'branch?'
                                    </td>
                                    <td rowspan="6">
                                        'if' gets compiled to 'branch?' and '2' so it jumps over 'exit' if the previous
                                        test fails. This is encoded as the jump destination less one as an increment
                                        happens after the word is executed.
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+11
                                    </td>
                                    <td class="" colspan="4">
                                        2
                                    </td>
                                    <td>

                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+12
                                    </td>
                                    <td class="" colspan="4">
                                        Pointer to 'exit'
                                    </td>
                                    <td>

                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+13
                                    </td>
                                    <td class="" colspan="4">
                                        Pointer to 'dup'
                                    </td>
                                    <td>

                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+14
                                    </td>
                                    <td class="" colspan="4">
                                        Pointer to '.'
                                    </td>
                                    <td>

                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+15
                                    </td>
                                    <td class="" colspan="4">
                                        Pointer to 'cr'
                                    </td>
                                    <td>

                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+16
                                    </td>
                                    <td class="" colspan="4">
                                        Pointer to '1+'
                                    </td>
                                    <td>

                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+17
                                    </td>
                                    <td class="" colspan="4">
                                        Pointer to 'branch'
                                    </td>
                                    <td>

                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+18
                                    </td>
                                    <td class="" colspan="4">
                                        -12
                                    </td>
                                    <td>

                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        X+19
                                    </td>
                                    <td class="" colspan="4">
                                        Pointer to '_exit'
                                    </td>
                                    <td class="align-bottom">
                                        <div class="bottom-border">
                                            <span class="icon mdi mdi-arrow-left"></span> End of Word
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <p>The decompiler knows that the end of a word is demarcated by a pointer to
                            <b>\_exit</b>, and that pointers to <b>exit</b> can occur within the body of the
                            definition.</p>

                        <br/><h4>key ( -- char )</h4>
                        <p>
                            Get a value from the input and put it onto the stack.
                        </p>

                        <br/><h4>_emit ( char -- status )</h4>
                        <p>
                            Put a character to the output stream returning a success value.
                        </p>

                        <br/><h4>r> ( -- x )</h4>
                        <p>
                            Pop a value from the return stack and push it to the variable stack.
                        </p>

                        <br/><h4>>r ( x -- )</h4>
                        <p>
                            Pop a value from the variable stack and push it to the return stack.
                        </p>

                        <br/><h4>branch ( -- )</h4>
                        <p>
                            Jump unconditionally to the destination next in the instruction stream.
                        </p>

                        <br/><h4>?branch ( bool -- )</h4>
                        <p>
                            Pop a value from the variable stack, if it is zero the jump to the
                            destination next in the instruction stream, otherwise skip over it.
                        </p>

                        <br/><h4>. ( x -- status )</h4>
                        <p>
                            Pop a value from the variable stack and print it to the output either
                            as a ASCII decimal or hexadecimal value depending on the BASE register. A
                            return status is pushed onto the stack, greater or equal to zero is a success,
                            negative is a failure. Failure can occur because of an invalid base in the BASE
                            register, or because the output could not be written to.
                        </p>

                        <br/><h4>' ( -- )</h4>
                        <p>
                            Push the next value in the instruction stream onto the variable stack
                            and advance the instruction stream pointer over it.
                        </p>

                        <br/><h4>, ( x -- )</h4>
                        <p>
                            Write a value into the dictionary, advancing the dictionary pointer.
                        </p>

                        <br/><h4>= ( x y -- z )</h4>
                        <p>
                            Pop two values, perform a test for equality and push the result.
                        </p>

                        <br/><h4>swap ( x y -- y z )</h4>
                        <p>
                            Swap two values on the stack.
                        </p>

                        <br/><h4>dup ( x -- x x )</h4>
                        <p>
                            Duplicate a value on the stack.
                        </p>

                        <br/><h4>drop ( x -- )</h4>
                        <p>
                            Drop a value.
                        </p>

                        <br/><h4>over ( x y -- x y x )</h4>
                        <p>
                            Duplicate the value that is next on the stack.
                        </p>

                        <br/><h4>find ( -- execution-token )</h4>
                        <p>
                            Find a word in the dictionary pushing a pointer to that word onto the
                            variable stack.
                        </p>

                        <br/><h4>depth ( -- depth )</h4>
                        <p>
                            Push the current stack depth onto the stack, the value is the depth of the
                            stack before the depth value was pushed onto the variable stack.
                        </p>

                        <br/><h4>sp@ ( -- addr )</h4>
                        <p>
                            Push the address of the stack pointer onto the stack, before 'sp@ was
                            executed:
                        </p>

                        <p>
                            <code>1 2 sp@ . . .</code>
                            <br/>
                            Prints:
                            <br/>
                            <code>2 2 1</code>
                        </p>

                        <br/><h4>sp! ( addr -- )</h4>
                        <p>
                            Set the address of the stack pointer.
                        </p>

                        <br/><h4>clock ( -- x )</h4>
                        <p>
                            Push the difference between the startup time and now, in milliseconds. This
                            can be used for timing and implementing sleep functionality, the counter
                            will not increase the interpreter is blocking and waiting for input, although
                            this is implementation dependent.
                        </p>

                        <br/><h4>evaluator ( c-addr u 0 | file-id 0 1 -- x )</h4>
                        <p>
                            This word is a primitive used to implement 'evaluate' and 'include-file', it
                            takes a boolean to decide whether it will read from a file (1) or a string (0),
                            and then takes either a forth string, or a 'file-id'.
                        </p>

                        <br/><h4>system ( c-addr u -- status )</h4>
                        <p>
                            Execute a command with the systems command interpreter.
                        </p>

                        <br/><h4>raise ( u -- ??? )</h4>
                        <p>
                            Raises a signal, what happens after the signal is raised is undefined.
                        </p>

                        <br/><h4>date ( -- date )</h4>
                        <p>
                            Push the date onto the stack, the order of the arguments are:<br/>
                        </p>

                        <ul class="list-group">
                            <li> Is day light savings time?</li>
                            <li>Days since start of year</li>

                            <li>Week day
                            </li>
                            <li>Year
                            </li>
                            <li>Month
                            </li>
                            <li>Day of month
                            </li>
                            <li>Hour
                            </li>
                            <li>Minutes
                            </li>
                            <li>Seconds (note, this can include lead seconds!)
                            </li>
                        </ul>

                        <p>The time is in UTC time.</p>

                        <br/><h4>memory-copy ( r-addr1 r-addr2 u -- )</h4>
                        <p>
                            Operates on two raw addresses. Copy 'u' characters from r-addr2 to r-addr1.
                        </p>

                        <br/><h4>memory-locate ( r-addr char u -- r-addr | 0 )</h4>

                        <p>Locate a character in a block of memory 'u' characters wide, returning a pointer to
                        that character or zero if that address cannot be found.</p>

                        <br/><h4>memory-set ( r-addr char u -- )</h4>

                        <p>Set 'u' character of memory starting at 'r-addr' to 'char'.</p>

                        <br/><h4>memory-compare ( r-addr1 r-addr2 u -- x )</h4>

                        <p>Compare two blocks of memory 'u' units wide.</p>

                        <br/><h4>allocate ( u -- r-addr status )</h4>

                        <p>Allocate a block of memory.</p>

                        <br/><h4>free ( r-addr -- status )</h4>

                        <p>Free a block of memory.</p>

                        <br/><h4>getenv ( c-addr u -- r-addr u )</h4>

                        <p>Get an [environment variable][] given a string, it returns '0 0' if the
                        variable was not found.</p>

                        <br/><h3>File Access Words</h3>

                        <p>The following compiling words are part of the File Access Word set, a few of
                        the fields need explaining in the stack comments. "file-id" refers to a
                        previously opened file as returned by "open-file", "ior" refers to a return
                        status provided by the file operations. "fam" is a file access method,</p>

                        <br/><h4>close-file ( file-id -- ior )</h4>

                        <p>Close an already opened file.</p>

                        <br/><h4>open-file ( c-addr u fam -- file-id ior )</h4>

                        <p>Open a file, given a Forth string (the 'c-addr' and the 'u' arguments), and a
                        file access method, which is defined within "forth.fth". Possible file access
                        methods are "w/o", "r/w" and "r/o" for read only, read-write and write only
                        respectively.</p>

                        <br/><h4>delete-file ( c-addr u -- ior )</h4>

                        <p>Delete a file on the file system given a Forth string.</p>

                        <br/><h4>read-file ( c-addr u file-id -- ior )</h4>

                        <p>Read in 'u' characters into 'c-addr' given a file identifier.</p>

                        <br/><h4>write-file ( c-addr u file-id -- ior )</h4>

                        <p>Write 'u' characters from 'c-addr' to a given file identifier.</p>

                        <br/><h4>file-position ( file-id -- ud ior )</h4>

                        <p>Get the file position offset from the beginning of the file given a file</p>
                        identifier.

                        <br/><h4>reposition-file ( ud file-id -- ior )</h4>

                        <p>Reposition a files offset relative to the beginning of the file given a file
                        identifier.</p>

                        <br/><h4>flush-file ( file-id -- ior )</h4>

                        <p>Attempt to flush any buffered information written to a file.</p>

                        <br/><h4>rename-file ( c-addr1 u1 c-addr2 u2 -- ior )</h4>

                        <p>Rename a file on the file system named by the first string ('c-addr1' and 'u1')
                        to the second string ('c-addr2' and 'u2').</p>

                        <br/><h4>temporary-file ( -- file-id ior )</h4>

                        <p>Open up a new temporary file for writing and reading.</p>

                        <br/><h1>Defined words</h1>

                        <p>Defined words are ones which have been created with the ':' word, some words
                        get defined before the user has a chance to define their own to make their
                        life easier.</p>

                        <br/><h4>state ( -- addr )</h4>

                        <p>Push the address of the register that controls the interpreter state onto
                        the stack, this value can be written to put the interpreter into compile
                        or command modes.</p>

                        <br/><h4>; ( -- )</h4>

                        <p>Write 'exit' into the dictionary and switch back into command mode.</p>

                        <br/><h4>base ( -- addr )</h4>

                        <p>This pushes the address of a variable used for input and output conversion of
                        numbers, this address can be written to and read, valid numbers to write are 0
                        and 2 to 36 (*not* 1).</p>

                        <br/><h4>pwd ( -- pointer )</h4>

                        <p>Pushes a pointer to the previously define word onto the stack.</p>

                        <br/><h4>h ( -- pointer )</h4>

                        <p>Push a pointer to the dictionary pointer register.</p>

                        <br/><h4>r ( -- pointer )</h4>

                        <p>Push a pointer to the register pointer register.</p>

                        <br/><h4>here ( -- dictionary-pointer )</h4>

                        <p>Push the current dictionary pointer (equivalent to 'h @').</p>

                        <br/><h4>[ ( -- )</h4>

                        <p>Immediately switch into command mode.</p>

                        <br/><h4>] ( -- )</h4>

                        <p>Switch into compile mode.</p>

                        <br/><h4>>mark ( -- location )</h4>

                        <p>Write zero into the head of the dictionary and advance the dictionary pointer,
                        push a address to the zero written into the dictionary. This is usually used
                        after in a word definition that changes the control flow, after a branch for
                        example.</p>

                        <br/><h4>:noname ( -- execution-token )</h4>

                        <p>This creates a word header for a word without a name and switches to compile
                        mode, the usual ';' finishes the definition. It pushes a execution token onto
                        the stack that can be written into the dictionary and run, or executed directly.</p>

                        <br/><h4>if ( bool -- )</h4>

                        <p>Begin an if-else-then statement. If the top of stack is true then we
                        execute all between the if and a corresponding 'else' or 'then', otherwise
                        we skip over it.</p>

                        <p>Abstract Examples:</p>

                        <br/>

                        <code>
                          : word ... bool if do-stuff ... else do-other-stuff ... then ... ;
                          : word ... bool if do-stuff ... then ... ;
                        </code>

                        <p>and a concrete examples:</p>

                        <br/>

                        <code>
                          : test-word if 2 2 + . cr else 3 3 * . cr ;
                          0 test-word
                          4 # prints 4
                          1 test-word
                          9 # prints 9
                        </code>

                        <p>Is a simple and contrived example.</p>

                        <br/><h4>else ( -- )</h4>

                        <p>See 'if'.</p>

                        <br/><h4>then ( -- )</h4>

                        <p>See 'if'.</p>

                        <br/><h4>begin ( -- )</h4>

                        <p>This marks the beginning of a loop.</p>

                        <br/><h4>until ( bool -- )</h4>

                        <p>Loop back to the corresponding 'begin' if the top of the stack is zero, continue
                        on otherwise.</p>

                        <br/><h4>')' ( -- char )</h4>

                        <p>Push the number representing the ')' character onto the stack.</p>

                        <br/><h4>tab ( -- )</h4>

                        <p>Print a tab.</p>

                        <br/><h4>cr ( -- )</h4>

                        <p>Prints a newline.</p>

                        <br/><h4>(  ( -- )</h4>

                        <p>This will read the input stream until encountering a ')' character, it
                        is used for comments.</p>

                        <br/><h4>allot ( amount -- )</h4>

                        <p>Allocate a number of cells in the dictionary.</p>

                        <br/><h4>tuck ( x y -- y x y )</h4>

                        <p>The stack comment documents this word entirely.</p>

                        <br/><h4>nip ( x y -- y )</h4>

                        <p>he stack comment documents this word entirely.</p>

                        <br/><h4>rot ( x y z -- z x y )</h4>

                        <p>The stack comment documents this word entirely. This word rotates three items
                        on the variable stack.</p>

                        <br/><h4>-rot ( x y z -- y z x )</h4>

                        <p>The stack comment documents this word entirely. This word rotates three items
                        on the variable stack, in the opposite direction of 'rot'.</p>

                        <br/><h4>emit ( x -- )</h4>

                        <p>Write a single character out to the output stream.</p>

                        <br/><h4>? ( addr -- )</h4>

                        <p>Print the contents of addr to the screen.</p>


                    </div>
                </div>
            </div>
        </div>

    </section>
@endsection
