# Decisions, assumptions and open items

Status: v0.4.0. The plugin has its own admin menu (podcast list, New podcast, one sub-item per podcast, Settings). A podcast has its details form, show art and a feed file (chunk A); episodes, Podcasting 2.0 tags beyond the basics, Import podcast, Podcast Index and Podping are not built.

## Product decisions (stated by the owner)
- Project and plugin name is **PodPublisher** (decided 2026-11-20). Description: a WordPress plugin to manage Podcasting 2.0 feeds and files.
- Naming scheme: see `AGENTS.md` (slug and text domain `podpublisher`, prefix `podpub` / `PODPUB_`).
- Versioning: starts at **0.1.0**. Major version changes (including moving to 1.0.0) need the owner's explicit approval; features and fixes before that are minor/patch bumps.
- Admin menu (owner request, 2026-11-20): a top-level **PodPublisher** item (not under Settings). Its page lists the managed podcasts and has **New podcast** and **Import podcast**. Its sub-items are: All podcasts, one item per managed podcast (in A to Z order), then Settings (general plugin settings, last).
- Files on disk (owner request, 2026-11-20): Settings has a **Podcast folder** (default: a `podpublisher` folder inside the WordPress uploads folder) and a **Public address** for it. Each podcast has its own sub-folder holding its feed XML, its image and its other files; each episode has its own sub-folder inside the podcast folder. Folder and file names contain no spaces or special characters: spaces become `_` and anything outside A-Z a-z 0-9 `_` `-` is removed (`PodPub_Storage::slug`).
- Repo layout and working rules copied from `ghmattf/publisher-feed-generator` (read-only reference).

