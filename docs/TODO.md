# To do (owner requests, not yet done)

Wording is the owner's. Completed items are removed from this list (the history is in git and docs/DECISIONS.md).

## New (2026-11-20)

2. I want to be able to adopt a feed already on my server and be able to manage it. (Owner, 2026-11-23; replaces "Import podcast", and replaces chunk E of TODO 5. The list and "New podcast" were done in 0.2.0. Plan and open questions: docs/PLANS.md.)
5. I want to replicate the features of https://sovereignfeeds.com or other podcast management tools. Build it. (Large: to be done in chunks, one per request; the proposed breakdown is in docs/DECISIONS.md. Chunks A (0.4.0), B (0.5.0), C (0.7.0) and D (0.8.0, 0.9.0) are done. Owner, 2026-11-23: E (import) is not required because TODO 2 covers it; G (raw XML editor) is not required; H, favourites, is not relevant, and live value block updating is out of scope at this stage. Left: F, Podcast Index and Podping; the owner picks.)
9. Bug: selecting a category, I have to hold my mouse click and release on my selection. I should be able to click once to open the list and then a second time to make my selection. This should be carried across all drop-down lists for this plugin. (Owner, 2026-11-21.) (Investigated 2026-11-21: not reproduced; see docs/DECISIONS.md, open items. Waiting for the owner's browser and system.)
12. When importing or saving a feed, the old feed should be examined for unsupported tags that would be lost, and the details reported for confirmation before continuing and potentially losing any data. (Owner, 2026-11-23; replaces the earlier question. Plan: docs/PLANS.md.)
17. Need a way to search for a location (to fill in the name, coordinates, OpenStreetMap id and country). (Plan in docs/PLANS.md.)
19. Make value time splits importable from a .txt file or from pasted XML.
20. Split the podcast edit screen into tabs.
21. Ability to delete episodes and podcasts.
22. Option to publish a post for each new podcast and episode. This could include the show notes, and the RSS could contain a link to the post instead of the actual show notes, but this needs consideration. (Owner, 2026-11-23.) (Owner's answers, including a podcast page and the delete prompt, and the plan are in docs/PLANS.md.)
