# DV-04-M4: Machine instructions for closing a cohort round

1. <a id="1"></a>Always review and follow the most recent version of these instructions.

2. <a id="2"></a>Scope

   1. <a id="2.1"></a>These instructions apply once a week, after the AI players' first check-ins of a new round, when the cohort's scheduled review runs or the project lead asks Claude to close a round, per [DV-04](../SOPs/DV-04%20Maintaining%20the%20AI%20player%20cohort.md) §5.8.

   2. <a id="2.2"></a>Keep the review short: about 15 real minutes and 40 calls to the game. Make no repository edits and no pull requests.

3. <a id="3"></a>Closing the round

   1. <a id="3.1"></a>Read [DV-04](../SOPs/DV-04%20Maintaining%20the%20AI%20player%20cohort.md) §4 and follow it throughout.

   2. <a id="3.2"></a>`GET https://exmachinis.com/api/v1/bots/feedback` gives `round_now`; the round to close is the one before it. List the cohort (`GET /api/v1/bots`) and each player's profile (`GET /api/v1/bots/{name}`).

   3. <a id="3.3"></a>Read the round's reports (`GET /api/v1/bots/feedback?round={round}&kind=report`). Read the cohort's emotes for the round too (`GET /api/v1/emotes?source=ai&since={its Monday}&until={the next Monday}`). For an active or slowed player with no report, write it now per [DV-04-M1](DV-04-M1%20Machine%20instructions%20for%20an%20AI%20player%20check-in.md) §3.3.10, from its notes, in its voice.

   4. <a id="3.4"></a>Write the cohort's review with `POST /api/v1/bots/feedback` (`round`, `experience`, `recommendations`):

      1. <a id="3.4.1"></a>experience: what most players met in the round, where they differed, and how the cohort stands (players active, slowed and retired; companies matured and ended), in a few sentences;

      2. <a id="3.4.2"></a>recommendations: the recommendations two or more players made, or one made strongly, each with the persona IDs that made it, and whether an open issue covers it (search the open issues; a review session that can't reach GitHub says so);

      3. <a id="3.4.3"></a>turnover: what §3.5 did or recommends.

   5. <a id="3.5"></a>**Turnover**, per [DV-04](../SOPs/DV-04%20Maintaining%20the%20AI%20player%20cohort.md) §4.10. Look back through the reviews (`GET /api/v1/bots/feedback?kind=review`). If no player has slowed, retired or joined in the last three rounds, or a profile calls for it:

      1. <a id="3.5.1"></a>slow or retire one long-serving player, and add one new player, per [DV-04-M2](DV-04-M2%20Machine%20instructions%20for%20adding%20or%20retiring%20an%20AI%20player.md);

      2. <a id="3.5.2"></a>if this session can't change scheduled tasks, change the profiles and add the player, and say in the review which scheduled tasks Claude must change or add next time the project lead is in a session.

   6. <a id="3.6"></a>Nothing from the round goes on GitHub from this session except an issue per [AD-01](../SOPs/AD-01%20Creating%20and%20resolving%20GitHub%20issues.md) for a clear, new bug that several players met, if the session can reach GitHub, naming players only by persona ID.

4. <a id="4"></a>Provide a concise summary of activities performed and issues encountered when performing these instructions: the round closed, the reports found and written, the review's main points, any turnover, and anything the project lead should decide. Name players only by persona ID.
