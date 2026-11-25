# DV-05-M1: Machine instructions for the daily balance check

1. <a id="1"></a>Always review and follow the most recent version of these instructions.

2. <a id="2"></a>Scope

   1. <a id="2.1"></a>Apply these instructions when the project lead, or a scheduled task they set up, directs you to run the daily balance check ("Apply DV-05-M1"), per [DV-05](../SOPs/DV-05%20Monitoring%20and%20balancing%20the%20live%20game.md).

   2. <a id="2.2"></a>Keep the check small (DV-05 §4.8):

      1. <a id="2.2.1"></a>at most 15 real minutes and about 60 reads of the API;

      2. <a id="2.2.2"></a>no subagents, no repository edits and no pull requests;

      3. <a id="2.2.3"></a>one comment on #1032, plus a measure on a tuning issue when one falls due (§3.7), plus an issue only under DV-05 §5.6.

3. <a id="3"></a>The daily check

   1. <a id="3.1"></a>Read [DV-05](../SOPs/DV-05%20Monitoring%20and%20balancing%20the%20live%20game.md) §4 and §5.3, and follow them throughout. Above all, never name or single out an AI player's account, company or drone, and never pass anything to the AI players (DV-05 §4.6).

   2. <a id="3.2"></a>Update the repository to the current `main`. Read the last three comments on #1032 for the previous readings and the signals they carried.

   3. <a id="3.3"></a>Run, saving what they read to the scratchpad:

      1. <a id="3.3.1"></a>`python3 tools/balance/measure_live.py --save <scratchpad>/live`;

      2. <a id="3.3.2"></a>`python3 tools/balance/watch.py --save <scratchpad>/watch`, and again with `--json` for the data line.

      If exmachinis.com can't be reached, post a one-line reading that says so and stop. Never ask for a login to the server.

   4. <a id="3.4"></a>List the commits on `main` since the last reading's commit (`git log <last>..origin/main --oneline`). Name each that touched game data, mining, prices, travel, stations or company rules, and say whether it has deployed: its "Deploy to the live server" run on GitHub Actions succeeded.

   5. <a id="3.5"></a>For each signal in DV-05 §5.3, compare with the previous readings and mark it new, continuing (with how many readings in a row), fired (reached its count), or cleared. For a sudden move, say which commit, if any, it follows.

   6. <a id="3.6"></a>Post the reading on #1032 with the "Daily balance reading template" ([DV-05-T1](DV-05-T1%20Daily%20balance%20reading%20template.md)).

   7. <a id="3.7"></a>Check the open sub-issues of #962 that have deployed. If one's day-1 or week-1 measure is due (about a real day or a real week after its deploy, per [DV-03](../SOPs/DV-03%20Tuning%20gameplay%20parameters.md) §5.5.1) and not yet posted, post the relevant rows of today's reading on it, against its baseline and prediction.

   8. <a id="3.8"></a>If DV-05 §5.6 applies, file the issue or tell the project lead now.

4. <a id="4"></a>Provide a concise summary of activities performed and issues encountered when performing these instructions: the signals that fired or cleared, any measure posted, and any issue filed.
