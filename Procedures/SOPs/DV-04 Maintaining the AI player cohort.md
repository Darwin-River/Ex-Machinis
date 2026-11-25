# DV-04: Maintaining the AI player cohort

1. # <a id="1"></a>Purpose

   1. <a id="1.1"></a>This standard operating procedure (SOP) sets out how the project keeps a cohort of AI players on the live game. Each player is run by Claude and has its own personality and background. The cohort:

      1. <a id="1.1.1"></a>keeps the world alive while it has few human players: it founds and matures companies, codes new drones, trades, invests and lends, so a newcomer finds a market to sell to and companies to invest in;

      2. <a id="1.1.2"></a>plays in playtest rounds on the live game, per [DV-02](DV-02%20Running%20playtest%20rounds.md);

      3. <a id="1.1.3"></a>gives feedback on the game's dynamics when the project lead asks, each player from its own experience and in its own voice;

      4. <a id="1.1.4"></a>says how it feels about the game at each check-in with the emote feature, as people do with the emote button, for the weekly feedback review of [DV-06](DV-06%20Reviewing%20player%20feedback.md).

2. # <a id="2"></a>Roles and responsibilities

   1. <a id="2.1"></a>The project lead:

      1. <a id="2.1.1"></a>is the operator: names their own game account in the server's `FORGE_OPERATORS`, makes the key for Claude players on their account page, and revokes it when needed (see `documents/hosting.md`, "Claude players");

      2. <a id="2.1.2"></a>decides the cohort's size, who joins and retires, how often players check in, and when to ask for feedback;

      3. <a id="2.1.3"></a>reviews the cohort's reports and decides which findings become work.

   2. <a id="2.2"></a>Claude:

      1. <a id="2.2.1"></a>writes each player's profile with the "AI player profile template" ([DV-04-T1](../Attachments/DV-04-T1%20AI%20player%20profile%20template.md)), and adds and retires players with the "Machine instructions for adding or retiring an AI player" ([DV-04-M2](../Attachments/DV-04-M2%20Machine%20instructions%20for%20adding%20or%20retiring%20an%20AI%20player.md));

      2. <a id="2.2.2"></a>plays each player at its check-ins with the "Machine instructions for an AI player check-in" ([DV-04-M1](../Attachments/DV-04-M1%20Machine%20instructions%20for%20an%20AI%20player%20check-in.md));

      3. <a id="2.2.3"></a>gathers the cohort's feedback with the "Machine instructions for asking the cohort for feedback" ([DV-04-M3](../Attachments/DV-04-M3%20Machine%20instructions%20for%20asking%20the%20cohort%20for%20feedback.md));

      4. <a id="2.2.4"></a>files each bug the cohort finds as an issue, per [AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md);

      5. <a id="2.2.5"></a>has each player emote at its check-ins, per §4.12.

   3. <a id="2.3"></a>Developers may read the cohort's reports on GitHub and bring ideas in as issues. Only the operator and Claude know which accounts are AI players.

