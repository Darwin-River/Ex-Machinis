# Working on Ex Machinis

These rules apply to every Claude session on this repository. They are the short version of the standard operating procedures in [`Procedures/`](Procedures/README.md): before a task, read the SOP that covers it and follow its machine instructions. If this file and an SOP disagree, post an issue for the project lead rather than guess.

## Every change has an issue

- Before starting a change, find or open a GitHub issue for it. One issue per change: a feature, a rule change, a bug, a doc rewrite.
- The issue says what is changing and why, in the requester's words where possible.
- The pull request names the issue (`Closes #N`). A PR that carries several changes names every issue it closes.
- A bug found while working gets its own issue, even when it is fixed in the same PR.
- Playtest rounds are logged as comments on the playtest issue (#380), not as new issues. A change that a round leads to still gets its own issue.

## Branches and merging

- Pull requests go into `main`, the default branch.
- Squash-merge only, only when CI is green, and only when the user says to merge.
- A merge to `main` deploys itself to the live server at exmachinis.com once CI passes. Follow the deploy and report it, per AD-03 (`Procedures/SOPs/AD-03 Deploying to the live server.md`). Never ask for a login to the server.

## Docs to keep current

When a change affects players, update the docs in the same PR:

- `documents/players-manual.md` (and its page under `resources/views/pages/`)
- `documents/design-manual.md`
- `documents/api.md`
- `documents/hosting.md`
- `documents/lua-functions.md`
- `documents/economy-playbook.md`
- `documents/sustainability-playbook.md` (costs, the support page, social accounts, who owns what)
- `documents/open-source-pathway.md` (the licence, the shared organization, the site's design)
- `documents/blog/` when the change is worth a post. A new post is announced on Bluesky and Mastodon automatically when it reaches `main`.

## Tests

- PHP: `vendor/bin/phpunit`
- Engine: `make test` in `game-engine/src`
- Game data: after editing `game-engine/sql/game-data/`, run `python3 game-engine/sql/game-data/build_game_data.py` and `python3 tools/balance/static_check.py`, and commit what they regenerate. No playability guard may break.

## Tuning the game

- Changes to the game's numbers that aim at balance or playability follow DV-03 (`Procedures/SOPs/DV-03 Tuning gameplay parameters.md`): each names its playability objectives (`tools/balance/objectives.json`), predicts their movement, changes one lever family a release, and is measured live and in a playtest round before it is kept, adjusted or reverted.
- Tuning issues are sub-issues of the backlog, #962, written with DV-03-T1.
- The live game's balance is checked routinely per DV-05 (`Procedures/SOPs/DV-05 Monitoring and balancing the live game.md`): a daily reading on the balance log (#1032) with `tools/balance/measure_live.py` and `watch.py`, and a weekly review that drafts at most one tuning issue. A signal acts only after it persists; readings never name or single out an AI player.

## The AI player cohort

- Claude runs a cohort of AI players on the live game per DV-04 (`Procedures/SOPs/DV-04 Maintaining the AI player cohort.md`): each an adult with its own profile, checking in, coding, investing and lending as a person would, under the same rules.
- No one may be able to tell which accounts are AI players. Their profiles and notes live only in the database (`/api/v1/bots`, with the operator's key). On GitHub, name a player only by its persona ID (C-01…), never its account, companies or drones.
- An AI player never claims to be a person and doesn't announce that it is AI. It tests the rules only against the cohort's own companies, loans and contracts, never at a person's cost.
- The cohort plays in weekly rounds. Each player reports its experience and recommendations at the end of a round, into the feedback log in the database (`/api/v1/bots/feedback`, the operator's Cohort feedback page), never on GitHub. A weekly review (DV-04-M4) summarizes the round and turns the cohort over: now and then an old player slows or retires and a new one joins.

## Outreach and fundraising

- Claude drafts blog posts, lessons, social media posts and supporter updates; people review and publish them.
- Claude never posts outside GitHub and never handles money. The Announce blog posts Action, which posts each new blog post to Bluesky and Mastodon when it reaches `main`, is the only automatic post.
- The game and its teaching materials stay free, and supporters never get a gameplay advantage.
- Never name or show a student or their school without written permission.

## Game philosophy

Break, learn, repeat. There is no test world: every release is live and free, so players learn by failing. Changes should keep failure cheap and feedback fast.
