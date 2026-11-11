# Ex Machinis standard operating procedures

These standard operating procedures (SOPs) say how the developers of Ex Machinis and Claude work together: to maintain the repository, track issues, improve the game, share it on social media, and pay its running costs through Ko-fi supporters, so that Ex Machinis can grow as a free place for high school students and anyone else to learn to code.

- **SOPs** are in [SOPs/](SOPs/). Each says who does what, and in what order.
- **Attachments** are in [Attachments/](Attachments/): machine instructions (M) that Claude follows, templates (T), and appendices (A).
- **Anchors**: every numbered item has a link anchor, so `…/AD-01%20Creating%20and%20resolving%20GitHub%20issues.md#5.6` opens AD-01 at §5.6. Run `python3 Procedures/Tools/update_anchors.py` after renumbering; `--check` only reports.

To have Claude do a task, name the machine instructions, for example: "Apply DV-02-M1 to run playtest round 42" or "Apply FR-01-M1 for November."

## Index

| ID | Title | Machine instructions and other attachments |
|---|---|---|
| [QA-01](SOPs/QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md) | Writing and maintaining Ex Machinis standard operating procedures | M1 proofreading SOPs, M2 reviewing SOP changes, T1 SOP template, T2 machine instructions template |
| [AD-01](SOPs/AD-01%20Creating%20and%20resolving%20GitHub%20issues.md) | Creating and resolving GitHub issues | M1 resolving issues, M2 posting issues, M3 tracking requests, M4 issue sweeps |
| [AD-02](SOPs/AD-02%20Working%20with%20branches%20and%20pull%20requests.md) | Working with branches and pull requests | None |
| [AD-03](SOPs/AD-03%20Deploying%20to%20the%20live%20server.md) | Deploying to the live server | M1 following a deploy |
| [DV-01](SOPs/DV-01%20Making%20and%20testing%20game%20changes.md) | Making and testing game changes | A1 docs to keep current, M1 making a game change |
| [DV-02](SOPs/DV-02%20Running%20playtest%20rounds.md) | Running playtest rounds | M1 running a round, T1 round comment for #380 |
| [OR-01](SOPs/OR-01%20Writing%20and%20publishing%20blog%20posts.md) | Writing and publishing blog posts | M1 drafting a post, T1 blog post |
| [OR-02](SOPs/OR-02%20Engaging%20on%20social%20media.md) | Engaging on social media | A1 accounts and conduct, M1 drafting posts, T1 social post |
| [OR-03](SOPs/OR-03%20Promoting%20Ex%20Machinis%20to%20schools%20and%20learners.md) | Promoting Ex Machinis to schools and learners | A1 audiences and messages, M1 drafting a lesson, T1 lesson |
| [FR-01](SOPs/FR-01%20Raising%20funds%20through%20Ko-fi.md) | Raising funds through Ko-fi | M1 drafting the monthly supporter update, T1 supporter update |

## Categories

- **QA**, quality assurance: keeping code, docs and SOPs correct and consistent.
- **AD**, administration: issues, branches, pull requests, merging and deploying.
- **DV**, development: changing, testing and playtesting the game.
- **OR**, outreach: the blog, social media, schools and learners.
- **FR**, fundraising: the Keep the Lights On page, Ko-fi and other support.

## Who does what

- **The project lead** sets direction, approves and merges pull requests, and is the only one who posts to social media and Ko-fi by hand or handles money. The Announce blog posts Action posts each new blog post to Bluesky and Mastodon by itself; nothing else is posted outside GitHub without a person.
- **Developers** make changes, review them, and bring ideas and feedback in as issues. Anyone can contribute, per [CONTRIBUTING.md](../CONTRIBUTING.md) and the [code of conduct](../CODE_OF_CONDUCT.md). The project is MIT licensed ([LICENSE](../LICENSE)) and lives in the shared [Darwin-River](https://github.com/Darwin-River/Ex-Machinis) organization on GitHub.
- **Claude** tracks every request in an issue, makes changes on a branch and opens pull requests, runs playtest rounds, and drafts blog posts, lessons, social posts and supporter updates for people to review and publish. Claude never merges on its own judgment and never posts outside GitHub.
