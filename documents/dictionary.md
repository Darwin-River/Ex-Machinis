# FORTH Dictionary

This dictionary was developed and adapted from the one provided by Richard Howel with his [libforth C library](https://github.com/howerj/libforth). Each word is also given with its effect on the variable stack, any other effects
are documented (including the effects on other stacks). Each entry looks like
this:

* word ( y -- z )

Where 'word' is the word being described, the contents between the parenthesis
describe the stack effects, this word expects one number to be one the stack,
'y', and returns a number to the stack 'z'. 

Some of the descriptions of the variables in the stack effect comment 
have a meaning:

|  Name   |                   Meaning                       |
|---------|:------------------------------------------------|
| addr    | An address in cells                             |
| c-addr  | Character address                               |
| r-addr  | A raw address                                   |
| file-id | A file id, used as a handle for file operations |
| ior     | A error status returned by file operations      |
| char    | Single byte / character                         |
| u       | An unsigned value                               |
| x       | A signed value                                  |
| c" xxx" | The word parses a word (not a stack effect)     |
	
The addressing modes complicate certain operations of Forth words. A normal
Forth address, as passed to words like '!', '@', 'c@' and 'c!' are relative to
the beginning of the start of the Forth cores memory, or at register zero. '!'
and '@' take their addresses in cells (*addr*), whereas 'c@' and 'c!' take 
their addresses in character address (*c-addr*).

Raw addresses are however normal addresses, as understood by [C][] programs 
and the computer. Operations on raw addresses can possibly result in undefined
behavior, and all objects they may operate on that exists outside of the Forth
core cannot be guaranteed to exists after a core file has been reloaded (that
is pointers to objects outside of Forth core should not be saved for further
use). Raw-addresses are also in character, not cell, units.

In future revisions of Forth this memory addressing might be simplified, if a
simple way of doing this can be achieved.

### Internal words

There are three types of words.

#### 'Invisible' words

These invisible words have no name but are used to implement the Forth. They
are all *immediate* words.

Word | Stack Effect | Meaning
:----|:------------:|:--------
push | -- x | Push the next value in the instruction stream onto the variable stack, advancing the instruction stream. 
const | -- x | Push the next location after this instruction, not the instruction stream, onto the variable stack. 
run | -- | Save the current instruction stream pointer onto the return stack and set the pointer instruction stream pointer to point to value after *run*.

#### Immediate words

These words are named and are *immediate* words.

* ':'           ( c" xxx" -- )

Read in a new word from the input stream and compile it into the dictionary.

* 'immediate'   ( -- )

Make the previously declared word immediate. Unlike in most Forth
implementations this is used after the words name is given not after the
final ';' has been reached.

So:

        : word immediate ... ;

Instead of:

        : word ... ; immediate

* '\\'           ( c" \n" -- )

A comment, ignore everything until the end of the line.

#### Compiling words

* 'read'        ( c" xxx" -- )

*read* is a complex word that implements most of the input interpreter,
it reads in a [Forth][] *word* (up to 31 characters), if this *word* is in
the *dictionary* it will either execute the word if we are in *command mode*
or compile a pointer to the executable section of the word if in *compile
mode*. If this *word* is not in the *dictionary* it is checked if it is a
number, if it is then in *command mode* we push this value onto the *variable
stack*, if in *compile mode* then we compile a *literal* into the *dictionary*.
If it is none of these we print an error message and attempt to read in a
new word.

* '@'           ( address -- x )

Pop an address and push the value at that address onto the stack.

* '!'           ( x address -- )

Given an address and a value, store that value at that address.

* 'c@'          ( char-address -- char )

Pop a character address and push the character value at that address onto the
stack. Note that this access is not checked for being within range of the
virtual machines memory, but it is still relative to the start address of
virtual machine memory. 

* 'c!'          ( char char-address -- )

Given a character address, store a character value at that address, like 'c@'
the address is relative to the virtual machines starting address.

* '-'           ( x y -- z )

Pop two values, subtract 'y' from 'x' and push the result onto the stack.

* '+'           ( x y -- z )

Pop two values, add 'y' to 'x' and push the result onto the stack.

* 'and'         ( x y -- z )

Pop two values, compute the bitwise 'AND' of them and push the result on to
the stack.

* 'or'          ( x y -- z )

Pop two values, compute the bitwise 'OR' of them and push the result on to
the stack.

* 'xor'         ( x y -- z )

Pop two values, compute the bitwise 'XOR' of them and push the result on to
the stack.

* 'invert'      ( x y -- z )

Perform a bitwise negation on the top of the stack.

* 'lshift'      ( x y -- z )

Pop two values, compute 'y' shifted by 'x' places to the left and push
the result on to the stack.

* 'rshift'      ( x y -- z )

Pop two values, compute 'y' shifted by 'x' places to the right and push
the result on to the stack.

* '\*'          ( x y -- z )

Pop two values, multiply them and push the result onto the stack.

* '/'           ( x y -- z )

Pop two values, divide 'x' by 'y' and push the result onto the stack. If 'y'
is zero and error message is printed and 'x' and 'y' will remain on the
stack, but execution will continue on as normal.

* 'u\<'         ( x y -- z )

Pop two unsigned values, compare them (y < x) and push the result onto the
stack, the comparison will be unsigned.

* 'u\>'         ( x y -- z )

Pop two values, compare them (y > x) and push the result onto the stack. The
comparison will be unsigned.

* '\_exit'        ( -- )

Pop the return stack and set the instruction stream pointer to that
value.

* 'exit'        ( -- )

This does the same as **\_exit**, the reason there are two exits instead
of one is so that the word 'see', defined in **forth.fth** can differentiate 
between an exit that occurs in the definition of a word, and one that occurs
at the end of a words definition. ('see' is a decompiler for Forth).


For example:

	: test 0 begin dup 10 > if exit then dup . cr 1+  again ;

Gets Compiled to:

	Address        Contents 
	         ._____._____._____._____. <- Start of Word
	  X      | 't' | 'e' | 's' | 't' | 
	         ._____._____._____._____.
	  X+1    |  0  |  0  |  0  |  0  | NUL terminates 'test' string
	         ._____._____._____._____.
	  X+2    | Previous Word Pointer | AKA 'PWD' field
	         ._______________________.
	  X+3    |       MISC Field      | <- Execution Starts here
	         ._______________________.
	  X+4    | Literal               | Literals a compiled as a pointer to
	         ._______________________. a 'literal' word and the literal in
	  X+5    | 0                     | the next field.
	         ._______________________.
	  X+6    | Pointer to 'dup'      |
	         ._______________________.
	  X+7    | literal               |
	         ._______________________.
	  X+8    | 10                    |
	         ._______________________.
	  X+9    | Pointer to '>'        |
	         ._______________________.
	  X+10   | Pointer to 'branch?'  | 'if' gets compiled to 'branch?'
	         ._______________________. and '2' so it jumps over 'exit'
	  X+11   | 2                     | if the previous test fails. This
	         ._______________________. is encoded as the jump destination
	  X+12   | Pointer to 'exit'     | less one as an increment happens
	         ._______________________. after the word is executed.
	  X+13   | Pointer to 'dup'      |
	         ._______________________.
	  X+14   | Pointer to '.'        |
	         ._______________________.
	  X+15   | Pointer to 'cr'       |
	         ._______________________.
	  X+16   | Pointer to '1+'       |
	         ._______________________.
	  X+17   | Pointer to 'branch'   |
	         ._______________________.
	  X+18   | -12                   |
	         ._______________________.
	  X+19   | Pointer to '_exit'    |
	         ._______________________. <- End of Word

The decompiler knows that the end of a word is demarcated by a pointer to
**\_exit**, and that pointers to **exit** can occur within the body of the
definition.

* 'key'         ( -- char )

Get a value from the input and put it onto the stack.

* '\_emit'      ( char -- status )

Put a character to the output stream returning a success value.

* 'r\>'          ( -- x )
        
Pop a value from the return stack and push it to the variable stack.

* '\>r'          ( x -- )

Pop a value from the variable stack and push it to the return stack.

* 'branch'           ( -- )

Jump unconditionally to the destination next in the instruction stream.

* '?branch'          ( bool -- )

Pop a value from the variable stack, if it is zero the jump to the
destination next in the instruction stream, otherwise skip over it.

* '(.)'           ( x -- status )

Pop a value from the variable stack and print it to the output either
as a ASCII decimal or hexadecimal value depending on the BASE register. A
return status is pushed onto the stack, greater or equal to zero is a success,
negative is a failure. Failure can occur because of an invalid base in the BASE
register, or because the output could not be written to.

* '''           ( -- )

Push the next value in the instruction stream onto the variable stack
and advance the instruction stream pointer over it.

* ','           ( x -- )

Write a value into the dictionary, advancing the dictionary pointer.

* '='           ( x y -- z )

Pop two values, perform a test for equality and push the result.

* 'swap'        ( x y -- y z )

Swap two values on the stack.

* 'dup'         ( x -- x x )

Duplicate a value on the stack.

* 'drop'        ( x -- )

Drop a value.

* 'over'        ( x y -- x y x )

Duplicate the value that is next on the stack.

* 'find'        ( -- execution-token )

Find a word in the dictionary pushing a pointer to that word onto the
variable stack.

* 'depth'       ( -- depth )

Push the current stack depth onto the stack, the value is the depth of the
stack before the depth value was pushed onto the variable stack.

* 'sp@'         ( -- addr )

Push the address of the stack pointer onto the stack, before **sp@** was 
executed:

	1 2 sp@ . . .

Prints:

	2 2 1

* 'sp!'         ( addr -- )

Set the address of the stack pointer.

* 'clock'       ( -- x )

Push the difference between the startup time and now, in milliseconds. This
can be used for timing and implementing sleep functionality, the counter
will not increase the interpreter is blocking and waiting for input, although
this is implementation dependent.

* 'evaluator'   ( c-addr u 0 | file-id 0 1 -- x )

This word is a primitive used to implement 'evaluate' and 'include-file', it
takes a boolean to decide whether it will read from a file (1) or a string (0),
and then takes either a forth string, or a **file-id**.

* 'system'      ( c-addr u -- status )

Execute a command with the systems command interpreter.

* 'raise'      ( u -- ??? )

Raises a signal, what happens after the signal is raised is undefined.

* 'date'       ( -- date )

Push the date onto the stack, the order of the arguments are:

	Is day light savings time? 
	Days since start of year
	Week day
	Year
	Month
	Day of month
	Hour
	Minutes
	Seconds (note, this can include lead seconds!)
	
The time is in UTC time.

* 'memory-copy' ( r-addr1 r-addr2 u -- )

Operates on two raw addresses. Copy 'u' characters from r-addr2 to r-addr1.

* 'memory-locate' ( r-addr char u -- r-addr | 0 )

Locate a character in a block of memory 'u' characters wide, returning a pointer to
that character or zero if that address cannot be found.

* 'memory-set'     ( r-addr char u -- )

Set 'u' character of memory starting at 'r-addr' to 'char'.

* 'memory-compare'  ( r-addr1 r-addr2 u -- x )

Compare two blocks of memory 'u' units wide.

* 'allocate' ( u -- r-addr status )

Allocate a block of memory.

* 'free' ( r-addr -- status )

Free a block of memory.

* 'getenv' ( c-addr u -- r-addr u )

Get an [environment variable][] given a string, it returns '0 0' if the
variable was not found.

##### File Access Words

The following compiling words are part of the File Access Word set, a few of
the fields need explaining in the stack comments. "file-id" refers to a
previously opened file as returned by "open-file", "ior" refers to a return
status provided by the file operations. "fam" is a file access method, 

* 'close-file'  ( file-id -- ior )

Close an already opened file.

* 'open-file'   ( c-addr u fam -- file-id ior )

Open a file, given a Forth string (the 'c-addr' and the 'u' arguments), and a
file access method, which is defined within "forth.fth". Possible file access
methods are "w/o", "r/w" and "r/o" for read only, read-write and write only
respectively.

* 'delete-file' ( c-addr u -- ior )

Delete a file on the file system given a Forth string.

* 'read-file'   ( c-addr u file-id -- ior )

Read in 'u' characters into 'c-addr' given a file identifier.

* 'write-file'  ( c-addr u file-id -- ior )

Write 'u' characters from 'c-addr' to a given file identifier.

* 'file-position'   ( file-id -- ud ior )

Get the file position offset from the beginning of the file given a file
identifier.

* 'reposition-file' ( ud file-id -- ior )

Reposition a files offset relative to the beginning of the file given a file
identifier.

* 'flush-file' ( file-id -- ior )

Attempt to flush any buffered information written to a file.

* 'rename-file' ( c-addr1 u1 c-addr2 u2 -- ior )

Rename a file on the file system named by the first string ('c-addr1' and 'u1')
to the second string ('c-addr2' and 'u2').

* 'temporary-file' ( -- file-id ior )

Open up a new temporary file for writing and reading.

### Defined words

Defined words are ones which have been created with the ':' word, some words
get defined before the user has a chance to define their own to make their
life easier.

* 'state'       ( -- addr )

Push the address of the register that controls the interpreter state onto
the stack, this value can be written to put the interpreter into compile
or command modes. 

* ';'           ( -- )

Write 'exit' into the dictionary and switch back into command mode.

* 'base'         ( -- addr )

This pushes the address of a variable used for input and output conversion of
numbers, this address can be written to and read, valid numbers to write are 0
and 2 to 36 (*not* 1).

* 'pwd'         ( -- pointer )

Pushes a pointer to the previously define word onto the stack.

* 'h'           ( -- pointer )

Push a pointer to the dictionary pointer register.

* 'r'           ( -- pointer )

Push a pointer to the register pointer register.

* 'here'        ( -- dictionary-pointer )

Push the current dictionary pointer (equivalent to "h @").

* '\['          ( -- )

Immediately switch into command mode.

* '\]'          ( -- )

Switch into compile mode

* '\>mark'      ( -- location )

Write zero into the head of the dictionary and advance the dictionary pointer,
push a address to the zero written into the dictionary. This is usually used
after in a word definition that changes the control flow, after a branch for
example.

* ':noname'     ( -- execution-token )

This creates a word header for a word without a name and switches to compile
mode, the usual ';' finishes the definition. It pushes a execution token onto
the stack that can be written into the dictionary and run, or executed directly.

* 'if'          ( bool -- )

Begin an if-else-then statement. If the top of stack is true then we
execute all between the if and a corresponding 'else' or 'then', otherwise
we skip over it.

Abstract Examples:

        : word ... bool if do-stuff ... else do-other-stuff ... then ... ;

        : word ... bool if do-stuff ... then ... ;

and a concrete examples:

        : test-word if 2 2 + . cr else 3 3 * . cr ;
        0 test-word
        4             # prints 4
        1 test-word
        9             # prints 9

Is a simple and contrived example.

* 'else'        ( -- )

See 'if'.

* 'then'        ( -- )

See 'if'.

* 'begin'       ( -- )

This marks the beginning of a loop.

* 'until'       ( bool -- )

Loop back to the corresponding 'begin' if the top of the stack is zero, continue
on otherwise.

* "')'"         ( -- char )

Push the number representing the ')' character onto the stack.

* 'tab'         ( -- )

Print a tab.

* 'cr'          ( -- )

Prints a newline.

* '('           ( -- )

This will read the input stream until encountering a ')' character, it
is used for comments.

* 'allot'       ( amount -- )

Allocate a number of cells in the dictionary.

* 'tuck'        ( x y -- y x y )

The stack comment documents this word entirely.

* 'nip'         ( x y -- y )

The stack comment documents this word entirely.

* 'rot'         ( x y z -- z x y )

The stack comment documents this word entirely. This word rotates three items
on the variable stack.

* '-rot'        ( x y z -- y z x )

The stack comment documents this word entirely. This word rotates three items
on the variable stack, in the opposite direction of "rot".

* 'emit'        ( x -- )

Write a single character out to the output stream.

* '?'             ( addr -- )

Print the contents of addr to the screen.

