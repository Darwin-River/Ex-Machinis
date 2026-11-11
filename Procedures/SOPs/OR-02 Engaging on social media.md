# OR-02: Engaging on social media

1. # <a id="1"></a>Purpose

   1. <a id="1.1"></a>This standard operating procedure (SOP) sets out how the project uses social media to tell people about Ex Machinis, bring in new players, students and teachers, answer their questions, and point supporters to the **Keep the Lights On** page. New blog posts are announced on Bluesky and Mastodon automatically; everything else Claude drafts and a person posts.

2. # <a id="2"></a>Roles and responsibilities

   1. <a id="2.1"></a>The project lead owns the project's social media accounts, approves every post made by hand, and publishes it. Only people post by hand; Claude has no access to the accounts.

   2. <a id="2.2"></a>The **Announce blog posts** Action (`.github/workflows/announce.yml`, using `admin/announce.py`) posts each new blog post's title, summary and link to Bluesky and Mastodon when the post reaches `main`. It is the only thing that posts outside GitHub without a person, and it posts only what a merged blog post says. Claude does not run it to post; it runs on the merge the project lead approved.

   3. <a id="2.3"></a>Claude drafts posts and replies when directed, using the "Machine instructions for drafting social media posts" ([OR-02-M1](../Attachments/OR-02-M1%20Machine%20instructions%20for%20drafting%20social%20media%20posts.md)), and records them in an issue so the project lead can review, edit and publish them.

   4. <a id="2.4"></a>Developers suggest things worth posting by commenting on, or opening, an outreach issue.

3. # <a id="3"></a>Required resources

   1. <a id="3.1"></a>The accounts listed in "Social media accounts and conduct" ([OR-02-A1](../Attachments/OR-02-A1%20Social%20media%20accounts%20and%20conduct.md)).

   2. <a id="3.2"></a>The Announce blog posts Action, with its variables and secrets set in the repository's **Settings → Secrets and variables → Actions** (`SITE_URL`, `BLUESKY_HANDLE`, `BLUESKY_APP_PASSWORD`, `MASTODON_INSTANCE`, `MASTODON_TOKEN`), per "Posting automatically" in `documents/sustainability-playbook.md`. A network whose pair is not set is skipped.

   3. <a id="3.3"></a>The "Social media post template" ([OR-02-T1](../Attachments/OR-02-T1%20Social%20media%20post%20template.md)).

4. # <a id="4"></a>General guidelines

   1. <a id="4.1"></a>This SOP will be formatted and maintained IAW [QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md).

   2. <a id="4.2"></a>Follow the voice and conduct rules in [OR-02-A1](../Attachments/OR-02-A1%20Social%20media%20accounts%20and%20conduct.md), and the project's `CODE_OF_CONDUCT.md`, which covers its community spaces too. In short: be friendly, be accurate, teach something, and keep students safe.

   3. <a id="4.3"></a>Use few places, steadily: Bluesky and Mastodon, posted automatically for each blog post; Reddit, itch.io and one Show HN post, by hand and only for big moments. Don't chase every network. X is not used: its API costs money.

   4. <a id="4.4"></a>Every post links to the game or to something useful there: a blog post, the players' manual, a drone's page. A post links to the support page at most once a month.

   5. <a id="4.5"></a>Show, don't announce. The blog is the content. The best extra posts show a drone doing something: a short Lua snippet, a screenshot of a drone's page, the leaderboard's top company of the week, a failure and the fix.

   6. <a id="4.6"></a>Post steadily rather than in bursts: the automatic blog announcements, plus about one extra post a month on Bluesky and Mastodon, per "The monthly hour" in the playbook. The project lead can change this.

