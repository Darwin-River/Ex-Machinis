# Open-source pathway

How Ex Machinis became an MIT-licensed project that can be handed to new maintainers (#523). It lives at [`Darwin-River/Ex-Machinis`](https://github.com/Darwin-River/Ex-Machinis); Darwin River is now a shared GitHub organization rather than a company. The [sustainability playbook](sustainability-playbook.md) covers money, promotion and the day-to-day hand-over; this page covers the code and its home.

## Where things stand

| Step | Status |
|---|---|
| MIT licence (`LICENSE`), copyright David Rozak and the Ex Machinis contributors | Done (#523, #532) |
| `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, issue templates | Done (#523) |
| Full-history audit for secrets, personal data and third-party licences | Done (#523) |
| Remove the commercial website template; build our own design | Done (#524) |
| Personal email out of the wiki settings | Done (#525) |
| Player data and an Access lock file out of the current files | Done (#526) |
| READMEs, docs and site pages describe the game today, without the company | Done (#529, #531) |
| Set up the shared organization (below) | To do: maintainer |

## Decisions (November 2026)

- **The repository stays in Darwin River**, now a shared organization rather than a company (#560). Its address, issues, pull requests, stars and links all stay as they are.
- **Git history is left as it is** (#560). The old passwords and keys in it are no longer live, and the 2018 users dump is no longer relevant. Rewriting history in place would change every commit ID, break every clone, fork and commit link, and still leave old copies reachable through past pull requests. The history clean-up script made for a new repository (#527) was removed.
- **Contributors:** Carlos Rodríguez and Fernando Rivas wrote their code under contract with the project's owner, who holds the copyright to it, so it can all be licensed under MIT without further permission.

## Setting up the shared organization

So the project doesn't depend on one person:

1. **Add at least one other trusted person as an owner** of the Darwin River organization (Settings → People), and give regular helpers write access to the repository.
2. **Make `public-forge` the default branch**, so newcomers land where pull requests go.
3. **Add a branch ruleset** on `public-forge`: pull requests only, CI must pass, squash merges only.
4. **Turn on private vulnerability reporting** (Settings → Code security), which `SECURITY.md` points to.
5. **Add the labels** the issue templates use, `bug` and `idea`, and a `good first issue` label.
6. **Set the Actions variables and secrets** for the announce Action (see the [playbook](sustainability-playbook.md#posting-automatically)).
7. Fill in the "who owns what" table in the [playbook](sustainability-playbook.md#who-owns-what).

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
