# DV-03-M1: Machine instructions for a tuning cycle

1. <a id="1"></a>Always review and follow the most recent version of these instructions.

2. <a id="2"></a>Scope

   1. <a id="2.1"></a>Apply these instructions when the project lead directs you to take a tuning change through its cycle ("Apply DV-03-M1 to #N"), to post a balance review ("Apply DV-03-M1 for a balance review"), or to add or change a playability objective.

3. <a id="3"></a>Taking a tuning change through its cycle

   1. <a id="3.1"></a>Read [DV-03](../SOPs/DV-03%20Tuning%20gameplay%20parameters.md), the tuning issue, its parent (#962), `documents/balance-report.md` and the last three rounds on #380.

   2. <a id="3.2"></a>If the issue doesn't yet follow the "Tuning issue template" ([DV-03-T1](DV-03-T1%20Tuning%20issue%20template.md)), rewrite it so it does, keeping the requester's words.

   3. <a id="3.3"></a>On the session's branch, make the change. Run `python3 game-engine/sql/game-data/build_game_data.py`, then `python3 tools/balance/static_check.py`. Compare the static objectives with the issue's prediction, and post the comparison on the issue before going further.

   4. <a id="3.4"></a>If a guard breaks, stop. Report which, and why, on the issue, and wait for the project lead.

   5. <a id="3.5"></a>Take the live baseline with `python3 tools/balance/measure_live.py --save <folder>`. If the live server can't be reached from the session, say so and ask the project lead to run it. Never ask for a login to the server. Post the table on the issue.

   6. <a id="3.6"></a>Finish the change per the "Machine instructions for making a game change" ([DV-01-M1](DV-01-M1%20Machine%20instructions%20for%20making%20a%20game%20change.md)): tests, docs, a world-event mark for a rule change, and the regenerated `game_data.sql` and `balance-report.md`. Open the pull request, naming the tuning issue, and wait for the project lead to merge it.

   7. <a id="3.7"></a>After the deploy, follow it per [AD-03](../SOPs/AD-03%20Deploying%20to%20the%20live%20server.md). Then post the live measure on the issue a real day later and a real week later, or ask the project lead to, and run a playtest round aimed at the change per the "Machine instructions for running a playtest round" ([DV-02-M1](DV-02-M1%20Machine%20instructions%20for%20running%20a%20playtest%20round.md)).

   8. <a id="3.8"></a>Post the result with the result block of [DV-03-T1](DV-03-T1%20Tuning%20issue%20template.md). Recommend keep, adjust or revert, with the reason, and wait for the project lead's decision.

   9. <a id="3.9"></a>Once decided, open a pull request that records it: the economy playbook's lever table, any promoted guard in `tools/balance/objectives.json`, and the regenerated report. For "adjust" or "revert", open the next tuning issue under #962.

4. <a id="4"></a>A balance review

   1. <a id="4.1"></a>Run the static check and the live measure. Collect the playtest objectives from the rounds since the last review.

   2. <a id="4.2"></a>Post on #962 one table of every objective: latest value, the value at the last review, its band, and guard or target. Below it, list the three objectives furthest from their bands and a proposed order for the backlog. Name any objective that no longer seems to measure what it should.

5. <a id="5"></a>Adding or changing an objective

   1. <a id="5.1"></a>Open a tuning issue that says what the objective is for and why its band is what it is.

   2. <a id="5.2"></a>Add it to `tools/balance/objectives.json` with the next free `PO-nn`, as a target. A static objective needs its figure in `static_check.py`, and a live one its figure in `measure_live.py`. Regenerate the report, and open the pull request.

6. <a id="6"></a>Provide a concise summary of activities performed and issues encountered when performing these instructions, including every objective that moved, the issues posted, and the pull request opened.
