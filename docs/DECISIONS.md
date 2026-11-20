# Decisions, assumptions and open items

Status: v0.1.0 skeleton only. The plugin activates and registers an empty `[podpublisher]` shortcode; no features are built yet.

## Product decisions (stated by the owner)
- Project and plugin name is **PodPublisher** (decided 2026-11-20). Description: a WordPress plugin to manage Podcasting 2.0 feeds and files.
- Naming scheme: see `AGENTS.md` (slug and text domain `podpublisher`, prefix `podpub` / `PODPUB_`).
- Versioning: starts at **0.1.0**. Major version changes (including moving to 1.0.0) need the owner's explicit approval; features and fixes before that are minor/patch bumps.
- Repo layout and working rules copied from `ghmattf/publisher-feed-generator` (read-only reference).

## Assumptions to verify (not confirmed)
1. Public GitHub repo `ghmattf/podpublisher` (assumed, same as the owner's other plugin repos).
2. Minimum WordPress 5.8 and PHP 7.4, copied from the feed generator plugin.
3. Release workflow and the PR-driven version bump work the same as in the feed generator.

## Open items
- Scope, feature list and anything that is out of scope still need to be decided; record them here.
- Owner's to-do list: see `docs/TODO.md`.

## Out of scope (do not add without asking)
(nothing recorded yet)
