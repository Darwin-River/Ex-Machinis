# Open-source pathway

How Ex Machinis becomes an MIT-licensed project that lives in its own GitHub organization and can be handed to new maintainers (#523). The [sustainability playbook](sustainability-playbook.md) covers money, promotion and the day-to-day hand-over; this page covers the code and its home.

## Where things stand

| Step | Status |
|---|---|
| MIT licence (`LICENSE`) | Done (#523) |
| `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, issue templates | Done (#523) |
| Full-history audit for secrets, personal data and third-party licences | Done (#523): findings below |
| Remove the commercial website template, its scripts, fonts and stock images; build our own design | Done (#524) |
| Personal email out of the wiki settings | Done (#525) |
| Player data and an Access lock file out of the current files | Done (#526) |
| Rotate old passwords and keys that are in git history | **To do: maintainer** |
| Contributors agree to MIT | **To do: maintainer** |
| Decide on the old player data in git history | **To do: maintainer** |
| Smaller clean-ups (below) | To do |
| Create the organization and transfer the repository | To do: maintainer |
| After the move: links, settings, Claude app | To do |

## Before the move: what only a maintainer can do

### 1. Rotate everything that was ever committed

The audit found **no live secrets in the current files**, but git history still holds old values, readable by anyone, because history isn't being rewritten. Treat each as public and change it wherever it's still in use:

- the game database password used in 2018–2020 (old `.env`, `game-engine/conf/*.conf`, `game-engine/scripts/.env` copies);
- the Laravel `APP_KEY` from that era;
- the `CRON_KEY` from that era (the app still uses a `CRON_KEY`: make sure the live one is different);
- the mail passwords for the old `@exmachinis.com` mailboxes;
- the wiki's database password, `$wgSecretKey` and `$wgUpgradeKey` from 2022.

Where a service is retired (old mailboxes, the old DigitalOcean server at the IP in `game-engine/doc/developers_guide.txt`), confirm it's off rather than rotating.

### 2. Get the contributors' agreement

MIT is a grant from whoever owns the copyright. Most of the history is by the maintainer, but two other people wrote a lot of it: **Carlos Rodríguez** (`crodnun`, much of `game-engine/`) and **Fernando Rivas** (`freelancefrivas`). Either:

- their contracts already assign their work to Darwin River Software LLC: note that on #523; or
- ask each of them to reply on #523 that they agree to their contributions being released under MIT.

Until then the `LICENSE` file states the intent, but this is the step that makes it solid.

### 3. Decide about old player data in history

An old database dump (`game-engine/sql/exmachinis_02082018.sql`, added 2018-02-08, removed 2019-03-21) holds names, email addresses and password hashes for eight accounts: three players, a spam sign-up and two developers. The website no longer has accounts, so the hashes protect nothing in the game, but people reuse passwords. Options:

- **Leave it** (it's been public since 2018) and note the decision on #523; or
- **Ask GitHub Support** to purge cached views of those commits, and email the three players if you have a way to reach them.

Rewriting history would break every existing clone and fork; it isn't recommended for this.

### 4. Smaller clean-ups

- `game-engine/machine/trace_machine` is a compiled program whose source (`trace_machine.c`) isn't in the repository. Commit the source or delete the binary.
- The logo (`html/images/logo.png`) looks original, but check the licence of the typeface it was drawn from.
- `soundasleep/html2text` in `composer.json` is no longer used; remove it.
- `HomeController::REPOSITORY_DOCS` links to the `main` branch; point it at `public-forge`.

Bundled code that stays, with its own notices: Lua 5.4 (`game-engine/lua54/`, MIT) and Richard Howe's eForth "embed" (`game-engine/src/embed/`, MIT). Bootstrap (MIT) and the npm and Composer dependencies are all permissive.

## The move

1. **Create an organization** on GitHub (free plan), named for the game, e.g. `ex-machinis`. Add at least one other trusted person as an **owner**.
2. **Transfer the repository**: Settings → General → Danger Zone → Transfer, to the new organization. GitHub keeps the issues, pull requests, stars and history, and redirects the old address (web and `git`) unless a new repository later takes the old name. Leave `Darwin-River/Ex-Machinis` unused for that reason.
3. **Reconnect what's tied to the repository:**
   - Install the Claude GitHub app on the new organization, so AI sessions can keep working on it.
   - Check Settings → Secrets and variables → Actions: the announce Action's `SITE_URL`, Bluesky and Mastodon settings (see the [playbook](sustainability-playbook.md#posting-automatically)).
   - Re-point any deploy keys or webhooks the server uses.

## After the move

- Make `public-forge` the **default branch**, so newcomers land where pull requests go.
- Add a **branch ruleset** on `public-forge`: pull requests only, CI must pass, squash merges only.
- Turn on **private vulnerability reporting** (Settings → Code security), which `SECURITY.md` points to.
- Add the labels the issue templates use (`bug`, `idea`) and a `good first issue` label.
- Update the `Darwin-River/Ex-Machinis` links: the site footer (`resources/views/layouts/app.blade.php`), `.github/ISSUE_TEMPLATE/config.yml`, `HomeController::REPOSITORY_DOCS` and its test, `deploy/README.md` and `documents/hosting.md`. Claude can do this in one pull request.
- Fill in the "who owns what" table in the [playbook](sustainability-playbook.md#who-owns-what).

## The website's design

The site's look is our own (#524) and MIT licensed with everything else:

- **Bootstrap 4** (MIT) gives the grid, navigation, dropdowns, alerts and tables.
- **`resources/assets/sass/site/`** holds the rest. `_tokens.scss` has every colour and font in one place, so restyling the site starts there.
- **Fonts:** Space Grotesk (headings) and JetBrains Mono (code), both under the SIL Open Font License, loaded from Google Fonts; body text uses the reader's system font.
- **No images to license:** the starfield, page headers and the home page's Earth-and-Moon drawing are CSS and inline SVG. The only images are the logo and an SVG favicon.
- **Page header:** every page starts with `<x-page-header>` (`resources/views/components/page-header.blade.php`): a title, an optional subtitle, and breadcrumbs.

After changing anything under `resources/assets/`, run `npm run build` and commit `html/build/`; CI checks it's up to date.
