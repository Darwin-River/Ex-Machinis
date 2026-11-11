# AD-02: Working with branches and pull requests

1. # Purpose

   1. This standard operating procedure (SOP) sets out how changes reach the game: on a branch, through a pull request into `main`, checked by CI, and squash-merged only when the project lead says so. The public game runs from `main`, so every merge is a live release and this is also the release procedure.

2. # Roles and responsibilities

   1. The project lead reviews pull requests and is the only person who decides when one is merged.

   2. Developers and Claude open pull requests, keep them green, and answer review comments. Contributors from outside the team work the same way, per `CONTRIBUTING.md`.

   3. Claude never merges a pull request on its own judgment, and never pushes to a branch other than the one it was given for the session.

3. # Required resources

   1. Write access to the [Darwin-River/Ex-Machinis](https://github.com/Darwin-River/Ex-Machinis) repository, or a fork of it for contributors without write access.

   2. The CI workflows in `.github/workflows/`: `tests.yml`, which runs on every pull request and every push to `main` and must pass, and `load.yml`, the load test, which reports but is not required.

4. # General guidelines

   1. This SOP will be formatted and maintained IAW [QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md).

   2. `main` is the default branch and the branch the public game runs from. Pull requests go into `main`. (The old default branch was retired in #563 and deleted; don't open pull requests into it.)

   3. Break, learn, repeat. There is no test world: each merge is released to players for free, and the server takes it at its next update (`documents/hosting.md`, "Update the game"). Keep each pull request small enough that, if it breaks something, it is quick to see why and quick to fix or revert.

5. # Procedures

   1. Start every change from an up-to-date `main` on its own branch. Claude uses the branch named for its session.

   2. Commit in small steps with messages that say what changed and name the issue, for example `Order books read only open orders (#453)`.

   3. Open a pull request into `main`. Its description:

      1. says what changed and why, in plain words;

      2. lists `Closes #N` for every issue it resolves;

      3. lists the checks that were run and their results;

      4. lists the docs updated, per [DV-01](DV-01%20Making%20and%20testing%20game%20changes.md).

   4. Wait for CI. If it fails, reproduce the failure, fix it on the same branch and push again. Never skip, disable or delete a test to make CI pass.

   5. Answer every review comment, with a fix or with a reason, on the pull request.

   6. Merge only when all three are true: CI is green, the project lead has said to merge, and the branch is up to date with `main`. Merge with **Squash and merge** only, so each change is one commit on `main`.

   7. After a merge, check that the issues named in the pull request were closed, and delete the branch.

   8. If a merge breaks the live game, open an issue at once, then fix forward or revert in a new pull request. Tell players what happened in the next blog post or social post if it affected them, per [OR-02](OR-02%20Engaging%20on%20social%20media.md).

6. # References

   1. [DV-01](DV-01%20Making%20and%20testing%20game%20changes.md): Making and testing game changes

   2. [OR-02](OR-02%20Engaging%20on%20social%20media.md): Engaging on social media

   3. [QA-01](QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md): Writing and maintaining Ex Machinis standard operating procedures

7. # Attachments

   1. None.
