# tldr-block

Adds a short summary block at the top of long posts, regenerated when the post changes, cached in post meta, with a shortcode and a block, and a per-category on and off switch. A model writes a draft; an editor approves it; readers only ever see approved text.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

## What it does

For long posts, the plugin generates a draft summary in the background and holds it for an editor to approve. Only approved text is shown to readers. When a post changes materially, the approved summary is withdrawn and a fresh draft is queued for review.

Spend is bounded: a daily cap on calls, an optional daily cost cap, a per-post daily limit and a kill switch, all in this plugin's own settings.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [Placing the summary](#placing-the-summary)
- [Hooks](#hooks)
- [WP-CLI](#wp-cli)
- [Privacy](#privacy)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

Requirements: WordPress 6.4 or later, PHP 8.1 or later. The `intl` extension is optional (better Unicode normalisation).

The only runtime dependency is [indianopinion/llm-json-php](https://github.com/IndianOpinion/llm-json-php), used for every model call. You can also run without it by supplying your own caller (see [FAQ](#faq)).

**Without Composer.** Download the release zip, which bundles llm-json-php under `lib/`, and upload it in Plugins, Add New. If another plugin already loaded llm-json-php, the bundled copy is not loaded.

**With Composer.** The library is not on Packagist yet, so add both repositories:

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/IndianOpinion/llm-json-php"},
        {"type": "vcs", "url": "https://github.com/IndianOpinion/tldr-block"}
    ],
    "require": {"indianopinion/tldr-block": "^0.1"}
}
```

**As a must-use plugin.** Put the plugin folder at `wp-content/mu-plugins/tldr-block/` and copy [`examples/mu-plugin-loader.php`](examples/mu-plugin-loader.php) to `wp-content/mu-plugins/`. Uninstall does not run for must-use plugins; run `wp tldr-block` commands and clean up by hand if you remove it.

To build the release zip yourself: `bin/make-release.sh`.

## Quick start

1. Activate the plugin. Nothing is generated yet, and nothing will show.
2. Settings, Summaries: choose a provider, paste an API key (or define `TLDR_BLOCK_API_KEY` in `wp-config.php`), enter one to three model ids. Press "Test connection" (it makes one tiny call that counts against the daily cap).
3. The global default mode is **off** on a fresh install. Turn summaries on for one category (Posts, Categories, edit, "Summaries: On"), or set the global default to on.
4. Save a post of 600 words or more in that category. Five minutes later (the debounce) a draft appears in the Summary panel of the editor.
5. An editor reads it and presses Approve, Edit and approve, or Reject. Only then does it appear on the post.

Posts, Summaries lists everything waiting for review.

## Configuration

All settings are on Settings, Summaries (capability `manage_options`). Every default passes through the `tldr_block_default_settings` filter.

| Setting | Default | Range or values |
|---|---|---|
| Generation (kill switch) | on, but nothing runs until a key and a model are set | on, off; `TLDR_BLOCK_DISABLED` locks it off |
| Display | on | on, off (hides every summary, keeps data) |
| Global default mode | off | on, off |
| Post types | `post` | public types with an editor |
| Trigger statuses | `publish`, `future`, `pending` | plus `draft` |
| Minimum words | 600 | 100 to 10000; per category override |
| Format | bullets | bullets, paragraph |
| Points | 3 to 5 | min 1 to 8, max min to 10 |
| Words per point | 30 | 8 to 60 |
| Paragraph words | 80 | 30 to 200 |
| Heading text | "Summary" | up to 60 characters, empty hides it |
| Placement | top | top, after_first_paragraph, none |
| Stale display | hide | hide, keep |
| Change threshold | 0.10 | 0.01 to 0.90 (filter `tldr_block_change_threshold`) |
| Debounce | 300 s | 0 to 3600 |
| Daily cap | 50 generations | 0 to 10000; `TLDR_BLOCK_DAILY_CAP` wins |
| Daily cost cap | none | decimal in the provider's currency |
| Per-post daily limit | 3 | 1 to 20 |
| Input limit | 30000 characters | 2000 to 200000 |
| History entries | 5 | 0 to 20 |
| Feeds | off | on, off |
| REST content insertion | off | on, off |
| Provider | OpenRouter | OpenRouter, OpenAI, OpenAI-compatible (base URL) |
| Model chain | empty | one to three model ids |
| Script check | on | reject output mostly in the wrong script |
| Debug logging | off | adds token counts, model and duration |

Constants (they win over settings and lock the field):

| Constant | Type | Effect |
|---|---|---|
| `TLDR_BLOCK_DISABLED` | bool | kill switch locked on |
| `TLDR_BLOCK_API_KEY` | string | API key; the environment variable of the same name is next, then the stored option |
| `TLDR_BLOCK_DAILY_CAP` | int | daily cap |

Per category (Posts, Categories): mode (inherit, on, off) and a minimum words override. Per post (the Summary panel): follow the category, always on, always off.

## How it works

1. **Save.** On `wp_after_insert_post` the plugin builds the source text (post body without markup, scripts, shortcode tags or its own block) and a fingerprint: a SHA-256 plus a 128-value bottom-k sketch of word 3-shingles. The title is not part of it.
2. **Compare.** The fingerprint is compared with the one the current summary was generated from, never with the previous save, so many small edits add up. A change is material when the hash differs and the estimated Jaccard distance is 0.10 or more. Bulk category edits, status flips and meta updates cost nothing.
3. **Stale.** A material change marks the approved summary stale. By default it is hidden at once and `tldr_block_display_changed` fires so caches can purge.
4. **Queue.** One job per post, pushed back by every material save (debounce). WP-Cron runs it, or `gen-queue` when that plugin is active, or `wp tldr-block run` from a system cron.
5. **Generate.** The job re-checks eligibility, takes a lease, reserves a unit of the daily cap under a lock in the options table, checks the per-post limit, the cost cap and `spend-meter` when present, then makes one call through llm-json-php. The article goes in the user message between markers; the system message says it is data, not instructions.
6. **Validate.** Point count, length, no empty or duplicate points, markup stripped, no URLs, no instruction-like lines, mostly the right script. Every number in the summary is checked against the article; misses are shown to the editor as "unverified figures".
7. **Review.** The result is a pending candidate. Nothing is shown until a user with `edit_others_posts` approves it. Every regeneration goes back to pending.

Failures retry after 5 minutes, 30 minutes and 2 hours, then stop as `failed`. An out-of-credit error stops retries and holds all generation for an hour.

Statuses: `none`, `queued`, `generating`, `pending`, `approved`, `rejected`, `failed`, `deferred`, `withdrawn`, `short`. Posts, All Posts has a Summary column and a filter.

## Placing the summary

- **Automatic** (default): prepended to the content on single views of the post, in the main loop only. Never in excerpts, archives, secondary loops, feeds (unless on) or REST responses (unless on). `after_first_paragraph` puts it after the first top-level paragraph.
- **Shortcode**: `[tldr]`, `[tldr post_id="123"]`, `[tldr heading="In short"]`, `[tldr heading=""]` to hide the heading.
- **Block**: "Summary" (`tldr-block/summary`). Server rendered; in the editor it previews the live summary or a placeholder that never reaches readers. Adding the block or the shortcode turns off automatic insertion for that post.
- **Template**: `tldr_block_the_summary()` echoes, `tldr_block_render()` returns, `tldr_block_get()` gives the `Summary` object. See [`examples/theme-template.php`](examples/theme-template.php).
- **REST**: the `tldr_block` field on the posts endpoint holds the displayable summary or null. It never exposes candidates or history.

Markup:

```html
<aside class="tldr-block" aria-label="Summary">
  <p class="tldr-block__heading">Summary</p>
  <ul class="tldr-block__points"><li>...</li></ul>
</aside>
```

The stylesheet is under 1 KB, uses logical properties (right-to-left works) and loads only on pages that print a summary. Turn it off with `add_filter('tldr_block_load_styles', '__return_false');`.

## Hooks

Actions: `tldr_block_queued`, `tldr_block_generated`, `tldr_block_failed`, `tldr_block_deferred`, `tldr_block_approved`, `tldr_block_rejected`, `tldr_block_withdrawn`, `tldr_block_stale`, `tldr_block_display_changed`, `tldr_block_cap_reached`, `tldr_block_log`.

Filters: `tldr_block_is_eligible`, `tldr_block_allow_private`, `tldr_block_mode`, `tldr_block_word_count`, `tldr_block_source_text`, `tldr_block_language`, `tldr_block_prompt`, `tldr_block_schema`, `tldr_block_validate`, `tldr_block_client`, `tldr_block_daily_cap`, `tldr_block_approve_capability`, `tldr_block_settings_capability`, `tldr_block_should_autoinsert`, `tldr_block_heading_tag`, `tldr_block_html`, `tldr_block_load_styles`, `tldr_block_debounce_seconds`, `tldr_block_change_threshold`, `tldr_block_default_settings`.

Purging a page cache when a summary appears or disappears:

```php
add_action('tldr_block_display_changed', function (int $id, string $reason): void {
    if ($reason !== 'category_switch') {
        my_cache_purge(get_permalink($id));
    }
}, 10, 2);
```

The plugin calls `clean_post_cache()` itself but never purges a page cache or CDN. A fuller example is in [`examples/purge-cache-on-change.php`](examples/purge-cache-on-change.php).

REST routes for the editor panel live under `tldr-block/v1/posts/<id>`: `GET`, `generate`, `approve`, `reject`, `withdraw`, `restore`, `override`, and `PUT manual`. All check `edit_post`; the review routes also check the approve capability.

## WP-CLI

```sh
wp tldr-block status [<post_id>]
wp tldr-block generate <post_id> [--force]
wp tldr-block run [--max=<n>]                  # for a system cron
wp tldr-block backfill [--category=<slug>] [--post_type=<t>] [--limit=<n>] [--spread] [--dry-run]
wp tldr-block pause                            # kill switch on
wp tldr-block resume
```

Backfill queues published posts with no summary, newest first, up to today's remaining cap; `--spread` schedules the rest on following days. It never approves anything. On a quiet site, add a system cron such as `*/5 * * * * wp tldr-block run --max=3`.

## Privacy

The title and text of eligible posts are sent to the model provider you configure. Password-protected and private posts are not sent. No reader, commenter or author data is sent. Say so in your privacy policy; the plugin adds a suggested paragraph to the privacy policy guide. The API key is never printed back, sent to the browser or logged.

## Limitations

- English, Indic and other spaced scripts are counted precisely; scripts without spaces (Chinese, Japanese, Thai and others) count one word per two characters.
- The figure check compares numbers only, not units or meaning. The editor is the check.
- The cost cap works only when the provider reports cost.
- Shortcode-like bracketed words such as `[sic]` are dropped from the source text along with real shortcode tags.
- With `gen-queue` active, its own daily cap and switch apply as well: until a gen-queue cap is set, its jobs wait.
- No bulk approve, by design.

## FAQ

**Can it publish summaries without review?** No. There is no setting for that.

**Why did my edit not trigger a new summary?** It was not material: below the 0.10 change threshold against the text the summary was generated from. Use Generate in the panel to force a fresh draft.

**Why is a post marked `deferred`?** The daily cap, the cost cap, the per-post limit or `spend-meter` refused the call. It is retried after local midnight, oldest change first.

**Can I run it without llm-json-php?** Yes. Return your own `IndianOpinion\TldrBlock\Llm\Caller` from the `tldr_block_client` filter. See [`examples/custom-caller.php`](examples/custom-caller.php).

**Who can approve?** Users with `edit_others_posts` (editors and administrators). Change it with `tldr_block_approve_capability`. Authors see the panel read-only and can ask for a regeneration.

**How do I see it working?** Settings, Summaries shows today's count, cost, pending reviews, failed and deferred jobs, the next run and the last five errors. Site Health has a test. Log lines start with `tldr-block:`.

Design notes and every decision are in [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). The requirements are in [`docs/PRD.md`](docs/PRD.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Licence

MIT. See [LICENSE](LICENSE). Copyright (c) 2026 Indian Opinion.
