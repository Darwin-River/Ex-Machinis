# DV-03: Tuning gameplay parameters

1. # <a id="1"></a>Purpose

   1. <a id="1.1"></a>This standard operating procedure (SOP) sets out how the project tunes the game's parameters toward written playability objectives. The parameters are where minerals are found, mining and refining, ship sizes, the recipes, fuel, taxes, the House's prices and demand, travel and the startup terms. Each change is proposed against an objective, checked against the game data before it ships, measured on the live world and in a playtest round, and then kept, adjusted or reverted.

   2. <a id="1.2"></a>The aim is a game that stays balanced as it grows. A newcomer's first company works, every step up the production chain pays, going deeper into the solar system is worth the trip, capital is put to work, and players have fun, find depth and come back. Each objective that is met becomes a guard that the build enforces, so balance only ratchets forward.

2. # <a id="2"></a>Roles and responsibilities

   1. <a id="2.1"></a>The project lead sets the playability objectives and their target bands, chooses which tuning change goes next, approves each change, and decides after measurement whether it is kept, adjusted or reverted.

   2. <a id="2.2"></a>Claude keeps the balance tools working, proposes tuning changes as issues, makes and measures them, and logs the results, using the "Machine instructions for a tuning cycle" ([DV-03-M1](../Attachments/DV-03-M1%20Machine%20instructions%20for%20a%20tuning%20cycle.md)) and the "Tuning issue template" ([DV-03-T1](../Attachments/DV-03-T1%20Tuning%20issue%20template.md)).

   3. <a id="2.3"></a>Developers and players may propose tuning changes and new objectives through an issue, per [AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md).

3. # <a id="3"></a>Required resources

   1. <a id="3.1"></a>The playability objectives, `tools/balance/objectives.json`: each objective's ID (`PO-nn`), metric, how it is measured (static, live or playtest), target band, and whether it is a guard or a target. They are the only place the bands are written.

   2. <a id="3.2"></a>The static balance check, `tools/balance/static_check.py`. It works out the static objectives from the game data that `game-engine/sql/game-data/build_game_data.py` builds, and writes `documents/balance-report.md`. CI runs it with `--check`.

   3. <a id="3.3"></a>The live measure, `tools/balance/measure_live.py`. It works out the live objectives from a world's public API, with no key and no server login.

   4. <a id="3.4"></a>The tuning backlog, #962, whose sub-issues are the proposed changes; the playtest issue, #380; and the economy playbook, `documents/economy-playbook.md`, whose lever tables record every change made.

   5. <a id="3.5"></a>How the tools work, and their limits, is in `tools/balance/README.md`.

4. # <a id="4"></a>General guidelines

   1. <a id="4.1"></a>This SOP will be formatted and maintained IAW [QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md).

   2. <a id="4.2"></a>**Objectives first.** Every tuning change names the objectives it serves. A change that serves none is not a tuning change: give it an ordinary issue per [DV-01](DV-01%20Making%20and%20testing%20game%20changes.md).

   3. <a id="4.3"></a>**One lever family a release.** Levers that move the same objective are a family. Change one family, measure, then move on: fixing one imbalance moves the problem, and changing two at once hides which did what (economy playbook lesson 31).

   4. <a id="4.4"></a>**Predict before changing.** A tuning issue says, in numbers, which objectives should move and by about how much, and when the change would be kept, adjusted or reverted. A surprise is the most useful result a round can give, and it can only be seen against a prediction.

   5. <a id="4.5"></a>**Keep failure cheap.** Every tuning change can be reverted in the next release. The newcomer path, a startup at the Moon with an unchanged starter, must not get worse: a change that worsens PO-01 to PO-03 is reverted unless the project lead decides otherwise.

   6. <a id="4.6"></a>**Guards ratchet.** A static target that is met, and holds through the next playtest round, is promoted to a guard. A guard is loosened only by the project lead, with the reason written in the tuning issue.

   7. <a id="4.7"></a>**Know the model's limits.** The static check prices everything at the House's listed prices, with no booms or slumps, import price, demand caps or crowding. It shows the shape of a change, not what players will earn. Decide on live and playtest results.

   8. <a id="4.8"></a>**Bands change through issues.** An objective, its metric or its band is changed like a lever: through a tuning issue that says why, approved by the project lead.

