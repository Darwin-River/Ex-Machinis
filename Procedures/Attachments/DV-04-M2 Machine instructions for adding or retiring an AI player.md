# DV-04-M2: Machine instructions for adding or retiring an AI player

1. <a id="1"></a>Always review and follow the most recent version of these instructions.

2. <a id="2"></a>Scope

   1. <a id="2.1"></a>These instructions apply when the project lead asks Claude to add players to the AI player cohort, or to retire some, per [DV-04](../SOPs/DV-04%20Maintaining%20the%20AI%20player%20cohort.md).

3. <a id="3"></a>Adding a player

   1. <a id="3.1"></a>`GET https://exmachinis.com/api/v1/bots` and read each player's profile (`GET /api/v1/bots/{name}`). Note the persona IDs in use and the cohort's mix.

   2. <a id="3.2"></a>Write the new profile with the "AI player profile template" ([DV-04-T1](DV-04-T1%20AI%20player%20profile%20template.md)):

      1. <a id="3.2.1"></a>give it the next free persona ID (C-01, C-02 and so on; IDs are never reused);

      2. <a id="3.2.2"></a>make it distinct from every other player: background, personality, skills, play style;

      3. <a id="3.2.3"></a>keep the cohort's mix per [DV-04](../SOPs/DV-04%20Maintaining%20the%20AI%20player%20cohort.md) §4.8: every persona an adult, at least one new to programming, at most a quarter testing the rules, no real person and no class groups;

      4. <a id="3.2.4"></a>invent nothing that could identify a real person.

   3. <a id="3.3"></a>Choose an account name the person in the profile would choose. It must be an ordinary name, with nothing in it that hints at AI, Claude, a bot or the persona ID.

   4. <a id="3.4"></a>`POST /api/v1/bots` with `{name}`. On 422 for a taken name, choose another. The answer's password and token are not needed later, since each check-in takes a fresh token; don't keep them.

   5. <a id="3.5"></a>Save the profile with `PUT /api/v1/bots/{name}` (`profile`), and start the notes with a first entry ("joined on {date}").

   6. <a id="3.6"></a>Give it its first check-in per [DV-04-M1](DV-04-M1%20Machine%20instructions%20for%20an%20AI%20player%20check-in.md), so it starts its first startup.

4. <a id="4"></a>Retiring a player

   1. <a id="4.1"></a>Check in one last time per [DV-04-M1](DV-04-M1%20Machine%20instructions%20for%20an%20AI%20player%20check-in.md), and leave nothing that would hurt a person:

      1. <a id="4.1.1"></a>repay or let run every loan a person backed;

      2. <a id="4.1.2"></a>deliver or cancel its contracts;

      3. <a id="4.1.3"></a>withdraw its loan requests and standing orders that it can't honour;

      4. <a id="4.1.4"></a>close the companies it owns outright that would only decay. Public companies keep running for their shareholders.

   2. <a id="4.2"></a>Set its profile's status to "retired on {date}" with `PUT /api/v1/bots/{name}`, and add a last note on why.

   3. <a id="4.3"></a>A retired player is never checked in again. Its account stays; there is no deleting one.

5. <a id="5"></a>Provide a concise summary of activities performed and issues encountered when performing these instructions: the persona IDs added or retired, a one-line sketch of each, and the cohort's mix now. Give account names only if the project lead asks, and only in the session.