## Assumptions to verify (not confirmed)
1. Public GitHub repo `ghmattf/podpublisher` (assumed, same as the owner's other plugin repos).
2. Minimum WordPress 5.8 and PHP 7.4, copied from the feed generator plugin.
3. Release workflow and the PR-driven version bump work the same as in the feed generator.

4. (0.2.0.) Managed podcasts are stored as records of a private post type `podpub_feed` (no front-end URL, no WordPress editor screen), so they survive in the normal WordPress tables and are removed on uninstall only if the Settings box is ticked. Assumption: this is the right store; a custom table is the alternative.
5. (0.2.0.) Access needs the `manage_options` capability (administrators). Assumption: editors should not manage podcasts; change `PodPub_Admin::CAP` if they should.
6. (0.2.0.) The menu is built on every admin page load with one query for the podcast list; with a very large number of podcasts the sidebar would get long. Not a concern at small scale.
7. (0.2.0.) "New podcast" asks only for a title and creates the record, then opens that podcast's page, which is a placeholder.

8. (0.3.0.) Names keep the case the user typed (`My_Show`, not `my_show`), as only the spaces rule was requested. Assumption: lower-casing would be safer on case-sensitive servers and for URLs; two titles differing only by case get separate folders (`My_Show`, `my_show`) on Linux but would collide on Windows/macOS, where the second becomes `my_show_2`. Easy to change in `PodPub_Storage::slug`.
9. (0.3.0.) A duplicate name gets `_2`, `_3`, ... (`My_Show`, `My_Show_2`). Names are limited to 60 characters. A folder is never renamed after it is made (feed URLs must not change), even if the podcast title changes. If the title has nothing usable (for example only symbols) the name is `podcast` or `episode`.
10. (0.3.0.) A folder entered in Settings must be a full path with no `..` and only letters, numbers, `.`, `_` and `-` in each folder name; a path or address that breaks that is refused and the old value kept. A folder outside the uploads folder also needs its public address. A folder the web server cannot write to is saved with a warning. Each folder gets an empty `index.html` so servers do not list its contents. Changing the setting does not move podcasts already created; they stay where they are (their folder name is stored, the base is not), so after changing it, existing podcasts would be looked for in the new folder. **Not handled yet:** moving existing podcast folders when the setting changes.
11. (0.3.0.) Episode folders: the function exists and is tested (`PodPub_Storage::create_episode_dir`) but nothing calls it yet because episodes are not built.

12. (0.4.0, chunk A.) Each podcast's page has a details form: title, description (required, up to 4000 characters), language (list), category (list of the 19 top-level Apple Podcasts categories; no sub-categories yet), explicit, type (episodic or serial), author, website (http or https), copyright, owner name and email, and show art. Saving checks everything first and saves nothing if any field is wrong. A title change updates the podcast's name in the menu but never its folder or feed address.
13. (0.4.0.) The feed is written to `{podcast folder}/{folder name}.xml` on every save (RSS 2.0 with the itunes, atom, content and podcast namespaces): channel title, link, description, language, copyright, lastBuildDate, generator, atom self link, itunes author, owner, image, category, explicit, type, `podcast:guid` and `podcast:medium` podcast. Optional tags are left out when empty. It is written to a temporary file and renamed so a half-written feed is never served. **The feed has no episodes yet** (chunk B). Assumption: a feed with no episodes is acceptable to write; podcast directories will not accept it until it has at least one.
14. (0.4.0.) `podcast:guid` is made once, on the first save, and never changes: a UUID version 5 of the feed address (scheme and trailing slash removed) with the podcast namespace, as the Podcast namespace specification says (checked against its published example and an independent calculation); a random UUID if the public address is not known. Assumption: if the feed address later changes (for example the Podcast folder setting is changed), the GUID stays, which is what the specification wants, but the feed will need `itunes:new-feed-url` (not built).
15. (0.4.0.) Show art: JPEG or PNG only (checked by reading the file, not the name), up to 10 MB, stored as `cover.jpg` or `cover.png` in the podcast folder; the uploaded file's own name is never used and an old cover of the other type is removed. A picture that is not square, or not between 1400 and 3000 pixels, is accepted with a warning, not refused (assumption: warn only, because the owner may want to use it anyway). Not checked: file size in pixels over 3000, colour profile, or the picture being corrupt beyond its header. Show art is not resized or converted.
16. (0.4.0.) Text going into the feed has XML-forbidden and control characters removed, and is written through an XML writer (not string joining), so `&`, `<` and quotes in titles cannot break the feed.
17. (0.4.0.) Uninstall (when ticked) deletes the podcast records and settings but **leaves the files on disk** (feeds, images, episodes), because feeds are live and may be registered with directories. Assumption: this is wanted; it matches the feed generator plugin's rule that published XML is never deleted.
18. (0.4.0.) Only administrators can save details (`manage_options`, a nonce, and uploads only accepted when PHP says the file was really uploaded).

## Proposed breakdown for "replicate Sovereign Feeds or other podcast management tools" (TODO 5)
What sovereignfeeds.com offers, from its site (2026-11-20): create a feed (required info, show art, show info, owner, funding, location, cast, value, block, podroll); episode tabs (info, show notes, cast, cross-app comments, chapters, transcripts, value, funding, location, alternate enclosures); value time splits (with import from The Split Kit); hand-editing the feed XML; download the RSS file or send it to your server; register the feed with Podcast Index; Podping for updates and for live start/end; favourites; a value block updater. The site says it does no media hosting; PodPublisher writes the files on the WordPress site itself, so it also serves the feed and media. One chunk per request. Suggested order (owner to confirm or change):
- A. **Done in 0.4.0.** Podcast details form (title, description, language, category, explicit, owner, show art uploaded into the podcast folder) and writing the feed XML to `{podcast folder}/{name}.xml`.
- B. Episodes: list, add episode (creates its folder), upload the audio file, show notes, publish date, duration; episodes appear in the feed.
- C. Podcasting 2.0 tags: funding, location, cast (person), value block and time splits, block, podroll, license.
- D. Chapters, transcripts, alternate enclosures, soundbites, cross-app comments.
- E. Import podcast (from a feed URL or pasted XML; see the open question).
- F. Podcast Index: register the feed; Podping when a feed is updated and for live start/end.
- G. Raw XML editor with checks, and download.
- H. Live items, value block updater across podcasts, favourites.

## Open items
- Import podcast: how should it work (a feed URL, pasted XML, or both)? Not built until decided.
- Scope, feature list and anything that is out of scope still need to be decided; record them here.
- Owner's to-do list: see `docs/TODO.md`.

## Out of scope (do not add without asking)
(nothing recorded yet)
