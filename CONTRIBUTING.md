# Contributing to Ex Machinis

Thank you for helping. Ex Machinis is a free, open-source game that teaches programming: players write Lua for drones that mine, build and trade in our solar system. Contributions of every size are welcome: a typo fix, a bug report, a strategy post, a new game rule.

## Ways to help

- **Play and report.** Release a drone, watch it, and [open an issue](../../issues/new/choose) when something is wrong or confusing. Confusion is a bug too.
- **Write.** The [players' manual](documents/players-manual.md) and the [strategy blog](documents/blog/) are Markdown files in this repository.
- **Code.** The website is Laravel (PHP) with Vue; the engines are C, running Lua. The [README](README.md) explains how to run everything locally.

## How changes are made

These are the same rules every maintainer and every AI session follows; [CLAUDE.md](CLAUDE.md) is the full list.

1. **Every change has an issue.** Find or open one first, saying what changes and why. One issue per change.
2. **Branch from `main`, and open the pull request into `main`.** Name the issue in the description: `Closes #N`.
3. **Keep the docs current.** If players would notice the change, update the manual and the other documents listed in CLAUDE.md in the same pull request.
4. **Run the tests:** `vendor/bin/phpunit` for the website, `make test` in `game-engine/src` for the engines. CI runs them too.
5. **The project lead squash-merges** once CI is green and they agree to the change.

Small pull requests get reviewed fastest. If you're unsure whether a change is wanted, ask on the issue before writing it.

## The game's philosophy

Break, learn, repeat. There is no test world: every release is live and free, so players learn by failing. Changes should keep failure cheap and feedback fast, and keep the game free and easy to start.

## Licence

Ex Machinis is [MIT licensed](LICENSE). By contributing, you agree that your contribution is licensed the same way.

Please follow the [code of conduct](CODE_OF_CONDUCT.md).
