# Open-source pathway

How Ex Machinis becomes an MIT-licensed project that lives in a new repository in its own GitHub organization and can be handed to new maintainers (#523). The [sustainability playbook](sustainability-playbook.md) covers money, promotion and the day-to-day hand-over; this page covers the code and its home.

## Where things stand

| Step | Status |
|---|---|
| MIT licence (`LICENSE`) | Done (#523) |
| `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, issue templates | Done (#523) |
| Full-history audit for secrets, personal data and third-party licences | Done (#523) |
| Remove the commercial website template; build our own design | Done (#524) |
| Personal email out of the wiki settings | Done (#525) |
| Player data and an Access lock file out of the current files | Done (#526) |
| A cleaned copy of the history for the new repository | Ready (#527): `admin/clean-history.sh` |
| Create the new repository and push the cleaned history | To do: maintainer, then Claude |
| Move the open issues; archive the old repository | To do |

## Decisions (November 2026)

- **Old passwords and keys:** none of them is live any more, so nothing needs rotating. They are removed from the new repository's history instead (#527).
- **Contributors:** Carlos Rodríguez and Fernando Rivas wrote their code under contract with the project's owner, who holds the copyright to it, so it can all be licensed under MIT without further permission.
- **The 2018 users dump:** no longer relevant; it is dropped from the new repository's history (#527).
- **A new repository rather than a transfer:** so the project starts with a clean history.

## The cleaned history

`admin/clean-history.sh` copies the old repository and rewrites the copy; the old repository isn't touched. It:

- replaces every old password and key (database, `APP_KEY`, `CRON_KEY`, mail, wiki) with `REMOVED`, finding them by their setting names, so the script itself holds no secrets;
- drops the 2018 `users` dump, the old `.env` files and a 2021 engine log;
- drops the commercial template's scripts, styles, fonts and stock images (#524), which can't be distributed under MIT;
- replaces players' rows quoted in old notes and the old server's address with example values (found by pattern, so the script repeats none of them);
- then checks that none of the old values is left.

On the November 2026 history it kept 959 of 962 commits (three only touched removed files), and the repository shrank from 33 MB to 14 MB. A gitleaks scan of the result finds only an example token in `documents/api.md`.

Run it on the old repository's **latest** history, after the last pull request there is merged:

```sh
pip install git-filter-repo
admin/clean-history.sh https://github.com/Darwin-River/Ex-Machinis.git /tmp/clean --review   # see what it will do
admin/clean-history.sh https://github.com/Darwin-River/Ex-Machinis.git /tmp/clean
git -C /tmp/clean/repo.git push <new-repository-url> public-forge
git -C /tmp/clean/repo.git push <new-repository-url> --tags
```

Every commit gets a new hash. Commit messages keep their text, so `#123` references still read correctly, but they'll point at the new repository's issue numbers.

Old commits in the cleaned history won't build the website (the template they used is gone); the current code is unaffected.

## What moves to a new repository, and what doesn't

| | Moves? | How |
|---|---|---|
| Code, commits, authors, dates, messages | Yes | Pushed from the cleaned history |
| Branches and tags | Yes, the ones pushed | `public-forge` and the tags; old work branches aren't needed |
| Issues and their comments | **No** | Copied by script, or left in the archived old repository (below) |
| Pull requests and reviews | **No** | Stay readable in the archived old repository |
| Labels, milestones | No | Recreated (a few minutes), or copied by the issue script |
| Stars, watchers, forks | No | Start again |
| Actions settings, variables and secrets | No | Set again (the announce Action's, see the [playbook](sustainability-playbook.md#posting-automatically)) |
| Releases, wiki, Pages, webhooks, deploy keys | No | Set again if used |
| Old links to `github.com/Darwin-River/Ex-Machinis` | No redirect | The archived old repository says where the project went |

### Issues

Issue numbers are referenced all over the code and docs (`#324`, `#380` …). Two options:

- **Recommended: start fresh, keep the old ones readable.** Archive the old repository (Settings → Archive): it becomes read-only but stays public, so every `#N` in the history can still be looked up there. Copy only the **open** issues to the new repository, each linking back to the original.
- **Copy everything.** A script can copy every issue and comment through the GitHub API. But the numbers change (pull requests used half of them), the copies are posted by whoever runs it, with the original author and date written into the text; it takes hours for 500+ issues; and pull requests can't be copied as pull requests.

## The move

1. **Create an organization** on GitHub (free plan), named for the game, e.g. `ex-machinis`, with at least one other trusted person as an **owner**. Create an **empty** repository in it (no README, licence or `.gitignore`).
2. **Merge** the last pull request in the old repository, then **run the clean-up and push** (above).
3. **Set up the new repository:**
   - make `public-forge` the default branch;
   - add a branch ruleset on `public-forge`: pull requests only, CI must pass, squash merges only;
   - turn on private vulnerability reporting (Settings → Code security), which `SECURITY.md` points to;
   - add labels: `bug`, `idea`, `good first issue`;
   - set the Actions variables and secrets;
   - install the Claude GitHub app on the organization.
4. **Move the open issues** (Claude can do this once it has access to both repositories).
5. **Point everything at the new home** in one pull request: the site footer (`resources/views/layouts/app.blade.php`), `.github/ISSUE_TEMPLATE/config.yml`, `HomeController::REPOSITORY_DOCS` and its test, `deploy/README.md`, `documents/hosting.md`, and the README.
6. **On the server**, point the checkout at the new repository: `git remote set-url origin <new-url>`, then `git fetch` and reset to the new `public-forge` (the history was rewritten, so a plain pull won't work).
7. **Archive the old repository**, after adding a line at the top of its README saying where the project moved.
8. Fill in the "who owns what" table in the [playbook](sustainability-playbook.md#who-owns-what).

## Smaller clean-ups still open

- `game-engine/machine/trace_machine` is a compiled program whose source (`trace_machine.c`) isn't in the repository. Commit the source or delete the binary.
- The logo (`html/images/logo.png`) looks original, but check the licence of the typeface it was drawn from.
- `soundasleep/html2text` in `composer.json` is no longer used; remove it.

Bundled code that stays, with its own notices: Lua 5.4 (`game-engine/lua54/`, MIT) and Richard Howe's eForth "embed" (`game-engine/src/embed/`, MIT). Bootstrap (MIT) and the npm and Composer dependencies are all permissive.

## The website's design

The site's look is our own (#524) and MIT licensed with everything else:

- **Bootstrap 4** (MIT) gives the grid, navigation, dropdowns, alerts and tables.
- **`resources/assets/sass/site/`** holds the rest. `_tokens.scss` has every colour and font in one place, so restyling the site starts there.
- **Fonts:** Space Grotesk (headings) and JetBrains Mono (code), both under the SIL Open Font License, loaded from Google Fonts; body text uses the reader's system font.
- **No images to license:** the starfield, page headers and the home page's Earth-and-Moon drawing are CSS and inline SVG. The only images are the logo and an SVG favicon.
- **Page header:** every page starts with `<x-page-header>` (`resources/views/components/page-header.blade.php`): a title, an optional subtitle, and breadcrumbs.

After changing anything under `resources/assets/`, run `npm run build` and commit `html/build/`; CI checks it's up to date.
