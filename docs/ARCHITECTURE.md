# tldr-block architecture

This document describes how the plugin is put together and records every decision taken while building 0.1.0 from [PRD.md](PRD.md), including the answers to the PRD's open questions.

## Modules

| Path | Role |
|---|---|
| `tldr-block.php` | Plugin header; loads `autoload.php` and calls `Plugin::boot()` |
| `autoload.php` | PSR-4 autoloader for `src/`, the public functions, and llm-json-php from `vendor/` or `lib/` unless already loaded |
| `src/Plugin.php` | Wires every hook; registers meta, shortcode, block, REST routes and field, styles, admin screens, CLI |
| `src/Settings.php` | Defaults, sanitising, constants, API key lookup, kill switch, provider check |
| `src/Text/WordCounter.php` | Word splitting (case 2) |
| `src/Text/SourceText.php` | Source text, own-markup removal, truncation (cases 10, 14, 15) |
| `src/Text/Fingerprint.php` | SHA-256 plus bottom-k sketch, distance, material change (cases 12, 13) |
| `src/Text/Validator.php` | Output and edited-text validation (cases 32, 47) |
| `src/Text/Figures.php` | Figure check (case 41) |
| `src/Text/Language.php` | Language names, codes and scripts (case 43, script check) |
| `src/Modes.php` | Mode resolution and per-category minimum words (cases 3, 4) |
| `src/Eligibility.php` | Generation eligibility and displayability (cases 1, 50) |
| `src/PostState.php` | All post meta reads and writes (section 6.1) |
| `src/Queue.php` | Save handling, stale marking, debounce, scheduling, cancel, deferred sweep (cases 16 to 26, 37) |
| `src/Generator.php` | The job handler: lease, caps, spend-meter, prompt, call, validation, candidate, retries (cases 26 to 44) |
| `src/Counters.php` | Daily count and cost under an options-table lock; credit hold (cases 34, 35, 39) |
| `src/Prompt.php` | Built-in template, prompt-library rendering, schema (cases 29, 31, 42, 44) |
| `src/Llm/*` | `Caller` interface, request and result objects, `CallFailed`, the llm-json-php adapter and factory (case 30) |
| `src/Integrations/*` | gen-queue, spend-meter and prompt-library bridges; all hook names for them live here |
| `src/Review.php` | Approve, edit and approve, write my own, reject, withdraw, restore (cases 46 to 52) |
| `src/State.php` | Panel state and the buttons a user may use (cases 45, 53) |
| `src/Renderer.php` | Markup, automatic insertion, shortcode, block render, feeds, REST field (cases 56 to 65) |
| `src/DisplayEvents.php` | `clean_post_cache()` and `tldr_block_display_changed` (case 58) |
| `src/Rest/Controller.php` | Routes under `tldr-block/v1` (section 5.5) |
| `src/Admin/*` | Settings screen, review screen, Posts column, category fields, editor panel and meta box, Site Health |
| `src/Cli/Command.php` | `wp tldr-block` commands (cases 66, 67) |
| `src/Uninstaller.php` | Clean-up on each site (case 71) |
| `src/Logger.php` | `tldr-block:` log lines, throttling, last five errors (section 11) |
| `blocks/summary/` | `block.json` (apiVersion 3) and the editor script |
| `assets/` | Front-end stylesheet, block editor panel, classic meta box script |

## Data flow

```
save post ──> Queue::onSave
                 │  source text, fingerprint, word count
                 │  material vs live?  ── yes ──> mark stale, display_changed(stale)
                 │  material vs basis? ── yes ──> set basis, schedule(now + debounce)
                 ▼
     WP-Cron event / gen-queue job / wp tldr-block run
                 ▼
          Generator::run
   eligibility ─ hold ─ lease ─ per-post limit ─ reserve cap ─ spend-meter
                 ▼
     Prompt::build ─> Caller::call (llm-json-php or filter) ─> Validator
                 ▼
     post changed mid-call? ── yes ──> discard, requeue
                 ▼
        candidate (pending) ──> editor: approve / edit / reject / withdraw / restore
                 ▼
         live summary ──> Eligibility::displayable ──> Renderer (content, shortcode, block, REST, feed)
```

The front end reads only post meta that the main query has already primed, plus term meta for the mode. It makes no external call. The stored word count (from the last save) is used for the minimum check so rendering does not rebuild the source text.

## Rulings on the open questions (section 13)

