# AD-01-M4: Machine instructions for sweeping issues

1. <a id="1"></a>Always review and follow the most recent version of these instructions.

2. <a id="2"></a>Scope

   1. <a id="2.1"></a>Apply these instructions when the project lead asks for an "issue sweep". A sweep reads every open issue and answers each one that is waiting on Claude, so that the conversation stays in the issues.

3. <a id="3"></a>Sweeping issues

   1. <a id="3.1"></a>Fetch `public-forge` and read what has changed since you last looked.

   2. <a id="3.2"></a>List every open issue. For each one, read its description, every comment and any pull request that refers to it.

   3. <a id="3.3"></a>Decide who the issue is waiting on, from its newest post:

      1. <a id="3.3.1"></a>a post without the "Posted by Claude" footer was written by a person, so the issue is waiting on Claude;

      2. <a id="3.3.2"></a>a post by Claude that asks a question or reports work done is waiting on a person, so leave it, and list it in the summary with who it waits on.

   4. <a id="3.4"></a>Check the permission of the person who wrote the newest post, per [AD-01](../SOPs/AD-01%20Creating%20and%20resolving%20GitHub%20issues.md) §5.7, and act only within it.

   5. <a id="3.5"></a>Answer each issue that is waiting on Claude according to what the newest post asks:

      1. <a id="3.5.1"></a>a question: answer it in a comment;

      2. <a id="3.5.2"></a>a request for a proposal: set out the proposed change in a comment and change nothing;

      3. <a id="3.5.3"></a>a direction to make a change, or approval of a proposal: resolve it per the "Machine instructions for resolving GitHub issues" ([AD-01-M1](AD-01-M1%20Machine%20instructions%20for%20resolving%20GitHub%20issues.md));

      4. <a id="3.5.4"></a>anything unclear, or a decision only the project lead can make: set out the options, recommend one, and ask.

   6. <a id="3.6"></a>Attribute and link every comment per [AD-01](../SOPs/AD-01%20Creating%20and%20resolving%20GitHub%20issues.md) §5.5 and §5.6.

   7. <a id="3.7"></a>Do not close issues during a sweep, and do not merge pull requests.

4. <a id="4"></a>Provide a concise summary of activities performed and issues encountered when performing these instructions, including every issue reviewed and what was posted, every pull request opened, and every issue waiting on a person.
