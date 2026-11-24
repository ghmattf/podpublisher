# To do (owner requests, not yet done)

Wording is the owner's. Completed items are removed from this list (the history is in git and docs/DECISIONS.md).

## New (2026-11-20)

5. I want to replicate the features of https://sovereignfeeds.com or other podcast management tools. Build it. (Large: to be done in chunks, one per request; the proposed breakdown is in docs/DECISIONS.md. Chunks A (0.4.0), B (0.5.0), C (0.7.0) and D (0.8.0, 0.9.0) are done. Owner, 2026-11-23: E (import) is not required because TODO 2 covers it; G (raw XML editor) is not required; H, favourites, is not relevant, and live value block updating is out of scope at this stage. Left: F, Podcast Index and Podping; the owner picks.)
9. Bug: selecting a category, I have to hold my mouse click and release on my selection. I should be able to click once to open the list and then a second time to make my selection. This should be carried across all drop-down lists for this plugin. (Owner, 2026-11-21.) (Investigated 2026-11-21: not reproduced; see docs/DECISIONS.md, open items. Waiting for the owner's browser and system.)
19. Make value time splits importable from a .txt file or from pasted XML. (Owner, 2026-11-24: the file holds ready-made `podcast:valueTimeSplit` XML, as in the sample feed, but other formats must be expected and handled too, from an uploaded file or pasted text.)
20. Split the podcast edit screen into tabs.
22. Option to publish a post for each new podcast and episode. This could include the show notes, and the RSS could contain a link to the post instead of the actual show notes, but this needs consideration. (Owner, 2026-11-23.) (Owner's answers, including a podcast page and the delete prompt, and the plan are in docs/PLANS.md.)
25. Every uploaded file's name must be made safe for a URL path: spaces and other problem characters are replaced with underscores. (Owner, 2026-11-24.)