1. **Category conflicts: `off` in any category wins.** Turning a category off must hide its summaries everywhere, including on posts filed in several categories. A primary-category rule would let an "on" category override an editor's decision to switch one off.
2. **Stale summaries are hidden until the new one is approved.** Default stale display is `hide`. A stale summary can repeat the error a correction removed, which is worse than showing nothing. The `keep` setting stays for sites that want it.
3. **Change threshold stays at 0.10, filterable** with `tldr_block_change_threshold` as well as the setting. Calibration on generated text (20 seeds each, distance mean, min and max):

   | Edit | 600 words | 1000 words | 2000 words |
   |---|---|---|---|
   | Typo fix (one word) | 0.003 (0.000 to 0.008) | 0.002 (0.000 to 0.008) | 0.002 (0.000 to 0.008) |
   | Added a short phrase or link text | 0.010 (0.000 to 0.023) | 0.006 (0.000 to 0.023) | 0.005 (0.000 to 0.023) |
   | Added a 70-word paragraph | 0.105 (0.055 to 0.164) | 0.063 (0.031 to 0.109) | 0.036 (0.008 to 0.070) |
   | Rewritten 70-word lede | 0.213 (0.164 to 0.273) | 0.129 (0.078 to 0.211) | 0.068 (0.047 to 0.117) |

   Typo and link edits never come near 0.10. One added paragraph is material on a 600-word post and usually not on a 2000-word one; repeated additions add up because the comparison is always against the generated-from text. A rewritten lede on a very long post may fall below 0.10; editors can press Generate, and sites with long posts can lower the threshold.
4. **A title change alone is not material.** The title is sent to the model but is not part of the fingerprint. Headlines are often tuned after publication without changing what the article says.
5. **No bulk approve in 0.1.0.** Each approval is a deliberate read of one summary. The review screen has per-row Approve and Reject only.
6. **Only users with `edit_others_posts` approve** (filter `tldr_block_approve_capability`). Authors see their panel read-only and can press Generate on their own posts.
7. **Backfill is WP-CLI only.** Backfill spends money in bulk; keeping it on the command line keeps it a conscious operator action.
8. **Block editor scripts with no build step,** using the `wp` globals (`wp.element`, `wp.components`, `wp.apiFetch`, `wp.plugins`). No Node toolchain is needed to contribute or to ship.
9. **No measured-result line in the README.** Nothing can be measured from the plugin alone. The README states plainly what the plugin does.
10. **Global default mode on a fresh install is `off`.** The operator opts in per category or globally. This differs from the PRD default (`on`) by ruling.

## Decisions taken by the builder

