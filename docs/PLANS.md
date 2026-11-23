# Plans

Plans for work that is not built yet. Nothing here is a decision until the owner agrees; once built, the decisions move to docs/DECISIONS.md and the plan is deleted from this file.

## TODO 22: WordPress posts for episodes and a page for the podcast

**Owner's answers (2026-11-23, two rounds).**
- Each episode can have an **ordinary WordPress post**. The show notes go **in both places**: in the post, and in the feed (the feed item also links to the post).
- **When the prompt appears:** only when the episode is being **published** (not a draft) and the owner clicks Save. The prompt is "Create/update WordPress post" yes/no. It is not shown for drafts.
- **When the post is made:** only **after the episode has been saved and published successfully**. An episode with errors that stop it publishing creates or edits no post.
- **Existing post:** the prompt itself carries the warning that saving with "yes" overwrites the post's previous title and content, and offers "no" to leave the post alone.
- **Podcast page:** the podcast editing screen has an option to create a **page** (not a post) for the podcast. The page lists all the podcast's episodes. The same screen has: an option to **turn off creating a post for each episode**, a list of **WordPress authors** to attribute the posts to, and **categories** for the posts.
- **Deleting an episode** asks whether to delete the matching post as well (delete episodes and podcasts, TODO 21, is built in 0.10.0 without this question; add the question when this plan is built).

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
