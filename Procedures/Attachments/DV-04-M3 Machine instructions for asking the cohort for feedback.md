# DV-04-M3: Machine instructions for asking the cohort for feedback

1. <a id="1"></a>Always review and follow the most recent version of these instructions.

2. <a id="2"></a>Scope

   1. <a id="2.1"></a>These instructions apply when the project lead asks the AI player cohort a question about the game, for example "How do the cohort find borrowing?" or "Ask the cohort about #975", per [DV-04](../SOPs/DV-04%20Maintaining%20the%20AI%20player%20cohort.md).

3. <a id="3"></a>Asking

   1. <a id="3.1"></a>Find or open the issue the question belongs to, per [AD-01](../SOPs/AD-01%20Creating%20and%20resolving%20GitHub%20issues.md).

   2. <a id="3.2"></a>For each active player (`GET https://exmachinis.com/api/v1/bots`, then `GET /api/v1/bots/{name}`):

      1. <a id="3.2.1"></a>read its profile and notes;

      2. <a id="3.2.2"></a>if the question is about something it hasn't met, give it a short check-in first per [DV-04-M1](DV-04-M1%20Machine%20instructions%20for%20an%20AI%20player%20check-in.md), in which it tries that part of the game as it would;

      3. <a id="3.2.3"></a>answer in its voice, from its own experience only: what it did, what happened, how it felt, and what it would change. Rate it out of 10 when the question asks for a rating. Keep each answer short;

      4. <a id="3.2.4"></a>add a dated entry to its notes saying what it was asked and what it said.

   3. <a id="3.3"></a>Post one comment on the issue:
      - the question;
      - a short summary of where the cohort agrees and differs;
      - each answer under its persona ID, with its one-line sketch (for example "C-04, a cautious nurse new to programming").

      Name no accounts, companies or drones, and give no figures that would single a player out ([DV-04](../SOPs/DV-04%20Maintaining%20the%20AI%20player%20cohort.md) §4.2). Say plainly that the answers come from the project's AI players.

   4. <a id="3.4"></a>A bug an answer reveals gets its own issue per [AD-01](../SOPs/AD-01%20Creating%20and%20resolving%20GitHub%20issues.md).

4. <a id="4"></a>Provide a concise summary of activities performed and issues encountered when performing these instructions: the question, where the cohort agreed and differed, the comment's link, and any issues filed.
