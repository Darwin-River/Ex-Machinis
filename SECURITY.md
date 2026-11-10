# Security

## Reporting a problem

Please report security problems **privately**, not in a public issue: use **Security → Report a vulnerability** on this repository's GitHub page. A maintainer will reply within a week.

Useful things to include: what you found, how to reproduce it, and what someone could do with it.

## What's in scope

- The website and its API (`app/`, `routes/`, `resources/`).
- The game and events engines (`game-engine/`), including anything a drone's Lua script can do outside its sandbox.
- The deployment files (`deploy/`).

Exploiting a game rule to make your company rich is gameplay, not a security problem; please do report it as an ordinary issue, though, so the rule can be fixed.

## Secrets

The repository holds no live passwords or keys: they live in each server's `.env` (see [documents/hosting.md](documents/hosting.md)). If you find one in the code or its history, report it as above.
