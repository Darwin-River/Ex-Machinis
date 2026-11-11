# FR-01: Raising funds through Ko-fi

1. # <a id="1"></a>Purpose

   1. <a id="1.1"></a>This standard operating procedure (SOP) sets out how the project pays its running costs through one ask: the **Keep the Lights On** page (`/support`) and its Ko-fi button. It covers setting Ko-fi up, keeping the support page current each month, the monthly update to supporters, and the rules that keep the game free.

2. # <a id="2"></a>Roles and responsibilities

   1. <a id="2.1"></a>The project lead owns the Ko-fi account, posts every Ko-fi update, handles all money, pays the bills, and decides what to do with any surplus.

   2. <a id="2.2"></a>Claude, when directed, makes the monthly pull request that updates `config/support.php` and drafts the monthly supporter update, using the "Machine instructions for drafting the monthly supporter update" ([FR-01-M1](../Attachments/FR-01-M1%20Machine%20instructions%20for%20drafting%20the%20monthly%20supporter%20update.md)) and the "Monthly supporter update template" ([FR-01-T1](../Attachments/FR-01-T1%20Monthly%20supporter%20update%20template.md)). Claude has no access to Ko-fi, to supporters' details, or to money, and never posts outside GitHub.

   3. <a id="2.3"></a>Developers give the project lead material for updates: what they shipped and what is coming.

3. # <a id="3"></a>Required resources

   1. <a id="3.1"></a>The project's Ko-fi page, once it exists, and its account, held by the project lead. Its owner is recorded in the "Who owns what" table of `documents/sustainability-playbook.md`.

   2. <a id="3.2"></a>`config/support.php`, which holds the monthly costs, what supporters gave last month (`covered`) and which month that was (`month`), and the Ko-fi page's name (`kofi`, or `KOFI_NAME` in the server's `.env`). The **Keep the Lights On** page (`resources/views/pages/support.blade.php`, at `/support`) shows them, and every page links to it from the header, the menu and the footer. While `kofi` is blank, the page says Ko-fi is coming soon.

   3. <a id="3.3"></a>`.github/FUNDING.yml`, which shows the Sponsor button on GitHub once its `ko_fi` line is filled in.

   4. <a id="3.4"></a>`documents/sustainability-playbook.md`, especially "The budget", "Ko-fi and the support page", "The monthly hour" and "Handing it on".

4. # <a id="4"></a>General guidelines

   1. <a id="4.1"></a>This SOP will be formatted and maintained IAW [QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md).

   2. <a id="4.2"></a>Supporters keep the lights on; they do not buy anything. The game and its teaching materials stay free for everyone, with no paid features, no ads and nothing to buy. Supporters never get a gameplay advantage, and there are no tiers, no supporter-only posts and no rewards.

   3. <a id="4.3"></a>Support covers running costs only: the server, the domain and the AI subscription that does most of the building. If support runs well over the goal, lower the goal or spend the surplus on the game, such as a bigger server.

   4. <a id="4.4"></a>Be open about money. Each month's update gives the month's costs against support in one line, for example "Costs $47, support $52: covered, thank you." Use only the figures the project lead gives; never guess them.

   5. <a id="4.5"></a>Say "support" or "chip in", never "donate". The project is not a registered charity, so support is income to whoever runs it, not a tax-deductible donation.

   6. <a id="4.6"></a>Ask, don't nag. The pitch is the game: a free place where high school students and anyone else can learn to code. Social posts link to the support page at most once a month, per [OR-02](OR-02%20Engaging%20on%20social%20media.md) §4.4.

   7. <a id="4.7"></a>Keep supporters' details private. Thank a supporter by name in public only if they have agreed to it.

   8. <a id="4.8"></a>Other ways of raising money, such as education grants, school sponsorships, or moving to Open Collective if Ko-fi stops fitting, are proposed in an issue and agreed by the project lead before anyone pursues them. When one is adopted, add an SOP for it in the FR category.

5. # <a id="5"></a>Procedures

   1. <a id="5.1"></a>Set Ko-fi up once (project lead), per the playbook's "Set it up once":

      1. <a id="5.1.1"></a>make the Ko-fi page, with the game's name and logo and three lines on what the money pays for, what the game is, and the link to play;

      2. <a id="5.1.2"></a>turn on a Ko-fi goal for the monthly total;

      3. <a id="5.1.3"></a>put the page's name in `config/support.php` (`kofi`) or `KOFI_NAME`, and in the `ko_fi` line of `.github/FUNDING.yml`, through a pull request per [AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md);

      4. <a id="5.1.4"></a>record the account in the playbook's "Who owns what" table, and its login in the shared vault.

   2. <a id="5.2"></a>On the first of each month, the project lead tells Claude what Ko-fi received last month and directs it to apply [FR-01-M1](../Attachments/FR-01-M1%20Machine%20instructions%20for%20drafting%20the%20monthly%20supporter%20update.md). Claude:

      1. <a id="5.2.1"></a>opens a pull request that sets `covered` and `month` in `config/support.php`, per [AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md);

      2. <a id="5.2.2"></a>posts the draft update, built from what already happened (the pull requests merged since the last update, the month's new blog posts per [OR-01](OR-01%20Writing%20and%20publishing%20blog%20posts.md), and the playtest notes on #380), in an issue titled "Supporter update: {month year}", labeled `support`.

   3. <a id="5.3"></a>The project lead checks the month's bills against the budget. If the costs have moved, they are updated in `config/support.php` (the same pull request will do), in the playbook's budget table, and in the Ko-fi goal.

   4. <a id="5.4"></a>The project lead edits the draft, posts it on Ko-fi, comments on the issue with its link, closes the issue, and says when to merge the pull request.

   5. <a id="5.5"></a>Feedback and requests that come in through Ko-fi are brought into GitHub by the project lead as issues, per [AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md), without the supporter's name unless they agree. Supporters, like anyone, can also post issues themselves, per `CONTRIBUTING.md`.

   6. <a id="5.6"></a>Point people to the support page from the places they already meet the project: every page of the website links to it, the repository's `README.md` mentions it, and social posts may link to it at most once a month, per [OR-02](OR-02%20Engaging%20on%20social%20media.md).

   7. <a id="5.7"></a>Once a year, review the budget and the support page in an issue: check every cost, and check that nothing offered to supporters breaks §4.2.

   8. <a id="5.8"></a>When the project changes hands, moving the Ko-fi account and its payouts is part of the hand-over, per the playbook's "Handing it on".

6. # <a id="6"></a>References

   1. <a id="6.1"></a>[AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md): Creating and resolving GitHub issues

   2. <a id="6.2"></a>[AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md): Working with branches and pull requests

   3. <a id="6.3"></a>[OR-01](OR-01%20Writing%20and%20publishing%20blog%20posts.md): Writing and publishing blog posts

   4. <a id="6.4"></a>[OR-02](OR-02%20Engaging%20on%20social%20media.md): Engaging on social media

   5. <a id="6.5"></a>[QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md): Writing and maintaining Ex Machinis standard operating procedures

7. # <a id="7"></a>Attachments

   1. <a id="7.1"></a>[FR-01-M1](../Attachments/FR-01-M1%20Machine%20instructions%20for%20drafting%20the%20monthly%20supporter%20update.md): Machine instructions for drafting the monthly supporter update

   2. <a id="7.2"></a>[FR-01-T1](../Attachments/FR-01-T1%20Monthly%20supporter%20update%20template.md): Monthly supporter update template