- **Caller abstraction.** Model calls go through the plugin's own `Llm\Caller` interface. `LlmJsonCaller` adapts an llm-json-php `Client`. The `tldr_block_client` filter may return either a `Caller` or a `Client`, so a site can run without llm-json-php (group C ruling of 14 November). Tests use a scripted fake caller.
- **Comparison basis.** The fingerprint a save is compared with is the newest generated-from fingerprint: the source of the queued or last generated job, else the pending candidate's, else the live summary's. It is stored in the job record. The live summary's own fingerprint is still used separately to decide staleness. This keeps a run of small edits after a regeneration from queueing again and again, while staleness is judged against what the approved text describes. Rejections, failures and hand-written summaries also set the basis, so a rejection does not trigger an automatic regeneration of the same text.
- **Sketch hash.** CRC32 of each shingle. It is native, fast and well spread over distinct strings, which is all a bottom-k estimate needs. Fingerprinting 10000 words takes about 12 to 20 ms locally. The sketch is stored packed and base64 encoded (684 bytes for 128 values).
- **Sorting, not a heap.** The bottom 128 hashes come from sorting the distinct hashes. For posts of realistic length this is as fast as a bounded heap and simpler.
- **Distance estimator.** The bottom-k of the union of both sketches; the distance is the share of those values not present in both.
- **Displayability uses the stored word count** from the last save (falls back to computing it), so rendering stays well under a millisecond.
- **Order inside a job.** Eligibility, credit hold, lease, per-post limit, own cap reservation, spend-meter. If spend-meter refuses, the plugin's own unit is refunded because no call was made; a failed call is never refunded.
- **Per-post limit and retries.** Every attempt counts towards the per-post limit, including retries. With the default limit of 3, a post whose first three attempts fail is deferred to the next day rather than making a fourth call the same day. This follows lesson 9: one post cannot eat the budget.
- **Deferred jobs.** One sweep event per day at 00:05 site time plus up to 30 minutes of jitter. It re-queues every deferred post, oldest change first, a few seconds apart.
- **Cron tick budget.** At most 3 jobs or 60 seconds per WP-Cron request; the rest move one minute later.
- **Lock.** `add_option()` on a lock row is an atomic insert; a lock older than 30 seconds is taken over. The object cache is bypassed for the counters.
- **Status field.** One status per post, holding the latest event. Withdrawn and stale are also flags on the live summary, so a queued regeneration does not lose them.
- **Markdown and HTML in output are stripped, not rejected** (case 32 rule 4). URLs and instruction-like lines ("ignore the previous instructions", "as an AI") are rejected. Hand-edited text skips the instruction check: the editor wrote it.
- **Script check** uses a fixed table from language code to Unicode script, with a 50 percent share. Japanese is not checked (three scripts). It can be turned off in settings.
- **Language.** `tldr_block_language` returns a language name for the prompt. The code used for the `lang` attribute and the script check is derived from the site locale, or from the name when the filter changes it and the name is known.
- **Per-post override.** The PRD names a per-post switch and a `post_switch` reason but no route. 0.1.0 adds `POST /posts/<id>/override` with `mode` (`auto`, `on`, `off`), behind the approve capability, firing `display_changed` with `post_switch`.
- **Placeholder only in the editor.** The block's server render returns nothing when there is no displayable summary. The placeholders ("Summary pending review", "No summary yet", "Summaries are off for this category") are drawn by the editor script from the panel state, so they can never reach readers.
- **Feeds** use the same markup without the stylesheet. **REST content** insertion, when on, inserts for the post being rendered.
- **Logs.** No log table. The last five error messages (no post text) are kept in one non-autoloaded option for the settings screen and Site Health; uninstall removes it.
- **Credit hold** is stored as a timestamp option. Jobs that come due during the hold are rescheduled to its end.
- **Uninstall** deletes post meta in batches of 500 per key, term meta by key, both scheduled hooks, the plugin's options, and its transients (including llm-json-php's store, which uses the `tldr_block_llm` prefix), on each site of a network.
- **Release zip.** `bin/make-release.sh` bundles llm-json-php under `lib/`. `autoload.php` loads it only when no other plugin has.

## Integrations

The gen-queue, spend-meter and prompt-library plugins were not released when 0.1.0 was built. Their hook names come from their requirement documents as cited in the PRD and are isolated in `src/Integrations/`:

| Plugin | Detection | Hooks used |
|---|---|---|
| gen-queue | a callback on `gen_queue_enqueue_job` that returns an integer | filter `gen_queue_enqueue_job` (job array with type `tldr_block.generate`, payload `{post_id}`, idempotency key `post:<id>`, replace on conflict, `run_after`, group `tldr-block`), filter `gen_queue_cancel_job`, handler registered on `gen_queue_handlers` |
| spend-meter | `SPEND_METER_API === 1` | filter `spend_meter_pre_spend`, actions `spend_meter_record` and `spend_meter_release` |
| prompt-library | a callback on `prompt_library_render` | filter `prompt_library_defaults` (slug `tldr-block/summary`), filter `prompt_library_render` |

Any error or unusable answer from them means "absent". The plugin's own caps and kill switch always apply.

## Security notes

- Every REST route checks `edit_post`; review routes also check the approve capability. Admin forms carry nonces and capability checks.
- Meta keys start with an underscore, are registered with `show_in_rest` false and an `auth_callback` that refuses, so the core posts endpoint cannot write an approved summary.
- Output is escaped at the point it is built. Stored summary text is plain text.
- The API key is read from the constant, then the environment, then the option. It is never printed back (only its last four characters), sent to the browser or logged.
- The article text is delimited in the user message; the delimiter strings are removed from the article first.

## Tests

PHPUnit 10 against an in-memory WordPress stub layer (`tests/stubs/`). Every acceptance test T1 to T74 in the PRD maps to at least one test method; the method docblocks name the T number and case. Tests that need a constant (`TLDR_BLOCK_DISABLED`, `WP_IMPORTING`, `DOING_AUTOSAVE`, `REST_REQUEST`, `SPEND_METER_API`, `TLDR_BLOCK_DAILY_CAP`, `WP_CLI`, `DISABLE_WP_CRON`) run in a separate process. T74 prints the fingerprint time and fails only above two seconds.
