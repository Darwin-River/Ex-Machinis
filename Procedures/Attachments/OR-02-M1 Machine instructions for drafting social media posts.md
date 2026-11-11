# OR-02-M1: Machine instructions for drafting social media posts

1. <a id="1"></a>Always review and follow the most recent version of these instructions.

2. <a id="2"></a>Scope

   1. <a id="2.1"></a>Apply these instructions when directed to draft social media posts or replies. You draft; the project lead publishes. Never try to post to a social media account yourself, and never run the Announce blog posts Action or `admin/announce.py` except as a dry run (`DRY_RUN=1`).

   2. <a id="2.2"></a>New blog posts are announced on Bluesky and Mastodon automatically when they reach `main`, per [OR-02](../SOPs/OR-02%20Engaging%20on%20social%20media.md) §5.2. Don't draft those announcements unless you are asked for a version to post by hand elsewhere.

3. <a id="3"></a>Drafting posts

   1. <a id="3.1"></a>Find what is worth posting since the last batch: pull requests merged into `main`, new blog posts, playtest rounds on #380, new teaching materials, and the latest supporter update. Read the last closed "Social posts" issue to see what was already posted and what drew attention.

   2. <a id="3.2"></a>Draft each post with the "Social media post template" ([OR-02-T1](OR-02-T1%20Social%20media%20post%20template.md)), following the voice and conduct rules in "Social media accounts and conduct" ([OR-02-A1](OR-02-A1%20Social%20media%20accounts%20and%20conduct.md)).

   3. <a id="3.3"></a>Fit each post to its channel's length limit and audience: 300 characters on Bluesky, 500 on Mastodon, and each Reddit community's own rules. Include a short Lua snippet or an image suggestion where it helps.

   4. <a id="3.4"></a>Check every fact, number and function name against the repository. Check every link resolves.

   5. <a id="3.5"></a>Keep to [OR-02](../SOPs/OR-02%20Engaging%20on%20social%20media.md) §4.4 and §4.5: every post links to the game or something useful there, posts teach or show, and the batch links to the support page at most once a month.

   6. <a id="3.6"></a>Post the batch in one issue titled "Social posts: {topic or month}" with the label `outreach`, attributed per [AD-01](../SOPs/AD-01%20Creating%20and%20resolving%20GitHub%20issues.md) §5.6, with a suggested day for each post.

4. <a id="4"></a>Drafting replies

   1. <a id="4.1"></a>Draft a reply only to a message the project lead has shared with you. Answer the question, link the doc or post that helps, and keep to [OR-02-A1](OR-02-A1%20Social%20media%20accounts%20and%20conduct.md).

   2. <a id="4.2"></a>If the message reveals a bug or a good idea, also post an issue for it, per the "Machine instructions for identifying and posting issues" ([AD-01-M2](AD-01-M2%20Machine%20instructions%20for%20identifying%20and%20posting%20issues.md)).

5. <a id="5"></a>Provide a concise summary of activities performed and issues encountered when performing these instructions, including the issue that holds the drafts.
