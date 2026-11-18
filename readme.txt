=== tldr-block ===
Contributors: indianopinion
Tags: summary, tldr, key points, block, editorial
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A short, editor-approved summary at the top of long posts, regenerated when the post changes, with a shortcode, a block and per-category switches.

== Description ==

Written from scratch; not derived from any third-party plugin.

For long posts, the plugin generates a draft summary in the background with a language model and holds it for an editor to approve. Only approved text is shown to readers. When a post changes materially, the approved summary is withdrawn and a fresh draft is queued for review.

* Nothing is shown until an editor approves it. Every regeneration goes back to pending.
* Change detection that ignores typo fixes and adds up many small edits.
* Daily cap, optional daily cost cap, per-post daily limit and a kill switch.
* Automatic insertion, the [tldr] shortcode, a Summary block, template functions and a REST field.
* Global, per-category and per-post switches.
* Block editor panel, classic meta box, review screen, Site Health test and WP-CLI commands.

= Third-party service =

This plugin sends the title and text of eligible posts to the model provider you configure (OpenRouter, OpenAI or an OpenAI-compatible server). Password-protected and private posts are not sent. No reader, commenter or author data is sent. Nothing is sent until you add an API key and a model. Read your provider's terms and privacy policy, and mention the processing in your own privacy policy.

== Installation ==

1. Upload the plugin and activate it.
2. Settings, Summaries: choose a provider, add an API key and one to three model ids.
3. Turn summaries on for a category (Posts, Categories) or set the global default to on.
4. Review drafts in the Summary panel of the editor or under Posts, Summaries.

== Frequently Asked Questions ==

= Can summaries be published without review? =

No. There is no setting for that.

= Why did a small edit not create a new draft? =

Only material changes do. Press Generate in the Summary panel to ask for one.

= Who can approve? =

Users with the edit_others_posts capability. A filter changes it.

== Changelog ==

= 0.1.0 =
* First release.
