# DV-05-M2: Machine instructions for the weekly balance review

1. <a id="1"></a>Always review and follow the most recent version of these instructions.

2. <a id="2"></a>Scope

   1. <a id="2.1"></a>Apply these instructions when the project lead, or a scheduled task they set up, directs you to run the weekly balance review ("Apply DV-05-M2"), per [DV-05](../SOPs/DV-05%20Monitoring%20and%20balancing%20the%20live%20game.md).

   2. <a id="2.2"></a>The review takes at most 30 real minutes. It drafts at most one tuning issue and changes nothing in the game or the repository.

3. <a id="3"></a>The weekly review

   1. <a id="3.1"></a>Read [DV-05](../SOPs/DV-05%20Monitoring%20and%20balancing%20the%20live%20game.md) §4 and §5.3 to §5.6, and follow them throughout, above all §4.6 on the AI cohort.

   2. <a id="3.2"></a>Read:

      1. <a id="3.2.1"></a>the last seven readings on #1032, and the last weekly review there;

      2. <a id="3.2.2"></a>the open sub-issues of #962, noting which are being measured and their lever families;

      3. <a id="3.2.3"></a>the latest round on #380;

      4. <a id="3.2.4"></a>issues opened in the week that raise a balance concern;

      5. <a id="3.2.5"></a>the Player Ladder's step counts (`GET /api/v1/ladder` with the operator's key, #1045): how many people's accounts reached each step, all told and among those made in the week, and the guest test drives. Put them in the review as one line, with the step that lost the most new accounts. They are counts only: never name or single out a player.

   3. <a id="3.3"></a>Build one table of every signal that fired in the week: its trend over the seven readings (from the readings' data lines), the objective it bears on, and its likely source: a tuning change being measured, another deploy, the players' code, or the parameters themselves.

   4. <a id="3.4"></a>Set aside:

      1. <a id="3.4.1"></a>signals in a lever family whose change is being measured (DV-05 §4.4): write "waiting on #N";

      2. <a id="3.4.2"></a>signals that began within two real days of a deploy that touched the same thing (DV-05 §4.5): write "after <commit>";

      3. <a id="3.4.3"></a>signals that come from players' code rather than the parameters, for example drones that don't yet look for crowding. Note these for the docs or the starters as an ordinary issue per [DV-01](../SOPs/DV-01%20Making%20and%20testing%20game%20changes.md), not a tuning change.

   5. <a id="3.5"></a>Of the signals left, choose the one that matters most (DV-05 §4.7). If a sub-issue of #962 already proposes a fix for it, recommend that one. Otherwise, draft a tuning issue with the "Tuning issue template" ([DV-03-T1](DV-03-T1%20Tuning%20issue%20template.md)) as a sub-issue of #962. Title it "Draft: …", run the static check on a scratch copy to fill in the static prediction, and change no file in the repository.

   6. <a id="3.6"></a>Post the review on #1032:

      1. <a id="3.6.1"></a>the table;

      2. <a id="3.6.2"></a>what was set aside, and why;

      3. <a id="3.6.3"></a>the proposal (or "no change proposed");

      4. <a id="3.6.4"></a>the tuning changes in measurement, with their next measure date.

      Attribute it per [AD-01](../SOPs/AD-01%20Creating%20and%20resolving%20GitHub%20issues.md) §5.6.

   7. <a id="3.7"></a>Tell the project lead the headline and the proposal in a few lines, and wait for their decision. An approved draft goes through [DV-03](../SOPs/DV-03%20Tuning%20gameplay%20parameters.md) (DV-03-M1).

   8. <a id="3.8"></a>If this is the first review of a calendar month, also apply DV-05 §5.7.

4. <a id="4"></a>Provide a concise summary of activities performed and issues encountered when performing these instructions: the signals that fired, what was set aside, and the draft issue, if any.
