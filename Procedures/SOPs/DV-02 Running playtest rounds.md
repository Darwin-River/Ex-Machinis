# DV-02: Running playtest rounds

1. # <a id="1"></a>Purpose

   1. <a id="1.1"></a>This standard operating procedure (SOP) sets out how the project improves the game by playtesting it in rounds: run a world, release company designs and newcomer players into it, measure how the economy and the players fare, log what was learned on the playtest issue (#380), and turn the findings into issues.

2. # <a id="2"></a>Roles and responsibilities

   1. <a id="2.1"></a>The project lead decides when a round runs, what it tests, and which findings become work.

   2. <a id="2.2"></a>Claude runs rounds when directed, using the "Machine instructions for running a playtest round" ([DV-02-M1](../Attachments/DV-02-M1%20Machine%20instructions%20for%20running%20a%20playtest%20round.md)), plays the newcomer personas, and logs each round with the "Playtest round comment template" ([DV-02-T1](../Attachments/DV-02-T1%20Playtest%20round%20comment%20template.md)).

   3. <a id="2.3"></a>Developers, supporters, teachers, students and any other players may take part in a round as real players. Their feedback is logged without naming students.

3. # <a id="3"></a>Required resources

   1. <a id="3.1"></a>A world to play in: a local world built from the current `main` (see `game-engine/tools/load-world.sh` and the developer setup in `README.md`), or the live game.

   2. <a id="3.2"></a>The playtest issue, #380, and the economy playbook, `documents/economy-playbook.md`.

4. # <a id="4"></a>General guidelines

   1. <a id="4.1"></a>This SOP will be formatted and maintained IAW [QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md).

   2. <a id="4.2"></a>Each round tests one idea. Say before it starts what the round is meant to show.

   3. <a id="4.3"></a>Newcomer personas stand in for the people the game is for. Each round should include at least one persona who is new to programming, such as a high school student or a teacher, and the rest can vary (an accountant, a speedrunner, a Python developer). A persona plays only from what a real newcomer could see: the player's manual, the Lua function list, the examples and the game itself.

   4. <a id="4.4"></a>Rounds are logged as comments on #380, not as new issues. Each bug and each change a round leads to gets its own issue, per [AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md).

5. # <a id="5"></a>Procedures

   1. <a id="5.1"></a>Post the round's plan as a short comment on #380: the idea being tested, the code it runs on, the designs and personas, and how long it will run.

   2. <a id="5.2"></a>Run the round per [DV-02-M1](../Attachments/DV-02-M1%20Machine%20instructions%20for%20running%20a%20playtest%20round.md).

   3. <a id="5.3"></a>Measure liveliness and player experience:

      1. <a id="5.3.1"></a>per design: companies, average and best net wealth, wrecks and clones;

      2. <a id="5.3.2"></a>the share of companies gaining, companies ended, drone-to-drone trade, and how many different goods the House buys;

      3. <a id="5.3.3"></a>how the game clock held up;

      4. <a id="5.3.4"></a>each persona's ratings out of 10 for fun, clarity, depth, feedback, API, Lua (to learn and for fit), would play again, and overall;

      5. <a id="5.3.5"></a>what each persona found hard, what they asked for, and what they praised.

   4. <a id="5.4"></a>File an issue for each bug, per [AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md).

   5. <a id="5.5"></a>Log the round on #380 with [DV-02-T1](../Attachments/DV-02-T1%20Playtest%20round%20comment%20template.md).

   6. <a id="5.6"></a>Add what the round taught about making money to `documents/economy-playbook.md`, through a pull request, per [DV-01](DV-01%20Making%20and%20testing%20game%20changes.md).

   7. <a id="5.7"></a>If the round makes a good story (a newcomer who won, a strategy that surprised everyone, a lesson for classrooms), open an issue for a blog post, per [OR-01](OR-01%20Writing%20and%20publishing%20blog%20posts.md).

6. # <a id="6"></a>References

   1. <a id="6.1"></a>[AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md): Creating and resolving GitHub issues

   2. <a id="6.2"></a>[DV-01](DV-01%20Making%20and%20testing%20game%20changes.md): Making and testing game changes

   3. <a id="6.3"></a>[OR-01](OR-01%20Writing%20and%20publishing%20blog%20posts.md): Writing and publishing blog posts

   4. <a id="6.4"></a>[QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md): Writing and maintaining Ex Machinis standard operating procedures

7. # <a id="7"></a>Attachments

   1. <a id="7.1"></a>[DV-02-M1](../Attachments/DV-02-M1%20Machine%20instructions%20for%20running%20a%20playtest%20round.md): Machine instructions for running a playtest round

   2. <a id="7.2"></a>[DV-02-T1](../Attachments/DV-02-T1%20Playtest%20round%20comment%20template.md): Playtest round comment template
