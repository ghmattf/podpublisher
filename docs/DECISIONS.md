# Decisions, assumptions and open items

Status: v0.2.0. The plugin has its own admin menu (podcast list, New podcast, one sub-item per podcast, Settings). A podcast has only a title so far; Import podcast and the contents of a podcast's page are not built.

## Product decisions (stated by the owner)
- Project and plugin name is **PodPublisher** (decided 2026-11-20). Description: a WordPress plugin to manage Podcasting 2.0 feeds and files.
- Naming scheme: see `AGENTS.md` (slug and text domain `podpublisher`, prefix `podpub` / `PODPUB_`).
- Versioning: starts at **0.1.0**. Major version changes (including moving to 1.0.0) need the owner's explicit approval; features and fixes before that are minor/patch bumps.
- Admin menu (owner request, 2026-11-20): a top-level **PodPublisher** item (not under Settings). Its page lists the managed podcasts and has **New podcast** and **Import podcast**. Its sub-items are: All podcasts, one item per managed podcast (in A to Z order), then Settings (general plugin settings, last).
- Repo layout and working rules copied from `ghmattf/publisher-feed-generator` (read-only reference).

## Assumptions to verify (not confirmed)
1. Public GitHub repo `ghmattf/podpublisher` (assumed, same as the owner's other plugin repos).
2. Minimum WordPress 5.8 and PHP 7.4, copied from the feed generator plugin.
3. Release workflow and the PR-driven version bump work the same as in the feed generator.

4. (0.2.0.) Managed podcasts are stored as records of a private post type `podpub_feed` (no front-end URL, no WordPress editor screen), so they survive in the normal WordPress tables and are removed on uninstall only if the Settings box is ticked. Assumption: this is the right store; a custom table is the alternative.
5. (0.2.0.) Access needs the `manage_options` capability (administrators). Assumption: editors should not manage podcasts; change `PodPub_Admin::CAP` if they should.
6. (0.2.0.) The menu is built on every admin page load with one query for the podcast list; with a very large number of podcasts the sidebar would get long. Not a concern at small scale.
7. (0.2.0.) "New podcast" asks only for a title and creates the record, then opens that podcast's page, which is a placeholder.

## Open items
- Import podcast: how should it work (a feed URL, pasted XML, or both)? Not built until decided.
- Scope, feature list and anything that is out of scope still need to be decided; record them here.
- Owner's to-do list: see `docs/TODO.md`.

## Out of scope (do not add without asking)
(nothing recorded yet)
