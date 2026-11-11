# DV-01-M1: Machine instructions for making a game change

1. <a id="1"></a>Always review and follow the most recent version of these instructions.

2. <a id="2"></a>Scope

   1. <a id="2.1"></a>Apply these instructions to any change to the website, the game engines, the game data, the Lua functions, the examples or the player docs.

3. <a id="3"></a>Making the change

   1. <a id="3.1"></a>Confirm the change is tracked by an issue, per the "Machine instructions for tracking requests with issues" ([AD-01-M3](AD-01-M3%20Machine%20instructions%20for%20tracking%20requests%20with%20issues.md)).

   2. <a id="3.2"></a>Read the parts of `documents/design-manual.md` that cover the area you are changing, and follow its conventions.

   3. <a id="3.3"></a>Make the smallest change that does the job. Add or update tests that show it works.

   4. <a id="3.4"></a>Run the checks in [DV-01](../SOPs/DV-01%20Making%20and%20testing%20game%20changes.md) §5.3 that apply. If a check cannot run in your environment, say so in the pull request rather than claiming it passed.

   5. <a id="3.5"></a>Work through the "Docs to keep current" checklist ([DV-01-A1](DV-01-A1%20Docs%20to%20keep%20current.md)) and update every doc the change affects. Write player docs in plain language a high school student can follow, with a short Lua example where it helps.

   6. <a id="3.6"></a>Open the pull request per [AD-02](../SOPs/AD-02%20Working%20with%20branches%20and%20pull%20requests.md), listing the checks run and the docs updated.

   7. <a id="3.7"></a>If the change is worth telling players about, say so in the pull request and suggest a blog post or social post.

4. <a id="4"></a>Provide a concise summary of activities performed and issues encountered when performing these instructions, including the checks run, their results and the docs updated.
