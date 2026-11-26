# Plans

Plans for work that is not built yet. Nothing here is a decision until the owner agrees; once built, the decisions move to docs/DECISIONS.md and the plan is deleted from this file.

## Several people, each managing only their own podcasts (idea from the owner, 2026-11-26)

Not agreed or scheduled. Notes so far:
- Do TODO 56 first: with several users the storage location must not be a per-user or editable setting.
- Ownership: store the owner's user id on each podcast (episodes follow their podcast). Add one PodPublisher capability for "manage my podcasts"; administrators manage all. Every action, list, Ajax call, file upload and delete checks "may this user manage this podcast" in one place, instead of today's single `manage_options` check.
- Keep administrator-only: Settings, adopting a feed from a file on the server, deleting a podcast's files by hand, anything that can reach outside the user's own podcast folder.
- WordPress posts: the post author is limited to the user (or people they may pick), categories limited to what they may use, and posts need `publish_posts` as today.
- Hosting safety on your server: a disk quota per user, a limit on podcasts per user, a no-scripts rule in the Podcast folder, and a way to see and suspend a podcast (its feed is public on your domain).
- Podcast folder names must be unique and cannot be chosen to land on another user's folder.
