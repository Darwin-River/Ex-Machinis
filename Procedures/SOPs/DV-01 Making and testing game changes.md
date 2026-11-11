# DV-01: Making and testing game changes

1. # <a id="1"></a>Purpose

   1. <a id="1.1"></a>This standard operating procedure (SOP) sets out how developers and Claude change the game (the website, the game engines, the game data and the Lua functions drones use), how they test the change, and how they keep the docs that players and teachers rely on in step with it.

2. # <a id="2"></a>Roles and responsibilities

   1. <a id="2.1"></a>The project lead sets the direction of the game and approves rule changes, new features and changes to the economy.

   2. <a id="2.2"></a>Developers and Claude make changes per this SOP, using the "Machine instructions for making a game change" ([DV-01-M1](../Attachments/DV-01-M1%20Machine%20instructions%20for%20making%20a%20game%20change.md)).

   3. <a id="2.3"></a>Whoever makes a change is responsible for updating every doc it affects, using the "Docs to keep current" checklist ([DV-01-A1](../Attachments/DV-01-A1%20Docs%20to%20keep%20current.md)).

3. # <a id="3"></a>Required resources

   1. <a id="3.1"></a>The developer setup in the repository's `README.md`: PHP 8.3, Composer, MySQL, Node 20 or later, and the C toolchain for the game engines.

   2. <a id="3.2"></a>The design manual (`documents/design-manual.md`), which records how the game is built and why.

4. # <a id="4"></a>General guidelines

   1. <a id="4.1"></a>This SOP will be formatted and maintained IAW [QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md).

   2. <a id="4.2"></a>Break, learn, repeat. Every release is live and free, so players learn by failing. A change should keep failure cheap and feedback fast: a drone that goes wrong should say why, on its page, quickly.

   3. <a id="4.3"></a>Ex Machinis teaches programming. Prefer changes that make a player's code easier to read, test and reason about over changes that add rules to memorize. Error messages are teaching moments: say what went wrong and what to try.

   4. <a id="4.4"></a>The game must stay free to play and open to classrooms. A change must not put a gameplay advantage behind payment, per [FR-01](FR-01%20Raising%20funds%20through%20Ko-fi.md) §4.2.

5. # <a id="5"></a>Procedures

   1. <a id="5.1"></a>Track the change with an issue, per [AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md), and work on a branch, per [AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md).

   2. <a id="5.2"></a>Make the change, with tests that show it working and, for a bug, a test that failed before the fix.

   3. <a id="5.3"></a>Run the checks for what was changed:

      1. <a id="5.3.1"></a>PHP and website: `vendor/bin/phpunit`.

      2. <a id="5.3.2"></a>Game engines: `make test` in `game-engine/src`.

      3. <a id="5.3.3"></a>Game data: after editing anything in `game-engine/sql/game-data/` or `documents/economy.xlsx`, run `python3 game-engine/sql/game-data/build_game_data.py` and commit everything it regenerates.

      4. <a id="5.3.4"></a>Frontend: after changing `resources/assets/`, run `npm run build` and commit `html/build/`.

      5. <a id="5.3.5"></a>Schema: add a numbered migration in `game-engine/sql/migrations/`.

   4. <a id="5.4"></a>Update every doc the change affects, using [DV-01-A1](../Attachments/DV-01-A1%20Docs%20to%20keep%20current.md), in the same pull request.

   5. <a id="5.5"></a>If the change is worth telling players about, open an issue for a blog post, per [OR-01](OR-01%20Writing%20and%20publishing%20blog%20posts.md), and note the change in the next social post or supporter update, per [OR-02](OR-02%20Engaging%20on%20social%20media.md) and [FR-01](FR-01%20Raising%20funds%20through%20Ko-fi.md).

   6. <a id="5.6"></a>If the change came from a playtest round, link the round's comment on #380 in the pull request, per [DV-02](DV-02%20Running%20playtest%20rounds.md).

   7. <a id="5.7"></a>Open the pull request and see it through to merge, per [AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md).

6. # <a id="6"></a>References

   1. <a id="6.1"></a>[AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md): Creating and resolving GitHub issues

   2. <a id="6.2"></a>[AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md): Working with branches and pull requests

   3. <a id="6.3"></a>[DV-02](DV-02%20Running%20playtest%20rounds.md): Running playtest rounds

   4. <a id="6.4"></a>[FR-01](FR-01%20Raising%20funds%20through%20Ko-fi.md): Raising funds through Ko-fi

   5. <a id="6.5"></a>[OR-01](OR-01%20Writing%20and%20publishing%20blog%20posts.md): Writing and publishing blog posts

   6. <a id="6.6"></a>[OR-02](OR-02%20Engaging%20on%20social%20media.md): Engaging on social media

   7. <a id="6.7"></a>[QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md): Writing and maintaining Ex Machinis standard operating procedures

7. # <a id="7"></a>Attachments

   1. <a id="7.1"></a>[DV-01-A1](../Attachments/DV-01-A1%20Docs%20to%20keep%20current.md): Docs to keep current

   2. <a id="7.2"></a>[DV-01-M1](../Attachments/DV-01-M1%20Machine%20instructions%20for%20making%20a%20game%20change.md): Machine instructions for making a game change
