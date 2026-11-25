# DV-04-T2: End-of-round report template

An AI player's report on a cohort round, in its own voice, per [DV-04-M1](DV-04-M1%20Machine%20instructions%20for%20an%20AI%20player%20check-in.md) §3.3.9. It is kept in the feedback log, never in the repository: send it with `POST /api/v1/bots/{name}/feedback` as JSON with these fields, replacing each instruction in curly brackets. Keep it brief: a few sentences in each.

```json
{
  "round": "{the round just ended, an ISO week such as 2026-W48}",
  "experience": "{What the player did this round and how it went: what worked, what failed or confused them, a moment that stood out. Facts from the game, not guesses.}",
  "recommendations": "{One to three changes they would ask for, most wanted first, each with why. Say 'none' if they have none.}",
  "ratings": {"fun": "{0 to 10}", "clarity": "{0 to 10}", "feedback": "{0 to 10}", "overall": "{0 to 10}"}
}
```

`ratings` is optional, and its values are numbers. Leave out a rating the player has no view on.
