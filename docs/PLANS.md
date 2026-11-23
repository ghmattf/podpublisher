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
