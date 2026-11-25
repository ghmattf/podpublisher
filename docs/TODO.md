# To do (owner requests, not yet done)

Wording is the owner's. Completed items are removed from this list (the history is in git and docs/DECISIONS.md).

## New (2026-11-20)

5. I want to replicate the features of https://sovereignfeeds.com or other podcast management tools. Build it. (Large: to be done in chunks, one per request; the proposed breakdown is in docs/DECISIONS.md. Chunks A (0.4.0), B (0.5.0), C (0.7.0) and D (0.8.0, 0.9.0) are done. Owner, 2026-11-23: E (import) is not required because TODO 2 covers it; G (raw XML editor) is not required; H, favourites, is not relevant, and live value block updating is out of scope at this stage. Left: F, Podcast Index and Podping; the owner picks.)
9. Bug: selecting a category, I have to hold my mouse click and release on my selection. I should be able to click once to open the list and then a second time to make my selection. This should be carried across all drop-down lists for this plugin. (Owner, 2026-11-21.) (Investigated 2026-11-21: not reproduced; see docs/DECISIONS.md, open items. Waiting for the owner's browser and system.)

## New (2026-11-25, later)

38. Move "Live episodes" to the top of the Episodes tab.
39. In the episode "Audio" section: "Current file: trailer2.wav (48 MB, audio/wav) Open" - make "Open" a button.
40. Move the rest of the "Audio" section to a pop-up triggered by a "Replace audio" button (when audio is already assigned) or "Add audio" (when not yet assigned). Move the editable "Duration" into the pop-up, and show the duration of a set audio file as non-editable text.
41. During a file upload, replace all the pop-up fields with just the progress bar and status.
42. "Ok" in the pop-up sets the audio (disabled during an upload). "Cancel" cancels a current file upload (after a confirmation) or simply closes the pop-up when not uploading.
43. Chunked upload for images, transcripts, chapters and time-splits files (add to the list only; do not build yet).

