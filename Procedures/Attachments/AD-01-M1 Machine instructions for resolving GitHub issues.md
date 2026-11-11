# AD-01-M1: Machine instructions for resolving GitHub issues

1. <a id="1"></a>Always review and follow the most recent version of these instructions.

2. <a id="2"></a>Scope

   1. <a id="2.1"></a>Apply these instructions to the issue or issues you are directed to resolve, including one found or posted under the "Machine instructions for tracking requests with issues" ([AD-01-M3](AD-01-M3%20Machine%20instructions%20for%20tracking%20requests%20with%20issues.md)).

3. <a id="3"></a>Resolution

   1. <a id="3.1"></a>Read the issue's title, description and every comment. If the intent or scope is unclear, ask in the issue before starting rather than guessing. Check that the person asking has the permission to ask for the change, per [AD-01](../SOPs/AD-01%20Creating%20and%20resolving%20GitHub%20issues.md) §5.7.

   2. <a id="3.2"></a>Fetch `main` and work on a branch from it, per [AD-02](../SOPs/AD-02%20Working%20with%20branches%20and%20pull%20requests.md).

   3. <a id="3.3"></a>Follow the SOP that governs the work:

      1. <a id="3.3.1"></a>game code, game data, tests and player docs: [DV-01](../SOPs/DV-01%20Making%20and%20testing%20game%20changes.md);

      2. <a id="3.3.2"></a>a blog post: [OR-01](../SOPs/OR-01%20Writing%20and%20publishing%20blog%20posts.md);

      3. <a id="3.3.3"></a>teaching materials: [OR-03](../SOPs/OR-03%20Promoting%20Ex%20Machinis%20to%20schools%20and%20learners.md);

      4. <a id="3.3.4"></a>an SOP or attachment: [QA-01](../SOPs/QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md).

   4. <a id="3.4"></a>Make the change, run the checks the governing SOP requires, and commit with a message that names the issue number.

   5. <a id="3.5"></a>Push the branch and open a pull request into `main` that says `Closes #N` for every issue it resolves, per [AD-02](../SOPs/AD-02%20Working%20with%20branches%20and%20pull%20requests.md).

   6. <a id="3.6"></a>Post a comment on the issue that says what was done and links the pull request, attributed per [AD-01](../SOPs/AD-01%20Creating%20and%20resolving%20GitHub%20issues.md) §5.6.

   7. <a id="3.7"></a>Do not merge. The project lead merges, per [AD-02](../SOPs/AD-02%20Working%20with%20branches%20and%20pull%20requests.md).

4. <a id="4"></a>Provide a concise summary of activities performed and issues encountered when performing these instructions, including the pull request opened and the checks run.
