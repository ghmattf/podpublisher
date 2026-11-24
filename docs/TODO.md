# To do (owner requests, not yet done)

Wording is the owner's. Completed items are removed from this list (the history is in git and docs/DECISIONS.md).

## New (2026-11-20)

5. I want to replicate the features of https://sovereignfeeds.com or other podcast management tools. Build it. (Large: to be done in chunks, one per request; the proposed breakdown is in docs/DECISIONS.md. Chunks A (0.4.0), B (0.5.0), C (0.7.0) and D (0.8.0, 0.9.0) are done. Owner, 2026-11-23: E (import) is not required because TODO 2 covers it; G (raw XML editor) is not required; H, favourites, is not relevant, and live value block updating is out of scope at this stage. Left: F, Podcast Index and Podping; the owner picks.)
9. Bug: selecting a category, I have to hold my mouse click and release on my selection. I should be able to click once to open the list and then a second time to make my selection. This should be carried across all drop-down lists for this plugin. (Owner, 2026-11-21.) (Investigated 2026-11-21: not reproduced; see docs/DECISIONS.md, open items. Waiting for the owner's browser and system.)

## New (2026-11-25)

28. When I open a podcast all the details are displayed right down the page like it was before changing to tabs - after a few seconds it gets hidden and the tabs work as expected. This is a bad user experience. The default tab is "Episodes" and nothing but what is expected on the active tab should be displayed. Items from other tabs should be hidden by default.
29. The "Save and write feed" button is not displayed on the "Episodes" tab but it should be.
30. Only one funding tag should be used. Put a single URL and Message field in the funding section and remove the table.
31. The Location section: add an "Add location" button that will pop up the Search (move that search feature to this pop up) and an option to manually input the details for a single location. Replace the current editable table with a nicer, non-editable list with an "Edit" and "Remove" button for each location item.
32. Do a similar thing as 30 for the "People" section, "Block" section, "Podroll" section, and "Comments (cross-app)" section.
33. Move the "Live episodes" section to the "Episodes" tab.
34. Episode "Explicit" option should default to blank when a new episode is created, and must be set to a non-blank option as mandatory.
35. For episodes do the same as 30 and 32 and also similar for the "Value time splits" section, "Chapters" section, "Transcripts" section, "Soundbites" section, and "Alternate files" section.
36. IMPORTANT! 0.17.0 created a new plugin instead of just upgrading the previous version of the plugin. Fix this in the next build.
