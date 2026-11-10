# Sustainability playbook

How Ex Machinis pays for itself, finds players, and keeps going when its maintainer changes, for about an hour a month (#518).

## The goal

- **The game stays free and open.** No logins, no paid features, no ads. Anyone can release a drone in minutes.
- **Donations cover running costs, nothing more.** The server, the domain and the AI subscription that does most of the building. Money beyond that isn't the point; if pledges ever run well over the goal, lower the goal or spend the surplus on the game (a bigger server, a second maintainer's AI subscription).
- **Promotion rides on work that is already done.** Every change already has an issue, a PR and, when it matters to players, a blog post. Those are the posts.
- **Anyone can take over.** Every account, secret and routine is written down here, and nothing depends on one person's memory.

## The budget

Check these once a year and update the Patreon goal to match.

| Cost | Typical | Notes |
|---|---|---|
| Server | about $20–25/month | Hetzner CPX31 plus its 20% backups; see [hosting.md](hosting.md#choose-a-server). A smaller plan is fine until a few hundred drones run at once. |
| Domain | about $1–2/month | Billed yearly. |
| AI subscription | $20–100/month | Claude Pro covers steady upkeep; Max covers heavy building weeks. Move between them as the work needs. |
| Off-site backups | $0–1/month | Optional object storage for database dumps. |
| **Total** | **about $45–130/month** | |

Platforms take a share of each pledge: Patreon's fee depends on the plan the page joined under (roughly 5–12%), plus payment processing, which is heaviest on small pledges. **Set the Patreon goal at the total plus 15%**, rounded up: about $55/month with Pro, about $150/month with Max.

A zero-fee option alongside Patreon: GitHub Sponsors takes no fee on personal accounts. Add it to [`.github/FUNDING.yml`](../.github/FUNDING.yml) next to Patreon if you set it up; GitHub then shows both on the repository's **Sponsor** button.

## Patreon

### Set it up once

1. **One goal, shown publicly:** "Keep the servers on: $55 a month" (or whatever the budget says). Patreon shows progress on the page, which is the strongest reason anyone gives.
2. **Three tiers, none of which takes ongoing work:**

   | Tier | Price | What patrons get |
   |---|---|---|
   | Patron | $3/month | Their name on the [About page](../resources/views/pages/about-us.blade.php) sponsors list. |
   | Crew | $8/month | The above, plus a vote in the monthly "what next" poll. |
   | Sponsor | $25/month | The above, plus their company or school listed with a link. Aimed at teachers, clubs and companies. |

   Never put play behind a tier. The game's promise is that it's free; patrons pay so it stays that way.
3. **Turn on Patreon's free tier** (followers) so people can follow updates without paying.
4. **Pin a welcome post** that says, in three lines, what the money pays for, what the game is, and the link to play.

### Every month (one post)

One Patreon post a month, written from what already happened:

- the PRs merged since the last post (one line each, linking the blog posts);
- the playtest round notes on #380;
- the month's costs against pledges, in one line ("Costs $48, pledges $61: covered, thank you");
- the poll for next month's focus (Crew and up).

Claude can draft this: ask a session to "draft this month's Patreon update from the PRs merged since <date> and the #380 comments". Patreon has no API for posting, so pasting it in is the one manual step. A [scheduled task](https://code.claude.com/docs/en/claude-code-on-the-web) can do the drafting on the 1st of each month and leave the draft as a comment on #518.

## Social media

### Where

Pick few places and use them steadily. Where people who like programming games and learning to code already are:

- **Bluesky** and **Mastodon** (a gamedev or programming instance): posted automatically, see below.
- **Reddit**, by hand, only for big moments: r/ProgrammingGames, r/lua, r/learnprogramming (when the post teaches something), r/CSEducation (for teachers). Read each community's rules first; most want you to take part, not just post links.
- **itch.io**: a free page pointing at the website, with devlogs copied from the blog. It's where people browse for small games.
- **One launch post** on Hacker News (Show HN) when the game is ready for a crowd: the server table in [hosting.md](hosting.md#choose-a-server) says how big a server a busy world needs.

### What

The blog is the content. Posts in [`documents/blog/`](blog/) teach a strategy with working Lua, which is exactly what this audience shares. CLAUDE.md already asks for a post when a change is worth one.

Good extra posts cost a minute each:

- a screenshot or short clip of a drone's page doing something funny or clever;
- the leaderboard's top company of the week, with a link to its page;
- "this drone ran out of fuel halfway to Mars": failures are on-brand. Break, learn, repeat.

Every post links to the game, and to Patreon at most once a month.

### Posting automatically

When a new blog post reaches `public-forge`, the **Announce blog posts** Action ([`.github/workflows/announce.yml`](../.github/workflows/announce.yml), using [`admin/announce.py`](../admin/announce.py)) posts its title, summary and link to Bluesky and Mastodon. The run's summary also holds the same text, ready to paste into Patreon, Discord or Reddit.

Set it up once, in the repository's **Settings → Secrets and variables → Actions**:

| Kind | Name | Value |
|---|---|---|
| Variable | `SITE_URL` | The game's address, e.g. `https://exmachinis.example` |
| Variable | `BLUESKY_HANDLE` | The game's Bluesky handle, e.g. `exmachinis.bsky.social` |
| Secret | `BLUESKY_APP_PASSWORD` | An app password from Bluesky's **Settings → Privacy and security → App passwords** (not the account password) |
| Variable | `MASTODON_INSTANCE` | The instance's address, e.g. `mastodon.gamedev.place` |
| Secret | `MASTODON_TOKEN` | From the instance's **Preferences → Development → New application**, with the `write:statuses` scope |

Leave out either network's pair and it's skipped. To announce a post again, run the Action by hand from the **Actions** tab with the post's name (e.g. `10-fail-fast`). To try it without posting, run `DRY_RUN=1 SITE_URL=https://example.test python3 admin/announce.py documents/blog/10-fail-fast.md`.

The blog also has an RSS feed at `/blog/feed`. Free tools turn a feed into posts elsewhere (a Discord channel's RSS bot, Buffer, IFTTT, an itch.io devlog reminder), so new networks need no code.

## The monthly hour

On the first of each month:

1. **Costs (5 min).** Check the server, domain and AI bills against the budget table. Update the table and the Patreon goal if they moved.
2. **Patreon (15 min).** Read Claude's draft, fix what's off, post it, and open the poll.
3. **Sponsors list (5 min).** Add new Patron-and-up names to the About page (ask Claude to make the PR).
4. **Health (10 min).** Open the site and a drone page; check the last backup ran ([hosting.md](hosting.md#back-up-the-game)); glance at the Actions tab for red runs.
5. **One extra post (5 min).** A screenshot or leaderboard shout-out on Bluesky and Mastodon.

Anything else is optional. If a month is busy, steps 1 and 4 are the ones to keep.

## Handing it on

Set these up now, while there's no hurry, so a new maintainer can take over in an afternoon.

### Who owns what

Keep this table current. "Owner" is an account or organisation, not a person's private login, wherever the service allows it.

| Thing | Where | Owner | Who else has access |
|---|---|---|---|
| Code | GitHub `Darwin-River/Ex-Machinis` | Darwin-River organisation | |
| Server | Hetzner project | Darwin River Software LLC | |
| Domain | Registrar | Darwin River Software LLC | |
| Patreon | patreon.com/exmachinis | | |
| Bluesky, Mastodon | | | |
| AI subscription | | | |
| Shared passwords | A password manager's shared vault (Bitwarden's free organisation plan holds two people) | | |

### Steps

1. **Put every login in the shared vault**, including the server's SSH key passphrase, the registrar, Patreon and the social accounts, and add a second trusted person.
2. **Pay from one account** that belongs to the project, not a personal card, so bills don't stop when a card does.
3. **Add a second GitHub owner** on the organisation, and give helpers write access with branch protection on `public-forge` (CI green, one review).
4. **Keep the way of working in the repository.** [CLAUDE.md](../CLAUDE.md) already tells every AI session the rules: an issue per change, PRs into `public-forge`, docs kept current, tests to run. A new maintainer with their own AI subscription picks up the same workflow on day one.
5. **Choose a licence** and add a `LICENSE` file, so contributors know their work can be used and the project can move to new hands.
6. **Write the hand-off as an issue** when the time comes: the new maintainer's name, each row of the table above moved to them, and the date the old maintainer's access ends.

If no one takes over, the cheapest graceful ending is to keep the server running as long as pledges cover it, then publish a final database backup and leave the code public so anyone can host a world.

## What not to do

- **Don't paywall play, add ads or sell in-game advantages.** The low barrier is the whole strategy.
- **Don't promise rewards that take weekly work** (custom art, private builds, personal coaching).
- **Don't chase every network.** Two automated, one or two by hand, is plenty.
- **Don't run a separate test server.** The live game is the test world; one server keeps costs flat.
