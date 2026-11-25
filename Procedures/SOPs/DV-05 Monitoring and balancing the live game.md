# DV-05: Monitoring and balancing the live game

1. # <a id="1"></a>Purpose

   1. <a id="1.1"></a>This standard operating procedure (SOP) sets out the routine by which the project watches the live game's balance and decides when to adjust its parameters. A short check every day records where the game stands. A review every week turns what the checks show into, at most, one proposed tuning change. Once a month the whole balance is reviewed. Each change is then made and measured per [DV-03](DV-03%20Tuning%20gameplay%20parameters.md).

   2. <a id="1.2"></a>The aim is to see problems while they are small and cheap to fix, to keep a record from which trends can be read, and to change the game only when the evidence holds up over several days. Not on one odd reading, and not on a hunch.

   3. <a id="1.3"></a>DV-03 covers one tuning change from proposal to decision. This SOP is the routine around it: what is checked, how often, what counts as a signal, and how a signal becomes a tuning issue.

2. # <a id="2"></a>Roles and responsibilities

   1. <a id="2.1"></a>The project lead:

      1. <a id="2.1.1"></a>decides whether the routine runs, and when; asks Claude to set up its scheduled tasks; and pauses them when needed;

      2. <a id="2.1.2"></a>reads each weekly review and approves, changes or rejects its proposal;

      3. <a id="2.1.3"></a>changes a signal's threshold, through an issue, when it proves too quick or too slow to fire.

   2. <a id="2.2"></a>Claude:

      1. <a id="2.2.1"></a>runs the daily balance check with the "Machine instructions for the daily balance check" ([DV-05-M1](../Attachments/DV-05-M1%20Machine%20instructions%20for%20the%20daily%20balance%20check.md)) and posts each reading with the "Daily balance reading template" ([DV-05-T1](../Attachments/DV-05-T1%20Daily%20balance%20reading%20template.md));

      2. <a id="2.2.2"></a>runs the weekly review with the "Machine instructions for the weekly balance review" ([DV-05-M2](../Attachments/DV-05-M2%20Machine%20instructions%20for%20the%20weekly%20balance%20review.md));

      3. <a id="2.2.3"></a>keeps the balance tools working, per DV-03.

   3. <a id="2.3"></a>Developers and players may raise a balance concern through an issue, per [AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md). The next weekly review weighs it with the readings.

3. # <a id="3"></a>Required resources

   1. <a id="3.1"></a>The balance tools, in `tools/balance/` (see its README):

      1. <a id="3.1.1"></a>`measure_live.py`, the live playability objectives;

      2. <a id="3.1.2"></a>`watch.py`, the signals beside them: crowding, the House's bids, the frontier exchanges and newcomers;

      3. <a id="3.1.3"></a>`static_check.py` and `documents/balance-report.md`, the static objectives;

      4. <a id="3.1.4"></a>`objectives.json`, the objectives and their bands.

      All of them read only the public API and need no key or server login.

   2. <a id="3.2"></a>The balance log, #1032: an open issue that holds one comment per daily reading. It is the record from which trends are read.

   3. <a id="3.3"></a>The tuning backlog, #962, and its sub-issues; the playtest issue, #380; and the economy playbook, `documents/economy-playbook.md`.

   4. <a id="3.4"></a>A Claude cloud environment that can reach exmachinis.com, for the scheduled tasks.

