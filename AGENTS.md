# PodPublisher: agent handoff

Read this first, then `docs/DECISIONS.md`. Together they are the full context a new agent needs.

## What this is
A WordPress plugin to manage Podcasting 2.0 feeds and files. (Owner: refine this paragraph once the scope is decided, and record the scope in `docs/DECISIONS.md`.)

## Layout
- `plugin/` the WordPress plugin (this folder is what gets zipped and installed). Main file `podpublisher.php`.
  - `includes/` PHP classes (`class-podpub-*.php`): `settings`, `shortcode`. Add new classes here and `require_once` them from the main file.
  - `assets/` JS and CSS, when there are any.
- `tests/` all checks; run `tests/run-all.sh` (needs php; node 18+ once JS tests are added).
- `build/` only if a JS bundle is ever generated (document how in `build/README.md`). Not created yet.
- `docs/DECISIONS.md` decisions, assumptions, open items. `docs/TODO.md` the owner's requests not yet done.

## Naming scheme
Plugin folder/slug and text domain `podpublisher`, main file `podpublisher.php`, main class `PodPublisher`, shortcode `[podpublisher]`, code prefix `podpub` / `PODPUB_` (classes `PodPub_*`, constants, options, DB tables `podpub_*`, REST namespace `podpub/v1`, JS globals `PodPub*`, CSS `.podpub-*`).

## Rules that must not be broken
1. **Never bump the major version number (for example to 1.0.0) without the owner's explicit approval.** Current version is 0.1.0; use minor/patch bumps. Keep `Version:` and `PODPUB_VERSION` in `plugin/podpublisher.php` and `Stable tag` in `plugin/readme.txt` in step.
2. Releases are created by `.github/workflows/release.yml` when main has a plugin version with no release yet. Sessions cannot create releases or tags directly; bump the version in a PR and merge it.
3. Anything fetched from a user-supplied URL goes through `wp_safe_remote_get` (https only, size/redirect limits). Never use plain `wp_remote_get` for user URLs.
4. Wrap `localStorage`/`sessionStorage` use in try/catch.
5. Do not add features listed as out of scope in `docs/DECISIONS.md` without asking the owner.
6. Completed items are deleted from `docs/TODO.md` (not ticked off).
7. **Every owner request goes into `docs/TODO.md` first, before any work on it starts** (one entry per item, in the owner's wording). Then work on it, and delete the entries when done (rule 6). Requests that are only questions need no entry.
8. **Commit every new TODO item** as soon as it is added to `docs/TODO.md`. A commit triggers a **new test build zip** (the contents of `plugin/` inside a `podpublisher/` folder, built from the committed code, named with the version and "test") only if the commit changes plugin code (anything under `plugin/`); commits that change only docs, tests or the TODO get no zip. When a release has been triggered (the owner said "release"), do **not** make a test build zip: keep the usual reply with the release URL and the changes.

## Verify before you say it works
Run `tests/run-all.sh`. For now it lints every PHP file and checks that the plugin header, `PODPUB_VERSION` and `readme.txt` agree on the version. It does **not** run a real WordPress; say so when reporting.

## Working style the owner wants
- Break larger work into small chunks and finish one per request.
- Create issues/changes only where asked. Other repos used as references (`ghmattf/publisher-feed-generator`, `ghmattf/v4v-musiclink-pro`, `ghmattf/p20-link-resolver`) are **read-only**. Never write to them.
- Flag assumptions plainly instead of burying them.
