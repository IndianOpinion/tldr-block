# tldr-block

Adds a short summary block at the top of long posts, regenerated when the post changes, cached in post meta, with a shortcode and a block, and a per-category on and off switch.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

## What it does

For long posts, the plugin generates a draft summary in the background and holds it for an editor to approve. Only approved text is shown to readers. When a post changes materially, the approved summary is withdrawn and a fresh draft is queued for review.
