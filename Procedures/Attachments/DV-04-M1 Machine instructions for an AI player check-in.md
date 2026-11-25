# DV-04-M1: Machine instructions for an AI player check-in

1. <a id="1"></a>Always review and follow the most recent version of these instructions.

2. <a id="2"></a>Scope

   1. <a id="2.1"></a>These instructions apply when the project lead, or a scheduled task they set up, asks Claude to check in on one AI player or on the whole cohort, per [DV-04](../SOPs/DV-04%20Maintaining%20the%20AI%20player%20cohort.md).

   2. <a id="2.2"></a>For the whole cohort, check each player in turn. A subagent may play a player, given these instructions, [DV-04](../SOPs/DV-04%20Maintaining%20the%20AI%20player%20cohort.md) §4, and the player's profile and notes.

   3. <a id="2.3"></a>Each check-in is short (#1028):

      1. <a id="2.3.1"></a>at most 10 real minutes and about 30 calls to the game, counting every request;

      2. <a id="2.3.2"></a>no waiting: never sleep more than 60 seconds in all;

      3. <a id="2.3.3"></a>one or two meaningful actions, as the player would, then stop; its drones and trading script play for it until the next check-in;

      4. <a id="2.3.4"></a>no subagents within a player's check-in, no repository edits and no pull requests;

      5. <a id="2.3.5"></a>at most one GitHub issue, only after searching the open ones (§3.4); anything else waits in the player's notes for the next report.

3. <a id="3"></a>Checking in

   1. <a id="3.1"></a>Read [DV-04](../SOPs/DV-04%20Maintaining%20the%20AI%20player%20cohort.md) §4, its General guidelines, and follow them throughout.

   2. <a id="3.2"></a>`GET https://exmachinis.com/api/v1/bots` lists the cohort (the session's network adds the operator's key). If it answers 401, stop: the key is missing or revoked. Tell the project lead, per [DV-04](../SOPs/DV-04%20Maintaining%20the%20AI%20player%20cohort.md) §5.11.

   3. <a id="3.3"></a>For each player to check in:

      1. <a id="3.3.1"></a>`GET /api/v1/bots/{name}` for its profile and notes. Skip a player whose profile says it is retired.

      2. <a id="3.3.2"></a>Act as the player by sending `X-Bot-Player: {name}` with every call for the rest of the check-in; the network adds the operator's key (#1030). Where a network passes `Authorization` headers, `POST /api/v1/bots/{name}/token` gives a token instead, kept only in memory or the scratchpad, never in the repository.

      3. <a id="3.3.3"></a>Become the player: from here on, act as the person in the profile, with their skills, risk and goals, picking up where the notes left off.

      4. <a id="3.3.4"></a>Look as they would:
         - their account (`GET /account`) and each company's page;
         - their drones' timelines and post-mortems;
         - the share market, the loan board and the news.

      5. <a id="3.3.5"></a>Decide and act, as they would. For example:
         - fix or improve a drone's code, testing it first with the test drive;
         - release a new startup, or mature one;
         - buy or sell shares;
         - back a loan request, or have a company ask for one;
         - post or take a contract;
         - set its trading script.

         Pace the calls well inside the rate limits.

      6. <a id="3.3.6"></a>Check the guidelines before every action that touches another player: [DV-04](../SOPs/DV-04%20Maintaining%20the%20AI%20player%20cohort.md) §4.6, testing the rules not the people, and §4.7, leaving room for people.

      7. <a id="3.3.7"></a>Note anything that looks like a bug: the request, the response, and what was expected.

      8. <a id="3.3.8"></a>Write its notes with `PUT /api/v1/bots/{name}` (`notes`):
         - add a dated entry: what they saw, did and plan next, and how they feel about the game, in their voice;
         - keep the notes under about 20,000 characters by summarizing the oldest entries.

      9. <a id="3.3.9"></a>**End of a round.** If this is the player's first check-in of a new round (the ISO week, Monday to Sunday UTC; `GET /api/v1/bots/feedback` gives `round_now`), and the log has no report from it for the round just ended (`GET /api/v1/bots/feedback?persona={its ID}&round={last round}`), write one: a brief report in its voice on that round's experience and recommendations, with the "End-of-round report template" ([DV-04-T2](DV-04-T2%20End-of-round%20report%20template.md)), sent with `POST /api/v1/bots/{name}/feedback`. It counts toward the check-in's limits.

   4. <a id="3.4"></a>Step out of character. For each bug found, decide whether it is new: search the open issues. File new ones (at most one a check-in, §2.3.5) per [AD-01](../SOPs/AD-01%20Creating%20and%20resolving%20GitHub%20issues.md), with steps anyone could follow and without the player's account, companies or drones ([DV-04](../SOPs/DV-04%20Maintaining%20the%20AI%20player%20cohort.md) §4.2).

   5. <a id="3.5"></a>If anything a player did or saw is hurting a person, stop that activity and tell the project lead at once.

4. <a id="4"></a>Provide a concise summary of activities performed and issues encountered when performing these instructions: per persona ID, what it did and how its companies stand, the bugs filed, and anything the project lead should decide. Give account names only if the project lead asks, and only in the session.
