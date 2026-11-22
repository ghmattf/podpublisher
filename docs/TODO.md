# To do (owner requests, not yet done)

Wording is the owner's. Completed items are removed from this list (the history is in git and docs/DECISIONS.md).

## New (2026-11-20)

2. The page will show a list of the user's podcast feeds, and an option to create a new podcast, and import an existing podcast. (Done in 0.2.0: the list and "New podcast". Still to do: "Import podcast".)
5. I want to replicate the features of https://sovereignfeeds.com or other podcast management tools. Build it. (Large: to be done in chunks, one per request; the proposed breakdown is in docs/DECISIONS.md, chunks A (0.4.0), B (0.5.0), C (0.7.0) and D (0.8.0, 0.9.0) are done; the owner picks the next chunk.)
9. Bug: selecting a category, I have to hold my mouse click and release on my selection. I should be able to click once to open the list and then a second time to make my selection. This should be carried across all drop-down lists for this plugin. (Owner, 2026-11-21.) (Investigated 2026-11-21: not reproduced; see docs/DECISIONS.md, open items. Waiting for the owner's browser and system.)
10. Location tags: https://podcasting2.org/docs/podcast-namespace/tags/location (Done in 0.7.0: rel, geo, osm, country, several locations.)
12. What happens if I import a feed that has tags the plugin does not handle? What happens when I re-save? (Answer needed before building Import podcast: unknown tags would be lost on the next save unless kept as raw XML.)
15. Use this feed as the reference for all tags and features to include: https://phafe.com/wp-content/uploads/rssfeed/v4v_music_spotlight/V4V_Music_Spotlight.xml (Listed in docs/DECISIONS.md, decision 38.)
17. Need a way to search for a location (to fill in the name, coordinates, OpenStreetMap id and country). (Plan in docs/PLANS.md.)
19. Make value time splits importable from a .txt file or from pasted XML.
20. Split the podcast edit screen into tabs.
21. Ability to delete episodes and podcasts.
22. Option to publish a post for each new podcast and episode. This could include the show notes, and the RSS could contain a link to the post instead of the actual show notes, but this needs consideration. (Owner, 2026-11-23.)