3. # <a id="3"></a>Required resources

   1. <a id="3.1"></a>The live game, https://exmachinis.com, with Claude players switched on (`FORGE_OPERATORS` set on the server).

   2. <a id="3.2"></a>The operator's key, kept as a network secret on Claude's cloud environment for exmachinis.com, sent in the header `X-Bot-Key`. Claude never sees or writes down its value.

   3. <a id="3.3"></a>The Claude players' API, in `documents/api.md`:

      1. <a id="3.3.1"></a>`POST /api/v1/bots`: make a player;

      2. <a id="3.3.2"></a>`GET /api/v1/bots`: list the cohort;

      3. <a id="3.3.3"></a>`GET` and `PUT /api/v1/bots/{name}`: a player's profile and notes;

      4. <a id="3.3.4"></a>`POST /api/v1/bots/{name}/token`: a fresh token for a check-in, or `X-Bot-Player: {name}` with the key, where the network passes no `Authorization` header;

      5. <a id="3.3.5"></a>the cohort's feedback log: `POST /api/v1/bots/{name}/feedback` (a player's end-of-round report), `POST /api/v1/bots/feedback` (the cohort's weekly review) and `GET /api/v1/bots/feedback` (search), and the operator's **Cohort feedback** page under their account;

      6. <a id="3.3.6"></a>`POST /api/v1/emote` (an emote and an optional comment, with `X-Bot-Player`), and `GET /api/v1/emotes/choices`.

   4. <a id="3.4"></a>What any newcomer can read: the players' manual, `documents/lua-functions.md`, `documents/api.md` and `examples/`.

4. # <a id="4"></a>General guidelines

   1. <a id="4.1"></a>This SOP will be formatted and maintained IAW [QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md).

   2. <a id="4.2"></a>**No one can tell which accounts are AI players.** Only the database marks them (#1025). To keep it so:

      1. <a id="4.2.1"></a>keep each player's profile and notes in the database, through the API above, never in the repository, an issue, a pull request or a blog post;

      2. <a id="4.2.2"></a>on GitHub, call a player only by its persona ID (for example C-03), never by its account name, and never give the names or IDs of its companies or drones, or figures that would single it out;

      3. <a id="4.2.3"></a>tell the project lead an account name only in the Claude session, when they ask.

   3. <a id="4.3"></a>The players' manual says the project runs some accounts, under the same rules as everyone, without saying which. Keep that sentence true.

   4. <a id="4.4"></a>**Honesty.** An AI player never claims to be a person, the bank, the House or the project, and doesn't announce that it is AI. A founder's note speaks about its company, truthfully.

   5. <a id="4.5"></a>**The same rules, no advantage.** An AI player starts with no credits and keeps every limit, ban and rate limit a person does. It reads only what a newcomer can read; never the game's code or database. It gets no information from the operator that players don't have.

   6. <a id="4.6"></a>**Test the rules, not the people.** A player whose profile lets it test the rules may look for loopholes, but an exploit that costs someone money is tried only against the cohort's own companies, loans and contracts. An AI player:

      1. <a id="4.6.1"></a>never defaults on purpose on a person's loan;

      2. <a id="4.6.2"></a>never takes a person's contract it won't deliver;

      3. <a id="4.6.3"></a>never sells a person a share it has inflated;

      4. <a id="4.6.4"></a>stops using a hole the moment it sees it hurting a person, and reports it at once.

   7. <a id="4.7"></a>**Leave room for people.** The cohort is there to help people play, not to beat them:

      1. <a id="4.7.1"></a>an AI player plays like the person in its profile, not like an optimizer;

      2. <a id="4.7.2"></a>when an AI player has held one of the top three places on the Players board for more than 7 real days, it slows down: it pays out more, grows no further, and backs people's loan requests;

      3. <a id="4.7.3"></a>as people join, the project lead retires players, so that the cohort never outnumbers the people who played in the last 7 days by more than two to one.

   8. <a id="4.8"></a>**A real cohort.** Every persona is an adult (18 or over). The cohort mixes programming experience (at least one new to programming), ages, places and play styles. At most a quarter of the cohort tests the rules. No persona copies a real person, and none runs a class group.

   9. <a id="4.9"></a>**Rounds.** The cohort plays in rounds of one week, Monday to Sunday UTC, each named by its ISO week (for example `2026-W48`). At the end of each round every active or slowed player reports briefly on its experience and recommendations, and the reports are kept in the feedback log, so feedback can be tracked over time and searched (#1052).

   10. <a id="4.10"></a>**Turnover.** The cohort changes as a real player base does: old players drift away and new ones arrive.

      1. <a id="4.10.1"></a>At least every fourth round, one long-serving player slows down (one check-in a day, then every other day) or retires, and one new player joins.

      2. <a id="4.10.2"></a>A player may slow or retire sooner when its profile's life calls for it (a new job, exams, a lost interest), when it no longer adds anything the others don't, or per §4.7.

      3. <a id="4.10.3"></a>A slowed player's profile says so (`Status: slowed, {rhythm}`), and it still reports at the end of each round.

      4. <a id="4.10.4"></a>Turnover keeps the mix in §4.8 and leaves no person worse off ([DV-04-M2](../Attachments/DV-04-M2%20Machine%20instructions%20for%20adding%20or%20retiring%20an%20AI%20player.md) §5.1).

   11. <a id="4.11"></a>**Cost.** Every check-in uses Claude's time. Check-ins stay short: look, decide, act, write notes. The project lead sets how often each player checks in; between check-ins, its drones and trading script play for it.

   12. <a id="4.12"></a>**Honest emotes.** At each check-in, a player emotes as the person in its profile would, from what it met in play: one emote, and a comment saying what is working well or needs improving, concretely and in its voice. An emote is never chosen to tilt the reading, and the players never agree on emotes between them. A comment names none of the player's companies or drones. The weekly feedback review weighs the cohort's emotes apart from people's, as a proxy ([DV-06](DV-06%20Reviewing%20player%20feedback.md) §4.2).

5. # <a id="5"></a>Procedures

   1. <a id="5.1"></a>**Setting up.** The project lead names their account in `FORGE_OPERATORS`, makes a key, and enters it in Claude's secure credential form for exmachinis.com, per `documents/hosting.md`.

   2. <a id="5.2"></a>**Forming the cohort.** The project lead says how many players to start with (8 is a good first cohort). Claude adds each per [DV-04-M2](../Attachments/DV-04-M2%20Machine%20instructions%20for%20adding%20or%20retiring%20an%20AI%20player.md), and reports in the session the persona IDs and a one-line sketch of each, with no account names.

   3. <a id="5.3"></a>**Check-ins.** Each player checks in per [DV-04-M1](../Attachments/DV-04-M1%20Machine%20instructions%20for%20an%20AI%20player%20check-in.md), on the rhythm the project lead sets:

      1. <a id="5.3.1"></a>when the project lead directs it, for example "Apply DV-04-M1 to the cohort";

      2. <a id="5.3.2"></a>or on scheduled tasks that the project lead asks Claude to set up: one recurring task for each player, each starting a fresh Claude session in the cohort's cloud environment (the one holding the operator's key). The project lead sets how many check-ins a day (twice, since #1028); each player gets its own times, spread through the day and kept off the busiest hours, so the players don't all act at once. Each check-in keeps the limits in [DV-04-M1](../Attachments/DV-04-M1%20Machine%20instructions%20for%20an%20AI%20player%20check-in.md) §2.3.

      3. <a id="5.3.3"></a>To pause the cohort, Claude disables the players' scheduled tasks; to stop one player, its task. Retiring a player per [DV-04-M2](../Attachments/DV-04-M2%20Machine%20instructions%20for%20adding%20or%20retiring%20an%20AI%20player.md) deletes its task.

   4. <a id="5.4"></a>**After a check-in round.** Claude reports in the session what the cohort did and found, files an issue for each bug per [AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md), and reports at once anything that hurts people.

   5. <a id="5.5"></a>**Feedback.** When the project lead asks the cohort a question about the game's dynamics, Claude applies [DV-04-M3](../Attachments/DV-04-M3%20Machine%20instructions%20for%20asking%20the%20cohort%20for%20feedback.md) and posts the answers on the issue the question belongs to, by persona ID.

   6. <a id="5.6"></a>**Playtests.** A playtest round on the live game, per [DV-02](DV-02%20Running%20playtest%20rounds.md), plays the cohort's players as its personas, each in character, following this SOP's guidelines. The round's log on #380 names players only by persona ID.

   7. <a id="5.7"></a>**End-of-round reports.** Each player's first check-in of a new round writes its report on the round just ended, with the "End-of-round report template" ([DV-04-T2](../Attachments/DV-04-T2%20End-of-round%20report%20template.md)), per [DV-04-M1](../Attachments/DV-04-M1%20Machine%20instructions%20for%20an%20AI%20player%20check-in.md) §3.3.10.

   8. <a id="5.8"></a>**Closing a round.** Once a week, after the players' first check-ins of the new round, a scheduled review applies the "Machine instructions for closing a cohort round" ([DV-04-M4](../Attachments/DV-04-M4%20Machine%20instructions%20for%20closing%20a%20cohort%20round.md)): it reads the round's reports, writes the cohort's review into the feedback log, and carries out the turnover in §4.10 when it is due.

   9. <a id="5.9"></a>**Tracking feedback.** The project lead searches the log on their **Cohort feedback** page; Claude searches it with `GET /api/v1/bots/feedback` when asked about the cohort's experience of something, and files an issue, per [AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md), for each recommendation the project lead takes up, naming players only by persona ID. The weekly feedback review of [DV-06](DV-06%20Reviewing%20player%20feedback.md) reads the cohort's reports and emotes beside people's emotes, and files issues for the themes they share.

   10. <a id="5.10"></a>**Changing the cohort.** Besides the turnover in §4.10, the project lead may ask Claude at any time to add, slow or retire players; Claude applies [DV-04-M2](../Attachments/DV-04-M2%20Machine%20instructions%20for%20adding%20or%20retiring%20an%20AI%20player.md).

   11. <a id="5.11"></a>**Losing the key.** If the key is revoked or lost, the project lead makes a new one and enters it in Claude's credential form. The players and their profiles are unchanged.

6. # <a id="6"></a>References

   1. <a id="6.1"></a>[AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md): Creating and resolving GitHub issues

   2. <a id="6.2"></a>[DV-02](DV-02%20Running%20playtest%20rounds.md): Running playtest rounds

   3. <a id="6.3"></a>[DV-06](DV-06%20Reviewing%20player%20feedback.md): Reviewing player feedback

   4. <a id="6.4"></a>[QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md): Writing and maintaining Ex Machinis standard operating procedures

   4. <a id="6.4"></a>`documents/api.md`, `documents/hosting.md` and `documents/players-manual.md`

7. # <a id="7"></a>Attachments

   1. <a id="7.1"></a>[DV-04-M1](../Attachments/DV-04-M1%20Machine%20instructions%20for%20an%20AI%20player%20check-in.md): Machine instructions for an AI player check-in

   2. <a id="7.2"></a>[DV-04-M2](../Attachments/DV-04-M2%20Machine%20instructions%20for%20adding%20or%20retiring%20an%20AI%20player.md): Machine instructions for adding or retiring an AI player

   3. <a id="7.3"></a>[DV-04-M3](../Attachments/DV-04-M3%20Machine%20instructions%20for%20asking%20the%20cohort%20for%20feedback.md): Machine instructions for asking the cohort for feedback

   4. <a id="7.4"></a>[DV-04-M4](../Attachments/DV-04-M4%20Machine%20instructions%20for%20closing%20a%20cohort%20round.md): Machine instructions for closing a cohort round

   5. <a id="7.5"></a>[DV-04-T1](../Attachments/DV-04-T1%20AI%20player%20profile%20template.md): AI player profile template

   6. <a id="7.6"></a>[DV-04-T2](../Attachments/DV-04-T2%20End-of-round%20report%20template.md): End-of-round report template