5. # <a id="5"></a>Procedures

   1. <a id="5.1"></a>**Choose the next change.**

      1. <a id="5.1.1"></a>Read the latest balance report, the latest live measure and the last playtest round on #380.

      2. <a id="5.1.2"></a>The project lead chooses, from the backlog (#962), the change that best serves the objective furthest from its band among those that matter most now. If no sub-issue covers it, Claude writes one with [DV-03-T1](../Attachments/DV-03-T1%20Tuning%20issue%20template.md) as a sub-issue of #962.

   2. <a id="5.2"></a>**Propose it.** The tuning issue states the objectives, the lever (where it is set, from and to), why, the prediction, how it will be measured, and when it would be kept, adjusted or reverted. Before the change is made, Claude runs the static check on a branch to fill in the static prediction, and the live measure for the baseline, and posts both on the issue. The project lead approves the proposal.

   3. <a id="5.3"></a>**Make it**, per [DV-01](DV-01%20Making%20and%20testing%20game%20changes.md):

      1. <a id="5.3.1"></a>Change the lever. Rebuild the game data with `python3 game-engine/sql/game-data/build_game_data.py` and the report with `python3 tools/balance/static_check.py`, and commit both.

      2. <a id="5.3.2"></a>No guard may break. The pull request's diff of `documents/balance-report.md` shows every static objective that moved.

      3. <a id="5.3.3"></a>Update the docs the change affects, per [DV-01-A1](../Attachments/DV-01-A1%20Docs%20to%20keep%20current.md), and add a world-event mark for the rule change (`config/world-events.php`), so players can see on the charts when it happened.

   4. <a id="5.4"></a>**Ship it**, per [AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md) and [AD-03](AD-03%20Deploying%20to%20the%20live%20server.md). Merge only when the project lead says so.

   5. <a id="5.5"></a>**Measure it.**

      1. <a id="5.5.1"></a>Run the live measure about a real day after the deploy and again about a real week after, and post each table on the tuning issue. While the daily balance check of [DV-05](DV-05%20Monitoring%20and%20balancing%20the%20live%20game.md) runs, it posts these.

      2. <a id="5.5.2"></a>Run a playtest round aimed at the change, per [DV-02](DV-02%20Running%20playtest%20rounds.md), and score its playtest objectives.

   6. <a id="5.6"></a>**Decide.** Post the result on the tuning issue with the result block of [DV-03-T1](../Attachments/DV-03-T1%20Tuning%20issue%20template.md). The project lead decides:

      1. <a id="5.6.1"></a>**keep** it: it met its objectives, or moved them the right way, without breaking a guard or worsening onboarding;

      2. <a id="5.6.2"></a>**adjust** it: another step of the same lever, as a new tuning issue;

      3. <a id="5.6.3"></a>**revert** it: it moved the wrong way, broke a guard or worsened onboarding. The revert is a tuning change like any other.

   7. <a id="5.7"></a>**Record it.**

      1. <a id="5.7.1"></a>Add the change to the economy playbook's lever table (from, to, effect, and the objective IDs), and any lesson to its lessons.

      2. <a id="5.7.2"></a>Promote each static target the change met, and which held through the round, to a guard in `objectives.json`, and regenerate the report.

      3. <a id="5.7.3"></a>Close the tuning issue when the pull request that records the result is merged.

   8. <a id="5.8"></a>**Review the balance.** Every five playtest rounds, or once a month if sooner, Claude posts a balance review on #962: every objective's latest value and trend, the guards, the targets furthest from their bands, and a proposed order for the backlog. The project lead reorders the backlog and revises objectives or bands through tuning issues. Between reviews, the routine of [DV-05](DV-05%20Monitoring%20and%20balancing%20the%20live%20game.md) checks the live game daily and proposes changes weekly.

6. # <a id="6"></a>References

   1. <a id="6.1"></a>[AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md): Creating and resolving GitHub issues

   2. <a id="6.2"></a>[AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md): Working with branches and pull requests

   3. <a id="6.3"></a>[AD-03](AD-03%20Deploying%20to%20the%20live%20server.md): Deploying to the live server

   4. <a id="6.4"></a>[DV-01](DV-01%20Making%20and%20testing%20game%20changes.md): Making and testing game changes

   5. <a id="6.5"></a>[DV-01-A1](../Attachments/DV-01-A1%20Docs%20to%20keep%20current.md): Docs to keep current

   6. <a id="6.6"></a>[DV-02](DV-02%20Running%20playtest%20rounds.md): Running playtest rounds

   7. <a id="6.7"></a>[DV-05](DV-05%20Monitoring%20and%20balancing%20the%20live%20game.md): Monitoring and balancing the live game

   8. <a id="6.8"></a>[QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md): Writing and maintaining Ex Machinis standard operating procedures

7. # <a id="7"></a>Attachments

   1. <a id="7.1"></a>[DV-03-M1](../Attachments/DV-03-M1%20Machine%20instructions%20for%20a%20tuning%20cycle.md): Machine instructions for a tuning cycle

   2. <a id="7.2"></a>[DV-03-T1](../Attachments/DV-03-T1%20Tuning%20issue%20template.md): Tuning issue template
