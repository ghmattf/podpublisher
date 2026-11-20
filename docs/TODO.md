# To do (owner requests, not yet done)

Wording is the owner's. Completed items are removed from this list (the history is in git and docs/DECISIONS.md).

## New (2026-11-20)

2. The page will show a list of the user's podcast feeds, and an option to create a new podcast, and import an existing podcast. (Done in 0.2.0: the list and "New podcast". Still to do: "Import podcast".)
4. Settings should have a default path to write the podcast files. The files and directories must not contain any spaces or special characters that may cause url issues. Replace spaces with _ (underscore). Each podcast will have its own subdirectory which will have the podcast feed xml file, the podcast image and other files for the podcast. Each episode should have its own subdirectory within the podcast directory.
5. I want to replicate the features of https://sovereignfeeds.com or other podcast management tools. Build it. (Large: to be done in chunks, one per request; the proposed breakdown is in docs/DECISIONS.md.)
