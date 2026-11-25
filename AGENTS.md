# PodPublisher: agent handoff

Read this first, then `docs/DECISIONS.md`. Together they are the full context a new agent needs.

## What this is
A WordPress plugin to manage Podcasting 2.0 feeds and files. **Podcasting 2.0 comes first**: the target is Podcasting 2.0 apps and the podcast namespace. Apple Podcasts, Spotify and similar services are not Podcasting 2.0 compliant, so they are secondary accommodations (see rule 9). (Owner: refine this paragraph once the scope is decided, and record the scope in `docs/DECISIONS.md`.)

## Layout
- `plugin/` the WordPress plugin (this folder is what gets zipped and installed). Main file `podpublisher.php`.
  - `includes/` PHP classes (`class-podpub-*.php`, one per area, for example `admin`, `feeds`, `episodes`, `feedxml`, `p20`, `details`, `storage`, `images`, `settings`). Add new classes here and `require_once` them from the main file.
  - `assets/` JS and CSS, when there are any.
- `tests/` all checks; run `tests/run-all.sh` (needs php with GD and DOM; node 18+ once JS tests are added). Each `test_*.php` file stands in for the few WordPress functions it uses; add a new test file to `run-all.sh`.
- `build/` only if a JS bundle is ever generated (document how in `build/README.md`). Not created yet.
- `docs/DECISIONS.md` decisions, assumptions, open items. `docs/TODO.md` the owner's requests not yet done. `docs/PLANS.md` plans for work not built yet (delete a plan once it is built and its decisions are in DECISIONS.md).

## Naming scheme
Plugin folder/slug and text domain `podpublisher`, main file `podpublisher.php`, main class `PodPublisher`, shortcode `[podpublisher]`, code prefix `podpub` / `PODPUB_` (classes `PodPub_*`, constants, options, DB tables `podpub_*`, REST namespace `podpub/v1`, JS globals `PodPub*`, CSS `.podpub-*`).

## Rules that must not be broken
1. **Never bump the major version number (for example to 1.0.0) without the owner's explicit approval.** Use minor/patch bumps (0.x.y). Keep `Version:` and `PODPUB_VERSION` in `plugin/podpublisher.php` and `Stable tag` in `plugin/readme.txt` in step.
2. Releases are created by `.github/workflows/release.yml` when main has a plugin version with no release yet. Sessions cannot create releases or tags directly; bump the version in a PR and merge it.
3. Anything fetched from a user-supplied URL goes through `wp_safe_remote_get` (https only, size/redirect limits). Never use plain `wp_remote_get` for user URLs.
4. Wrap `localStorage`/`sessionStorage` use in try/catch.
5. Do not add features listed as out of scope in `docs/DECISIONS.md` without asking the owner.
6. Completed items are deleted from `docs/TODO.md` (not ticked off).
7. **Every owner request goes into `docs/TODO.md` first, before any work on it starts** (one entry per item, in the owner's wording). Then work on it, and delete the entries when done (rule 6). Requests that are only questions need no entry.
8. **Commit every new TODO item** as soon as it is added to `docs/TODO.md`. A commit triggers a **new test build zip** (the contents of `plugin/` inside a `podpublisher/` folder, built from the committed code, named with the version and "test") only if the commit changes plugin code (anything under `plugin/`); commits that change only docs, tests or the TODO get no zip. When a release has been triggered (the owner said "release"), do **not** make a test build zip: keep the usual reply with the release URL and the changes.
9. **Podcasting 2.0 first; Apple Podcasts, Spotify and similar services are secondary accommodations** (owner, 2026-11-21). Design and name things for Podcasting 2.0 apps and the podcast namespace first. Where a requirement exists only for those other services (for example image types, size ranges, `itunes:` tags, categories), support it only if it costs nothing, and as a **warning or an optional field, never as a block** and never as a reason to drop or weaken a Podcasting 2.0 feature. When writing UI text, docs or warnings, say plainly that it is those services that need it.
10. **Everything must be on GitHub so another agent can continue the work** (owner, 2026-11-23). A session's workspace is thrown away; only what is pushed survives. Before ending any reply that changed anything: commit all changes (code, tests, `docs/TODO.md`, `docs/DECISIONS.md`, `docs/PLANS.md`, and this file when a rule or the layout changed), push the branch, and open or update a pull request (use `.github/PULL_REQUEST_TEMPLATE.md`). Never leave work only in the workspace, in chat or in a project doc. Keep `docs/DECISIONS.md` and `docs/TODO.md` current in the same PR, so a new agent can pick up from the repo alone. If something cannot be pushed (no access, a failed check), say so plainly in the reply and record it in `docs/DECISIONS.md` open items. Say in the reply which branch and PR hold the work.

## Verify before you say it works
Run `tests/run-all.sh` before every commit. It lints every PHP file, checks that the plugin header, `PODPUB_VERSION` and `readme.txt` agree on the version, and runs the test files (hundreds of checks). It does **not** run a real WordPress; say so when reporting. When a test fails, decide whether the test or the code is wrong; never weaken a test to make it pass.

## Working style the owner wants
- Break larger work into small chunks and finish one per request.
- Create issues/changes only where asked. Other repos used as references (`ghmattf/publisher-feed-generator`, `ghmattf/v4v-musiclink-pro`, `ghmattf/p20-link-resolver`) are **read-only**. Never write to them.
- Flag assumptions plainly instead of burying them.

## Working conventions for every agent
- **Start** each task with one sentence saying what you are about to do. Use a task list for anything with several steps.
- **Explain in plain English.** The owner is not a programmer: no unexplained jargon; say what a change means for them, and give the choice and your default when you ask a question.
- **Record decisions.** Each change that decides something gets a numbered entry in `docs/DECISIONS.md` (with the version), including what you could not check (for example, spec pages you could not read). Update `docs/PLANS.md` when a plan changes.
- **Commits.** Small commits with a clear message, on a branch (not straight to `main`), pushed to the same branch. End each commit message with the attribution lines the session gives you (the `Co-Authored-By` and `Claude-Session` lines).
- **Version.** Each batch of plugin changes bumps the minor or patch version in the three places (rule 1) and is committed together with the tests and the docs.
- **Test build zip** (rule 8): run `tests/build-zip.sh` (it copies `plugin/` into a folder named `podpublisher`, zips that folder as `podpublisher-<version>-test.zip` and checks the layout), then send the zip to the owner as a file. Never zip `plugin/` directly: the folder inside the zip must be named `podpublisher` or WordPress installs a second plugin instead of upgrading.
- **Pull request.** Keep one open PR per branch. Update its title and body for each version (the body lists the changes and ends with the "Generated with Claude Code" line and the session link). GitHub's GraphQL is not available from sessions, so use the REST API (`gh api repos/ghmattf/podpublisher/pulls/...`).
- **Reply when done:** what changed in a few sentences, what could not be checked, and the one natural next step. Do not recap the steps.