4. # <a id="4"></a>General guidelines

   1. <a id="4.1"></a>This SOP will be formatted and maintained IAW [QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md).

   2. <a id="4.2"></a>**Readings are cheap, changes are not.** A daily check only looks and records. It never changes the game, the repository or a player's code. Only a weekly review proposes a change, and only the project lead approves one.

   3. <a id="4.3"></a>**A signal needs persistence.** One reading out of band is noise until it repeats. The signals in §5.3 fire only after several readings in a row, except a broken guard, an exploit, or players losing money widely, which are handled at once (§5.6).

   4. <a id="4.4"></a>**One family at a time.** While a tuning change is being measured (from its deploy to the decision, per DV-03 §5.5 and §5.6), no other change is proposed in its lever family. A signal in that family is noted in the review as "waiting on #N", not acted on.

   5. <a id="4.5"></a>**Know what else changed.** Every reading lists what reached `main` since the last one and whether it deployed. A move within two real days of a deploy that touched mining, prices, travel, stations or company rules is credited to that deploy first, not to the routine's tuning.

   6. <a id="4.6"></a>**The AI cohort is a proxy.** The AI players of [DV-04](DV-04%20Maintaining%20the%20AI%20player%20cohort.md) keep new companies coming, but they are Claude, not newcomers. Where people's results and the cohort's disagree, people's results decide. Readings and reviews:

      1. <a id="4.6.1"></a>never name or single out an AI player's account, company or drone, and give the cohort only in aggregate, per DV-04 §4.2;

      2. <a id="4.6.2"></a>never steer the cohort: nothing from a reading or review is passed to the AI players, who learn of a change only as any player does (DV-04 §4.5).

   7. <a id="4.7"></a>**Onboarding first.** Among signals, those on PO-01 to PO-03, a newcomer's first company, come first, then broken guards, then the objective furthest from its band (DV-03 §4.5).

   8. <a id="4.8"></a>**Keep it small.** A daily check takes at most 15 real minutes and about 60 reads of the API, with no subagents and no repository edits. A weekly review takes at most 30 minutes and drafts at most one tuning issue.

