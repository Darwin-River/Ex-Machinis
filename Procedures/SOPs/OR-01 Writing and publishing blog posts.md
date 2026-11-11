# OR-01: Writing and publishing blog posts

1. # <a id="1"></a>Purpose

   1. <a id="1.1"></a>This standard operating procedure (SOP) sets out how the project writes and publishes posts for the Ex Machinis blog. The blog teaches: each post shows players, students and teachers a programming idea through the game, such as state machines, failing fast or cheap feedback loops, and tells them what has changed.

2. # <a id="2"></a>Roles and responsibilities

   1. <a id="2.1"></a>The project lead chooses what the blog covers and approves every post before it is merged.

   2. <a id="2.2"></a>Claude drafts posts when directed, using the "Machine instructions for drafting a blog post" ([OR-01-M1](../Attachments/OR-01-M1%20Machine%20instructions%20for%20drafting%20a%20blog%20post.md)) and the "Blog post template" ([OR-01-T1](../Attachments/OR-01-T1%20Blog%20post%20template.md)).

   3. <a id="2.3"></a>Developers suggest posts by opening an issue, and check posts about their own changes for accuracy.

3. # <a id="3"></a>Required resources

   1. <a id="3.1"></a>The blog folder, `documents/blog/`. The website shows each Markdown file there as a post at `/blog/<file name without .md>`, in file-name order (see `app/Http/Controllers/BlogController.php`).

4. # <a id="4"></a>General guidelines

   1. <a id="4.1"></a>This SOP will be formatted and maintained IAW [QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md).

   2. <a id="4.2"></a>Write for a curious high school student who has played for an hour. Use short sentences and plain words. Explain each programming idea the first time it appears.

   3. <a id="4.3"></a>Every post teaches one idea and includes Lua that runs as written on the current game. Test every code example before publishing.

   4. <a id="4.4"></a>Every post ends with something to try: a change to make to an example drone, and what to watch for.

   5. <a id="4.5"></a>Name no student and no school without written permission from the school, per [OR-03](OR-03%20Promoting%20Ex%20Machinis%20to%20schools%20and%20learners.md) §4.3.

5. # <a id="5"></a>Procedures

   1. <a id="5.1"></a>Open an issue for the post that says its idea and who it is for, per [AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md). Ideas come from changes worth explaining ([DV-01](DV-01%20Making%20and%20testing%20game%20changes.md) §5.5), playtest rounds ([DV-02](DV-02%20Running%20playtest%20rounds.md) §5.7), and questions from players, supporters and teachers.

   2. <a id="5.2"></a>Draft the post from [OR-01-T1](../Attachments/OR-01-T1%20Blog%20post%20template.md), named with the next number and a short slug, for example `11-reading-the-market.md`.

   3. <a id="5.3"></a>Open a pull request into `main`, per [AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md). The project lead reviews it; the post goes live when it is merged.

   4. <a id="5.4"></a>When it reaches `main`, the Announce blog posts Action posts its title, summary and link to Bluesky and Mastodon, per [OR-02](OR-02%20Engaging%20on%20social%20media.md) §5.2; nobody needs to post it by hand. Check the front matter's `title` and `summary` before merging, since they are what the Action posts. The post also goes into the next supporter update, per [FR-01](FR-01%20Raising%20funds%20through%20Ko-fi.md).

6. # <a id="6"></a>References

   1. <a id="6.1"></a>[AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md): Creating and resolving GitHub issues

   2. <a id="6.2"></a>[AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md): Working with branches and pull requests

   3. <a id="6.3"></a>[DV-01](DV-01%20Making%20and%20testing%20game%20changes.md): Making and testing game changes

   4. <a id="6.4"></a>[DV-02](DV-02%20Running%20playtest%20rounds.md): Running playtest rounds

   5. <a id="6.5"></a>[FR-01](FR-01%20Raising%20funds%20through%20Ko-fi.md): Raising funds through Ko-fi

   6. <a id="6.6"></a>[OR-02](OR-02%20Engaging%20on%20social%20media.md): Engaging on social media

   7. <a id="6.7"></a>[OR-03](OR-03%20Promoting%20Ex%20Machinis%20to%20schools%20and%20learners.md): Promoting Ex Machinis to schools and learners

   8. <a id="6.8"></a>[QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md): Writing and maintaining Ex Machinis standard operating procedures

7. # <a id="7"></a>Attachments

   1. <a id="7.1"></a>[OR-01-M1](../Attachments/OR-01-M1%20Machine%20instructions%20for%20drafting%20a%20blog%20post.md): Machine instructions for drafting a blog post

   2. <a id="7.2"></a>[OR-01-T1](../Attachments/OR-01-T1%20Blog%20post%20template.md): Blog post template
