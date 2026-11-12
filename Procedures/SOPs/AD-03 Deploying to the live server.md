# AD-03: Deploying to the live server

1. # <a id="1"></a>Purpose

   1. <a id="1.1"></a>This standard operating procedure (SOP) sets out how a change on `main` reaches the live game at [exmachinis.com](https://exmachinis.com): automatically, once CI passes, through the **Deploy** workflow. It also covers setting deploys up, deploying by hand, what to do when a deploy fails, and how to pause deploys or revoke the deploy key.

2. # <a id="2"></a>Roles and responsibilities

   1. <a id="2.1"></a>The project lead owns the server and its accounts, holds the only login to it, sets up and revokes the deploy key, and decides when to merge. Because merging deploys, saying "merge" is saying "release".

   2. <a id="2.2"></a>Claude never has a login to the server and never asks for one. It deploys only by merging when the project lead says to, per [AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md), or by running the Deploy workflow when directed. After either, it follows the deploy and reports the result, using the "Machine instructions for following a deploy" ([AD-03-M1](../Attachments/AD-03-M1%20Machine%20instructions%20for%20following%20a%20deploy.md)).

   3. <a id="2.3"></a>Developers deploy the same way, by getting a pull request merged. Anyone with a login to the server may also deploy by hand, per §5.3.

3. # <a id="3"></a>Required resources

   1. <a id="3.1"></a>The server, set up per `documents/hosting.md`, with the game in `/opt/exmachinis` run by the `forge` user, and its backups in `/var/backups/exmachinis`.

   2. <a id="3.2"></a>`deploy/auto-deploy.sh`, which deploys: it backs up, pulls `main`, rebuilds and restarts, waits for the database update (`migrate`), and checks that every service stays up. It logs to `/var/backups/exmachinis/deploy.log`.

   3. <a id="3.3"></a>`.github/workflows/deploy.yml`, the Deploy workflow. It runs when the Tests workflow passes on a push to `main`, or when started by hand from the **Actions** tab, and logs in to the server with the deploy key.

   4. <a id="3.4"></a>Three repository secrets: `DEPLOY_HOST` (the server's address), `DEPLOY_SSH_KEY` (the deploy key) and `DEPLOY_KNOWN_HOSTS` (the server's fingerprint). The `production` environment on GitHub records each deploy.

   5. <a id="3.5"></a>`documents/hosting.md`, sections "Update the game", "Automatic updates", "If an update goes wrong" and "Restore from a backup".

4. # <a id="4"></a>General guidelines

   1. <a id="4.1"></a>This SOP will be formatted and maintained IAW [QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md).

   2. <a id="4.2"></a>Every merge to `main` is a release. There is no test world (break, learn, repeat), so merge when the change is ready for players to meet, and keep pull requests small, per [AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md).

   3. <a id="4.3"></a>Containers whose code didn't change keep running. When the game engine changes, Lua drones run their `launched` function again from the top; when only the website or the docs change, the drones aren't touched. Merge engine changes when few people are playing.

   4. <a id="4.4"></a>Database migrations only go forward. Every deploy takes a backup first, so a deploy that changed the schema can be undone by restoring that backup.

   5. <a id="4.5"></a>The deploy key can start a deploy and do nothing else: the server locks it to `deploy/auto-deploy.sh`. Keep it only in the `DEPLOY_SSH_KEY` secret; never copy it anywhere else.

   6. <a id="4.6"></a>Never edit files on the server's checkout. A change goes through a pull request like any other, and the script refuses to deploy over local changes.

   7. <a id="4.7"></a>Fix forward when you can: a small pull request that fixes the problem deploys itself once merged. Roll back by hand only when the live game can't wait for a fix.

5. # <a id="5"></a>Procedures

   1. <a id="5.1"></a>Setting up deploys (once, by the project lead):

      1. <a id="5.1.1"></a>Make sure the server has `deploy/auto-deploy.sh`; if it doesn't, update it by hand once (`documents/hosting.md`, "Update the game"). Make sure `forge` can write to the backups folder, `/var/backups/exmachinis` (`documents/hosting.md`, "The backup script"): every deploy backs up first, and the script refuses to run without it.

      2. <a id="5.1.2"></a>Make the deploy key on the server, lock it to the script, note the server's fingerprint, add the three secrets on GitHub, and delete the key files from the server, as `documents/hosting.md`, "Automatic updates", sets out step by step.

      3. <a id="5.1.3"></a>Test it: **Actions → Deploy → Run workflow**. The run should end with `deploy: already at …: nothing to deploy`.

      4. <a id="5.1.4"></a>Record the server and its deploy key in the "Who owns what" table of `documents/sustainability-playbook.md`.

   2. <a id="5.2"></a>Deploying a change:

      1. <a id="5.2.1"></a>Merge the pull request, per [AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md). The Tests workflow runs on `main`; when it passes, the Deploy workflow starts on its own.

      2. <a id="5.2.2"></a>Watch the Deploy run in the **Actions** tab. A deploy takes a few minutes; one that rebuilds the engines can take ten. It succeeds when its log ends with `deploy: deployed` and the commit.

      3. <a id="5.2.3"></a>Open the site and a drone page to see that the game is running. Check what the change was meant to do.

   3. <a id="5.3"></a>Deploying by hand, when a deploy needs repeating or the workflow can't run:

      1. <a id="5.3.1"></a>On GitHub: **Actions → Deploy → Run workflow**. Tick **force** to rebuild and restart even when the server already runs the latest `main`.

      2. <a id="5.3.2"></a>Or, logged in to the server as `forge`: `/opt/exmachinis/deploy/auto-deploy.sh` (add `--force` to rebuild anyway).

   4. <a id="5.4"></a>When a deploy fails:

      1. <a id="5.4.1"></a>Read the Deploy run's log. The script stops at the first problem and its `deploy: FAILED` line says which step failed, the command that shows the logs, and, once the code was updated, the command that goes back to the version that was running.

      2. <a id="5.4.2"></a>Open an issue for the failure with the `bug` label, per [AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md), quoting the `FAILED` lines.

      3. <a id="5.4.3"></a>If the game is still running (a failed build leaves the old containers up), fix forward: a pull request that fixes the problem deploys itself once merged.

      4. <a id="5.4.4"></a>If the game is down and can't wait, the project lead rolls back on the server with the command the log gives, per `documents/hosting.md`, "If an update goes wrong". If the failed deploy changed the database schema (its `migrate` step listed a new migration, or `migrate` failed), also restore the backup it took first, per "Restore from a backup".

      5. <a id="5.4.5"></a>After a rollback the server is off `main`, so automatic deploys stop until the fix is merged and the project lead runs `cd /opt/exmachinis && git checkout main` and deploys by hand, per §5.3.

      6. <a id="5.4.6"></a>If players were affected, tell them in the next blog post or social post, per [OR-02](OR-02%20Engaging%20on%20social%20media.md).

   5. <a id="5.5"></a>Pausing deploys, for example before a big change or while the server is being worked on: **Actions → Deploy → ⋯ → Disable workflow**, and enable it again afterwards. To approve each deploy instead, add the project lead as a required reviewer of the `production` environment (**Settings → Environments → production**).

   6. <a id="5.6"></a>Revoking the deploy key, if it may have leaked or a maintainer leaves: delete its line (ending `github-deploy`) from `/home/forge/.ssh/authorized_keys` on the server and the `DEPLOY_SSH_KEY` secret on GitHub, then make a new one per §5.1.2.

6. # <a id="6"></a>References

   1. <a id="6.1"></a>[AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md): Creating and resolving GitHub issues

   2. <a id="6.2"></a>[AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md): Working with branches and pull requests

   3. <a id="6.3"></a>[OR-02](OR-02%20Engaging%20on%20social%20media.md): Engaging on social media

   4. <a id="6.4"></a>[QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md): Writing and maintaining Ex Machinis standard operating procedures

7. # <a id="7"></a>Attachments

   1. <a id="7.1"></a>[AD-03-M1](../Attachments/AD-03-M1%20Machine%20instructions%20for%20following%20a%20deploy.md): Machine instructions for following a deploy
