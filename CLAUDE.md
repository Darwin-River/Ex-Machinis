# Working on Ex Machinis

These rules apply to every Claude session on this repository.

## Every change has an issue

- Before starting a change, find or open a GitHub issue for it. One issue per change: a feature, a rule change, a bug, a doc rewrite.
- The issue says what is changing and why, in the requester's words where possible.
- The pull request names the issue (`Closes #N`). A PR that carries several changes names every issue it closes.
- A bug found while working gets its own issue, even when it is fixed in the same PR.
- Playtest rounds are logged as comments on the playtest issue (#380), not as new issues. A change that a round leads to still gets its own issue.

## Branches and merging

- Pull requests go into `public-forge`, never `main`.
- Squash-merge only, only when CI is green, and only when the user says to merge.

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
- `documents/blog/` when the change is worth a post. A new post is announced on Bluesky and Mastodon automatically when it reaches `public-forge`.

## Tests

- PHP: `vendor/bin/phpunit`
- Engine: `make test` in `game-engine/src`
- Game data: after editing `game-engine/sql/game-data/`, run `python3 game-engine/sql/game-data/build_game_data.py` and commit what it regenerates.

## Game philosophy

Break, learn, repeat. There is no test world: every release is live and free, so players learn by failing. Changes should keep failure cheap and feedback fast.
