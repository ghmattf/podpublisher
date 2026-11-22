# Plans

Plans for work that is not built yet. Nothing here is a decision until the owner agrees; once built, the decisions move to docs/DECISIONS.md and the plan is deleted from this file.

## TODO 14: log downloads with OP3 (op3.dev), set per podcast

**What OP3 is.** A free, open download counter for podcasts. No account or sign-up. The podcast's enclosure addresses get `https://op3.dev/e/` put in front; OP3 logs the request and sends the app on to the real file. Every measured show gets a free public stats page (`op3.dev/show/<id>`) and an API. Listener IP addresses are hashed with keys that change monthly and are not stored. (Source: op3.dev and github.com/skymethod/op3, read 2026-11-22.)

**Where the setting lives.** On each podcast (not in Settings), as the owner asked: one tick box, "Count downloads with OP3", off by default. It sits with the other feed-level options (or in its own "Statistics" tab once the edit screen is split into tabs, TODO 20).

**How it works.**
1. The address stored for each episode is never changed. The prefix is added only when the feed file is written, so switching it off and saving puts every address back and there is nothing to undo.
2. Prefix: `https://op3.dev/e/` + the full audio address including `https://`. Only the main `<enclosure>` is prefixed. Not prefixed: transcripts, chapters, pictures, alternate files, trailers, live streams (not what OP3 counts; to be confirmed against the OP3 API docs).
3. Only https audio addresses are prefixed (all addresses in this plugin are already https for hosted-elsewhere audio; files hosted by PodPublisher use the public address from Settings, which may be http, in which case the tick box warns that OP3 needs an address it can reach and that the owner should check the setup page).
4. An address that already starts with `op3.dev/e/` is never prefixed twice.
5. Optional (to confirm before building): OP3 can be told which show a download belongs to by adding the podcast guid to the prefix (`https://op3.dev/e,pg=<podcast:guid>/https://...`). I know this from memory of the OP3 documentation and have **not** read the API docs yet, so I would read https://op3.dev/api/docs and https://op3.dev/setup before building and use the guid form only if it is confirmed.
6. After saving, the podcast page shows a link to "Check my feed with OP3" (`https://op3.dev/setup`) and a field to paste the show's stats page address once OP3 has made one (it appears after the first downloads), shown as a "View download statistics" link.
7. The tick box text says in plain words: listeners' apps will contact op3.dev before downloading; OP3 hashes IP addresses; changing this later does not break anything.

**Work.** One checkbox and one text field in the podcast details (`op3`, `op3_stats_url`), the prefix in `PodPub_Episodes::feed_items` (the one place where the feed address is built), the page text. Small: about 0.9.1.

**Tests.** Off: addresses unchanged. On: prefix added; not added twice; not added to http addresses; not added to alternate files, transcripts or trailers; stored address unchanged; the stats link only when set and only if https.

**Questions for the owner.**
- Should the prefix also go on alternate files (video versions)? (My default: no.)
- Do you also want your own download log in WordPress? (My default: no. OP3 already keeps the log and WordPress would count app and bot requests wrongly.)
- Existing episodes: apply to all (my default; the guid, not the address, identifies an episode to apps).

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

**Questions for the owner.**
- Is this plugin only for your own sites, or will it be published? (Published: I would not ship with the public Nominatim as the default; I would ship with search off until the site owner enters a service.)
- Nominatim (stricter policy, well known) or Photon (looser, no guarantee)? My default: Nominatim, one click per search.
- Names: use the place name only ("Melbourne"), or the place with its region and country ("Melbourne, Victoria, Australia")? The spec limit is 128 characters. My default: place, region and country, editable.