5. # <a id="5"></a>Procedures

   1. <a id="5.1"></a>Things worth posting come from merged changes ([DV-01](DV-01%20Making%20and%20testing%20game%20changes.md) §5.5), playtest rounds ([DV-02](DV-02%20Running%20playtest%20rounds.md)), new blog posts ([OR-01](OR-01%20Writing%20and%20publishing%20blog%20posts.md) §5.4), teaching materials ([OR-03](OR-03%20Promoting%20Ex%20Machinis%20to%20schools%20and%20learners.md)) and supporter updates ([FR-01](FR-01%20Raising%20funds%20through%20Ko-fi.md)).

   2. <a id="5.2"></a>When a new blog post reaches `main`, the Action announces it:

      1. <a id="5.2.1"></a>The run's summary, in the repository's **Actions** tab, holds the same text, ready for the project lead to paste by hand elsewhere, such as Ko-fi, Discord or Reddit.

      2. <a id="5.2.2"></a>If the run failed, or the post needs announcing again, the project lead runs the Action by hand from the **Actions** tab with the post's name, for example `10-fail-fast`.

      3. <a id="5.2.3"></a>To see what would be posted without posting, run `DRY_RUN=1 SITE_URL=https://example.test python3 admin/announce.py documents/blog/<post>.md`. Claude may run this; it posts nothing.

   3. <a id="5.3"></a>For anything else, when directed, Claude drafts a batch of posts per [OR-02-M1](../Attachments/OR-02-M1%20Machine%20instructions%20for%20drafting%20social%20media%20posts.md) and posts them in one issue titled "Social posts: {topic or month}", labeled `outreach`.

   4. <a id="5.4"></a>The project lead edits the drafts as needed, publishes them, and comments on the issue with a link to each published post. The issue is then closed.

   5. <a id="5.5"></a>Big moments, such as a launch or a major new feature, can also go to Reddit (r/ProgrammingGames, r/lua, r/learnprogramming when the post teaches something, r/CSEducation for teachers), an itch.io devlog, or a single Show HN post. Propose them in an outreach issue. Claude drafts them to each community's rules; the project lead reads those rules first and posts by hand.

   6. <a id="5.6"></a>Questions and comments from the public are answered by the project lead. Claude can draft replies when the project lead pastes the question into an issue or session. A question that reveals a bug, a doc gap or a good idea becomes an issue, per [AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md).

   7. <a id="5.7"></a>Once a month, in the monthly hour, the project lead posts one extra post on Bluesky and Mastodon and notes in that month's outreach issue which posts drew the most attention, so the next drafts can learn from them.

   8. <a id="5.8"></a>Adding or retiring an account is a change to [OR-02-A1](../Attachments/OR-02-A1%20Social%20media%20accounts%20and%20conduct.md), made through an issue and a pull request. The same pull request updates "Social media" and the "Who owns what" table in `documents/sustainability-playbook.md`, the Action and `admin/announce.py` if the account is to be posted to automatically, and any links to it on the website (the footer in `resources/views/layouts/app.blade.php`).

6. # <a id="6"></a>References

   1. <a id="6.1"></a>[AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md): Creating and resolving GitHub issues

   2. <a id="6.2"></a>[DV-01](DV-01%20Making%20and%20testing%20game%20changes.md): Making and testing game changes

   3. <a id="6.3"></a>[DV-02](DV-02%20Running%20playtest%20rounds.md): Running playtest rounds

   4. <a id="6.4"></a>[FR-01](FR-01%20Raising%20funds%20through%20Ko-fi.md): Raising funds through Ko-fi

   5. <a id="6.5"></a>[OR-01](OR-01%20Writing%20and%20publishing%20blog%20posts.md): Writing and publishing blog posts

   6. <a id="6.6"></a>[OR-03](OR-03%20Promoting%20Ex%20Machinis%20to%20schools%20and%20learners.md): Promoting Ex Machinis to schools and learners

   7. <a id="6.7"></a>[QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md): Writing and maintaining Ex Machinis standard operating procedures

7. # <a id="7"></a>Attachments

   1. <a id="7.1"></a>[OR-02-A1](../Attachments/OR-02-A1%20Social%20media%20accounts%20and%20conduct.md): Social media accounts and conduct

   2. <a id="7.2"></a>[OR-02-M1](../Attachments/OR-02-M1%20Machine%20instructions%20for%20drafting%20social%20media%20posts.md): Machine instructions for drafting social media posts

   3. <a id="7.3"></a>[OR-02-T1](../Attachments/OR-02-T1%20Social%20media%20post%20template.md): Social media post template
