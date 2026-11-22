# Plans

Plans for work that is not built yet. Nothing here is a decision until the owner agrees; once built, the decisions move to docs/DECISIONS.md and the plan is deleted from this file.

## TODO 17: search for a location

**Goal.** In each Location row, type a place name, press Search, pick a result, and the name, coordinates, OpenStreetMap id (`N`, `W` or `R` plus the number) and country code are filled in.

**What the public geocoders allow (read 2026-11-22).**
- **Nominatim** (nominatim.openstreetmap.org, run by the OpenStreetMap Foundation): at most 1 request per second for the whole application (all users together); the program must send an identifying User-Agent or Referer; results should be cached; **search-as-you-type (auto-complete) is forbidden**, including from the browser; the program must be switchable off by its operators; "OpenStreetMap contributors" attribution is required. Software handed to many users is held to the same total limit, so the policy asks for a caching proxy; for one owner's few sites this is fine, for a plugin published to the public it would not be.
- **Photon** (photon.komoot.io): fair use, no numbers, no guarantee, "may be throttled or banned"; its answer format and attribution were not on the page I could read.
- Others (Geoapify, Mapbox, Google) need keys and have their own terms.

**Recommended design.**
1. **A Search button, not search-as-you-type.** One request per click.
2. **The search is made by the WordPress server**, not the visitor's browser: a small admin-only request (`admin-ajax`, nonce, `manage_options`) calls the geocoder with a User-Agent of `PodPublisher/<version> (<site address>)`. The geocoder sees the server, not the editor's computer.
3. **Provider setting in Settings** ("Location search address", default Nominatim's public address, empty = search turned off). Anyone running their own Nominatim or a Nominatim-compatible service can paste its address. A short note in Settings gives the usage policy in one paragraph.
4. **Limits kept in the plugin:** at most one request per second site-wide (a short stored lock), results kept 30 days (same question costs nothing), 8-second timeout, up to 5 results, text of 3 to 120 characters, one search at a time per row.
5. **Result to fields.** Nominatim's JSON (`format=jsonv2&addressdetails=1&limit=5`) gives `display_name`, `lat`, `lon`, `osm_type` (node, way, relation), `osm_id` and `address.country_code`. They become: name = the first parts of the display name (editable), geo = `lat,lon` to 6 decimals, osm = `N`/`W`/`R` + id, country = the code in capitals. Clicking Use fills the first empty Location row (or says all rows are full). Nothing is saved until the form is saved.
6. **Fallback without any service:** a "paste an OpenStreetMap address" box that reads `https://www.openstreetmap.org/relation/113314` into `R113314`. Works with the search turned off.
7. Attribution line shown under the results: "Search results © OpenStreetMap contributors".
8. Any failure (offline, blocked, slow, rate limit) shows one plain sentence and never stops the form from saving.

**Work.** A small server class (`PodPub_Geo`: search, parse, cache, rate lock), one admin handler, a Settings field, a search box and about 40 lines of script on the Location rows, tests with a stubbed HTTP answer (parsing, caching, the one-per-second lock, bad input, permissions), and a DECISIONS entry. Medium: about 0.10.0.

**Answers from the owner (2026-11-22).** The plugin and its code are public but not planned for the WordPress plugin store, so shipping with the public Nominatim as the default is acceptable. Nominatim is the chosen service. Names: place, region and country, editable (default, not yet confirmed).

## TODO 22: WordPress posts for episodes and a page for the podcast

**Owner's answers (2026-11-23, two rounds).**
- Each episode can have an **ordinary WordPress post**. The show notes go **in both places**: in the post, and in the feed (the feed item also links to the post).
- **When the prompt appears:** only when the episode is being **published** (not a draft) and the owner clicks Save. The prompt is "Create/update WordPress post" yes/no. It is not shown for drafts.
- **When the post is made:** only **after the episode has been saved and published successfully**. An episode with errors that stop it publishing creates or edits no post.
- **Existing post:** the prompt itself carries the warning that saving with "yes" overwrites the post's previous title and content, and offers "no" to leave the post alone.
- **Podcast page:** the podcast editing screen has an option to create a **page** (not a post) for the podcast. The page lists all the podcast's episodes. The same screen has: an option to **turn off creating a post for each episode**, a list of **WordPress authors** to attribute the posts to, and **categories** for the posts.
- **Deleting an episode** asks whether to delete the matching post as well (belongs with TODO 21, delete episodes and podcasts).

**How it would work (my reading; to be confirmed when built).**
1. Episode form: Save with the status set to published asks "Create/update WordPress post?" (yes/no). With a draft status, no prompt and no post. If the podcast has posts turned off, no prompt.
2. Order on save: check and save the episode, write the feed; only if that succeeds and the episode is published, and the answer is yes, create or update the post. Any error: nothing is done to the post.
3. Post: title = episode title; content = the show notes (the same cleaned HTML); published with the episode date; author and categories from the podcast's settings; the episode picture as the featured image if there is one. The post's id is stored with the episode so later saves update the same post.
4. Feed: the item's `<link>` is the post's address once there is a post; the show notes stay in the feed.
5. Existing post: the prompt text says the post's title and content will be overwritten by the plugin's version and any edits made in WordPress will be lost. Default answer when a post already exists: **no** (my default, to confirm).
6. Podcast page: a yes/no "Create a page for this podcast" on the podcast screen. The page's content lists the episodes (title linked to its post if it has one, date), newest first, and is kept up to date when episodes are published. Stored page id with the podcast; if the page is deleted in WordPress, saving with "yes" makes a new one.
7. Podcast screen settings: "Create a WordPress post for each episode" (on or off), "Author for the posts" (a drop-down of WordPress users who can publish posts), "Categories" (tick list of WordPress categories). These are the defaults used in step 3.
8. Delete episode (with TODO 21): the confirmation asks "Also delete the WordPress post?" (yes/no).
9. Needs the rights to publish posts and pages for the person saving; if they lack them, the plugin says why no post or page was made.

**Tests.** Draft save: no post, no prompt. Published save with yes: one post, correct title, notes, date, author, categories; second save with yes updates the same post; no leaves it alone. An episode with an error creates or changes no post. Feed link is the post address. Posts turned off: no prompt. Podcast page lists the episodes and updates. Deleting with and without the post.

**Not yet answered (defaults in brackets).**
- What does the podcast page show besides the episode list (the podcast description, show art, subscribe links)? (Default: the description and the show art above the list.)
- Existing episodes saved before this feature: no bulk action; saving with "yes" creates the post. (Default.)
- No audio player in the post; the episode picture is the featured image. (Default.)
- Is the episode list on the page a plain list or does it include each episode's show notes? (Default: title, date and description only.)

## TODO 2 and 12: adopt a feed already on the server, and warn before data is lost

**Goal (owner, 2026-11-23).** Take a podcast feed that already exists on the server and manage it in PodPublisher. Whenever a feed is adopted, or an adopted feed is saved over its old file, the old feed is examined first for tags PodPublisher does not support, which would be lost; the details are shown and the owner confirms before anything is written.

**My reading (to confirm).**
1. **Adopt** takes the feed's address or its file on the server (an https address goes through `wp_safe_remote_get`, rule 3; a file path must be inside the Podcast folder), reads the channel and episodes into a new podcast and its episodes, and keeps the original feed address and each episode's `guid` unchanged so apps and directories see the same feed.
2. **Lost-tag check** compares the old feed with what PodPublisher would write: every channel or item tag or attribute it does not read (for example tags from other namespaces, or attributes it does not know) is listed with its place and a short sample. Nothing is written until the owner ticks "I understand these will be lost" (or cancels). The same check runs on every later save over a feed file that was adopted.
3. **Audio and pictures** stay where they are (the episode uses the existing https address; a file on this server can be picked as in 0.6.0).
4. **Open questions (defaults in brackets):** adopt by address only, or by file too (both); keep the old feed file as a backup copy next to the new one (yes, `name.xml.bak`); is the adopted feed's address kept as the podcast's own address even if it differs from the Podcast folder address (yes, with `itunes:new-feed-url` not touched).

**Tests.** A feed with known and unknown tags: the report lists exactly the unknown ones; cancel changes nothing; confirm writes; guids are kept; a feed with nothing unsupported needs no confirmation.
