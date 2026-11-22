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