5. # <a id="5"></a>Procedures

   1. <a id="5.1"></a>**Setting up.** When the project lead asks, Claude sets up two recurring scheduled tasks. Each starts a fresh Claude session, in an environment that can reach exmachinis.com, from the current `main`:

      1. <a id="5.1.1"></a>the daily check, once a day at a fixed time away from the busiest hours and away from the AI players' check-in times: "Apply DV-05-M1";

      2. <a id="5.1.2"></a>the weekly review, once a week, a few hours after that day's check: "Apply DV-05-M2".

      To pause the routine, Claude disables both tasks. The project lead may also run either by hand.

   2. <a id="5.2"></a>**The daily check**, per [DV-05-M1](../Attachments/DV-05-M1%20Machine%20instructions%20for%20the%20daily%20balance%20check.md):

      1. <a id="5.2.1"></a>run `measure_live.py` and `watch.py`;

      2. <a id="5.2.2"></a>list what reached `main` since the last reading;

      3. <a id="5.2.3"></a>compare with the last readings on #1032, and mark each signal in §5.3 as new, continuing (with how many readings in a row) or cleared;

      4. <a id="5.2.4"></a>post the reading on #1032 with [DV-05-T1](../Attachments/DV-05-T1%20Daily%20balance%20reading%20template.md);

      5. <a id="5.2.5"></a>when a tuning change's day-1 or week-1 measure falls due (DV-03 §5.5.1), post that measure on its tuning issue too.

   3. <a id="5.3"></a>**Signals.** A signal fires when its condition holds for the number of readings in a row shown here:

      | Signal | Condition | Readings |
      |---|---|---|
      | Objective out of band | A live objective outside its band in `objectives.json` | 3 |
      | Sudden move | A live objective, or the working drones at a place, moves by more than a quarter of the figure in one day | 1, then checked against deploys |
      | Stuck miners | `watch.py` marks a mineral stuck: more miners than the crowding capacity on a site that pays less than another near site | 3 |
      | Empty frontier | A frontier exchange has no working drones and no trades | 3 |
      | Thin premium | A good sold at a frontier exchange gets less than 10% over the best inner exchange's bid | 3 |
      | Newcomers falling behind | A newcomer has less than it started with, or the newcomers' median net wealth falls below the startup race line for their median age | 2 |
      | Congestion | Processor congestion above 1×, or PO-20 above its band | 2 |

      The project lead changes a condition or count through an issue (§2.1.3). A new signal needs a figure in `watch.py` or `measure_live.py`.

   4. <a id="5.4"></a>**The weekly review**, per [DV-05-M2](../Attachments/DV-05-M2%20Machine%20instructions%20for%20the%20weekly%20balance%20review.md):

      1. <a id="5.4.1"></a>read the week's readings on #1032, the open tuning issues, the latest round on #380, the latest feedback summary on #1069 and what it put on #962 ([DV-06](DV-06%20Reviewing%20player%20feedback.md)), and any balance issues raised in the week;

      2. <a id="5.4.2"></a>list each signal that fired, with its trend, and what it most likely comes from: a tuning change, another deploy, the players' code, or the parameters themselves;

      3. <a id="5.4.3"></a>set aside signals that wait on a change in measurement (§4.4) or on a deploy (§4.5);

      4. <a id="5.4.4"></a>of the rest, choose the one that matters most (§4.7) and draft a tuning issue for it with the "Tuning issue template" ([DV-03-T1](../Attachments/DV-03-T1%20Tuning%20issue%20template.md)), as a sub-issue of #962, marked as a draft for the project lead;

      5. <a id="5.4.5"></a>post the review on #1032 and tell the project lead its headline and proposal. When no signal fires, say so in one line: that is a good week.

   5. <a id="5.5"></a>**Adjusting.** The project lead approves, changes or rejects the draft. An approved draft is made, measured and decided per DV-03. The routine's readings supply its day-1 and week-1 measures.

   6. <a id="5.6"></a>**At once.** These don't wait for the weekly review:

      1. <a id="5.6.1"></a>a broken guard, a free loop (PO-09), or any way to make credits from nothing is a bug: file it per AD-01 and tell the project lead in the session;

      2. <a id="5.6.2"></a>players losing money widely and suddenly, for example many companies going broke in a day, means telling the project lead at once, with the readings that show it.

   7. <a id="5.7"></a>**Monthly.** The monthly balance review (DV-03 §5.8) draws on the month's readings and weekly reviews. With it, Claude:

      1. <a id="5.7.1"></a>proposes any signal whose threshold fired too often or never;

      2. <a id="5.7.2"></a>proposes any figure a review needed that the tools don't give;

      3. <a id="5.7.3"></a>may suggest a balance question for the AI cohort, which the project lead asks through DV-04 §5.5 if they wish.

6. # <a id="6"></a>References

   1. <a id="6.1"></a>[AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md): Creating and resolving GitHub issues

   2. <a id="6.2"></a>[DV-03](DV-03%20Tuning%20gameplay%20parameters.md): Tuning gameplay parameters

   3. <a id="6.3"></a>[DV-03-T1](../Attachments/DV-03-T1%20Tuning%20issue%20template.md): Tuning issue template

   4. <a id="6.4"></a>[DV-04](DV-04%20Maintaining%20the%20AI%20player%20cohort.md): Maintaining the AI player cohort

   5. <a id="6.5"></a>[DV-06](DV-06%20Reviewing%20player%20feedback.md): Reviewing player feedback

   6. <a id="6.6"></a>[QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md): Writing and maintaining Ex Machinis standard operating procedures

   7. <a id="6.7"></a>`tools/balance/README.md` and `documents/economy-playbook.md`

7. # <a id="7"></a>Attachments

   1. <a id="7.1"></a>[DV-05-M1](../Attachments/DV-05-M1%20Machine%20instructions%20for%20the%20daily%20balance%20check.md): Machine instructions for the daily balance check

   2. <a id="7.2"></a>[DV-05-M2](../Attachments/DV-05-M2%20Machine%20instructions%20for%20the%20weekly%20balance%20review.md): Machine instructions for the weekly balance review

   3. <a id="7.3"></a>[DV-05-T1](../Attachments/DV-05-T1%20Daily%20balance%20reading%20template.md): Daily balance reading template
