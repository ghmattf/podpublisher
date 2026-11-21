# PodPublisher

WordPress plugin to manage Podcasting 2.0 feeds and files. Podcasting 2.0 apps are the target; Apple Podcasts, Spotify and similar services are not Podcasting 2.0 compliant and are supported only as secondary accommodations (warnings and optional fields, never blockers).

- Install: zip the `plugin/` folder (keep the folder name `podpublisher` inside the zip), upload via Plugins > Add New, add `[podpublisher]` to a page. Details in `plugin/readme.txt`.
- Develop: see `AGENTS.md`. Run checks with `tests/run-all.sh`.
- Releases: bump the version in `plugin/podpublisher.php` and `plugin/readme.txt` in a PR; merging to `main` creates the GitHub release and attaches the plugin zip.
