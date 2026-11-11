# AD-03-M1: Machine instructions for following a deploy

1. <a id="1"></a>Always review and follow the most recent version of these instructions.

2. <a id="2"></a>Scope

   1. <a id="2.1"></a>Apply these instructions after you merge a pull request into `main` on the project lead's say-so, per [AD-02](../SOPs/AD-02%20Working%20with%20branches%20and%20pull%20requests.md), and whenever you are directed to deploy or to check on a deploy, per [AD-03](../SOPs/AD-03%20Deploying%20to%20the%20live%20server.md).

3. <a id="3"></a>Following a deploy

   1. <a id="3.1"></a>You have no login to the server. Never ask for one, never accept server passwords or keys in chat, and never try to reach the server other than through the Deploy workflow.

   2. <a id="3.2"></a>Find the Tests workflow run for the merge commit on `main` and wait for it. If it fails, the Deploy workflow doesn't run: treat the failure as a broken `main`, open an issue per [AD-01](../SOPs/AD-01%20Creating%20and%20resolving%20GitHub%20issues.md) and tell the project lead.

   3. <a id="3.3"></a>When Tests passes, find the Deploy workflow run it started and wait for it to finish. When directed to deploy without a merge, start the Deploy workflow (`workflow_dispatch`, `force` only if told to) and follow that run.

   4. <a id="3.4"></a>If the run succeeded and its log ends with `deploy: deployed` and the commit, tell the project lead the commit is live, with a link to the run.

   5. <a id="3.5"></a>If the run succeeded but only noted that automatic deploys aren't set up, tell the project lead that nothing was deployed and point them to [AD-03](../SOPs/AD-03%20Deploying%20to%20the%20live%20server.md) §5.1.

   6. <a id="3.6"></a>If the run failed:

      1. <a id="3.6.1"></a>Read its log and find the `deploy: FAILED` lines.

      2. <a id="3.6.2"></a>Re-run it once only if it failed before the server's script started (for example, the SSH connection timed out). A second failure is real.

      3. <a id="3.6.3"></a>Open an issue with the `bug` label per [AD-01](../SOPs/AD-01%20Creating%20and%20resolving%20GitHub%20issues.md), quoting the `FAILED` lines. Never quote secrets; the log contains none, but check.

      4. <a id="3.6.4"></a>Tell the project lead at once: what failed, whether the game is likely still running (a failed build leaves the old containers up; a failed `migrate` or a service that isn't running means it may be down), and the rollback command the log gives, per [AD-03](../SOPs/AD-03%20Deploying%20to%20the%20live%20server.md) §5.4.

      5. <a id="3.6.5"></a>Work on a fix-forward pull request if the cause is clear, per [AD-01-M1](AD-01-M1%20Machine%20instructions%20for%20resolving%20GitHub%20issues.md). Don't merge it until the project lead says to.

   7. <a id="3.7"></a>If the merged change touched the game engine or added a database migration, remind the project lead that Lua drones restarted their `launched` function, or that the schema changed and a rollback needs the backup restored, per [AD-03](../SOPs/AD-03%20Deploying%20to%20the%20live%20server.md) §4.3 and §4.4.

4. <a id="4"></a>Provide a concise summary of activities performed and issues encountered when performing these instructions, including the commit deployed, the run's link and its result.
