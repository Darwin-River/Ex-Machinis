# OR-03: Promoting Ex Machinis to schools and learners

1. # <a id="1"></a>Purpose

   1. <a id="1.1"></a>This standard operating procedure (SOP) sets out how the project promotes Ex Machinis as a free, open environment for learning to code: for high school students and their teachers first, and for anyone in the general public who wants to learn programming by doing. It covers who we are speaking to, what we offer them, the teaching materials we build, and how we learn from the classrooms that use the game.

2. # <a id="2"></a>Roles and responsibilities

   1. <a id="2.1"></a>The project lead decides which audiences and partners to pursue, speaks for the project to schools and teachers, and approves all teaching materials.

   2. <a id="2.2"></a>Claude drafts teaching materials when directed, using the "Machine instructions for drafting a lesson" ([OR-03-M1](../Attachments/OR-03-M1%20Machine%20instructions%20for%20drafting%20a%20lesson.md)) and the "Lesson template" ([OR-03-T1](../Attachments/OR-03-T1%20Lesson%20template.md)), keeps them working as the game changes, and drafts messages to teachers for the project lead to send.

   3. <a id="2.3"></a>Developers keep the game easy to start for a newcomer: clear errors, working examples, and docs a student can follow, per [DV-01](DV-01%20Making%20and%20testing%20game%20changes.md).

   4. <a id="2.4"></a>Teachers who use the game are partners. Their feedback is recorded in issues, per [AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md).

3. # <a id="3"></a>Required resources

   1. <a id="3.1"></a>The player's manual (`documents/players-manual.md`), the Lua function list (`documents/lua-functions.md`) and the starter scripts in `examples/`.

   2. <a id="3.2"></a>The teaching materials folder, `documents/teaching/`, created with the first lesson.

   3. <a id="3.3"></a>"Audiences and messages" ([OR-03-A1](../Attachments/OR-03-A1%20Audiences%20and%20messages.md)).

4. # <a id="4"></a>General guidelines

   1. <a id="4.1"></a>This SOP will be formatted and maintained IAW [QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md).

   2. <a id="4.2"></a>Free means free. The game and all teaching materials stay free for schools and learners. Supporters on Ko-fi help pay the running costs; support never gates learning or brings a gameplay advantage, per [FR-01](FR-01%20Raising%20funds%20through%20Ko-fi.md) §4.2.

   3. <a id="4.3"></a>Protect students. The game needs no accounts and collects no personal data from players; keep it that way for anything aimed at students. Never name, picture or quote a student, or name their school, without written permission from the school and, for a student under 18, a parent or guardian. Talk to teachers and schools, never to students directly.

   4. <a id="4.4"></a>Teach transferable skills. Every lesson names the programming idea it teaches (variables, loops, functions, state machines, debugging, data) in terms a teacher can map to their course, and shows how it applies beyond the game.

   5. <a id="4.5"></a>Make lessons fit a real class: a 45 to 50 minute period, a shared computer lab, a browser and no installs.

5. # <a id="5"></a>Procedures

   1. <a id="5.1"></a>Keep [OR-03-A1](../Attachments/OR-03-A1%20Audiences%20and%20messages.md) current: who we are trying to reach, what each audience needs, and what we say to them.

   2. <a id="5.2"></a>Build teaching materials:

      1. <a id="5.2.1"></a>Open an issue for each lesson or unit, per [AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md).

      2. <a id="5.2.2"></a>Draft it per [OR-03-M1](../Attachments/OR-03-M1%20Machine%20instructions%20for%20drafting%20a%20lesson.md) in `documents/teaching/`, and merge it through a pull request, per [AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md).

      3. <a id="5.2.3"></a>Start with a short "first hour" lesson that gets a student from nothing to a drone that mines and sells, then build a sequence that follows the blog's ideas (state machines, buy orders, messages, experiments, failing fast).

   3. <a id="5.3"></a>Test lessons before teachers use them: run each one as a playtest persona who is new to code, per [DV-02](DV-02%20Running%20playtest%20rounds.md), and fix whatever the persona gets stuck on.

   4. <a id="5.4"></a>Keep lessons working. When a game change alters a lesson's code or steps, update the lesson in the same pull request, per the "Docs to keep current" checklist ([DV-01-A1](../Attachments/DV-01-A1%20Docs%20to%20keep%20current.md)).

   5. <a id="5.5"></a>Reach teachers:

      1. <a id="5.5.1"></a>The project lead contacts teachers, computer science departments, robotics and coding clubs, and teacher communities. Claude can draft these messages for the project lead to send.

      2. <a id="5.5.2"></a>Offer a pilot: a teacher runs a lesson with a class and tells us what worked. Record what they report in an issue labeled `education`.

      3. <a id="5.5.3"></a>With permission, share what classes did in a blog post or supporter update, per [OR-01](OR-01%20Writing%20and%20publishing%20blog%20posts.md) and [FR-01](FR-01%20Raising%20funds%20through%20Ko-fi.md).

   6. <a id="5.6"></a>Reach the general public through the blog ([OR-01](OR-01%20Writing%20and%20publishing%20blog%20posts.md)) and social media ([OR-02](OR-02%20Engaging%20on%20social%20media.md)), with the same promise: learn to code by flying drones in the real solar system, for free.

   7. <a id="5.7"></a>Review this outreach every three months in an issue: what was tried, what teachers said, and what to try next.

6. # <a id="6"></a>References

   1. <a id="6.1"></a>[AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md): Creating and resolving GitHub issues

   2. <a id="6.2"></a>[AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md): Working with branches and pull requests

   3. <a id="6.3"></a>[DV-01](DV-01%20Making%20and%20testing%20game%20changes.md): Making and testing game changes

   4. <a id="6.4"></a>[DV-01-A1](../Attachments/DV-01-A1%20Docs%20to%20keep%20current.md): Docs to keep current

   5. <a id="6.5"></a>[DV-02](DV-02%20Running%20playtest%20rounds.md): Running playtest rounds

   6. <a id="6.6"></a>[FR-01](FR-01%20Raising%20funds%20through%20Ko-fi.md): Raising funds through Ko-fi

   7. <a id="6.7"></a>[OR-01](OR-01%20Writing%20and%20publishing%20blog%20posts.md): Writing and publishing blog posts

   8. <a id="6.8"></a>[OR-02](OR-02%20Engaging%20on%20social%20media.md): Engaging on social media

   9. <a id="6.9"></a>[QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md): Writing and maintaining Ex Machinis standard operating procedures

7. # <a id="7"></a>Attachments

   1. <a id="7.1"></a>[OR-03-A1](../Attachments/OR-03-A1%20Audiences%20and%20messages.md): Audiences and messages

   2. <a id="7.2"></a>[OR-03-M1](../Attachments/OR-03-M1%20Machine%20instructions%20for%20drafting%20a%20lesson.md): Machine instructions for drafting a lesson

   3. <a id="7.3"></a>[OR-03-T1](../Attachments/OR-03-T1%20Lesson%20template.md): Lesson template
