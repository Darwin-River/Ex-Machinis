# FR-01-M1: Machine instructions for drafting the monthly supporter update

1. Always review and follow the most recent version of these instructions.

2. Scope

   1. Apply these instructions when directed to draft the monthly supporter update and update the support page, usually on the first of the month for the month before. You draft; the project lead posts on Ko-fi. You have no access to Ko-fi, to supporters' details, or to money.

3. Updating the support page

   1. Use only the amount and month the project lead gives you for what Ko-fi received. If you were not given them, ask; never guess.

   2. Fetch `main` and, on a branch, set `covered` (whole US dollars) and `month` (for example `October 2026`) in `config/support.php`. If the project lead gave new monthly costs, update `costs` too, and the budget table in `documents/sustainability-playbook.md` to match.

   3. Run `vendor/bin/phpunit` if you can, and open a pull request into `main` that names the update's issue, per [AD-02](../SOPs/AD-02%20Working%20with%20branches%20and%20pull%20requests.md). Do not merge it.

4. Drafting the update

   1. Gather the month's work: pull requests merged into `main` since the last update, new blog posts in `documents/blog/`, the playtest notes on #380, and any new teaching materials. Read the last "Supporter update" issue so you don't repeat it.

   2. Draft the update from the "Monthly supporter update template" ([FR-01-T1](FR-01-T1%20Monthly%20supporter%20update%20template.md)). Keep it short. Lead with what changed for players and learners, in plain words, and link the blog posts.

   3. Give the month's costs against support in one line, from the figures in `config/support.php`, for example "Costs $47, support $52: covered, thank you."

   4. Follow [FR-01](../SOPs/FR-01%20Raising%20funds%20through%20Ko-fi.md) §4: say "support" or "chip in", not "donate"; offer no rewards, tiers or gameplay advantage; name no supporter without consent; and give an honest picture of what is done and what is not.

   5. Check every fact and link against the repository.

   6. Post the draft in an issue titled "Supporter update: {month year}", labeled `support`, attributed per [AD-01](../SOPs/AD-01%20Creating%20and%20resolving%20GitHub%20issues.md) §5.6, and link the pull request from §3.3.

5. Provide a concise summary of activities performed and issues encountered when performing these instructions, including the issue that holds the draft and the pull request that updates the support page.
