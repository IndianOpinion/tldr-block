# tldr-block: product requirements

Status: draft for build 0.1.0. Date: 14 November 2026.

One line: adds a short summary block at the top of long posts, regenerated
when the post changes, cached in post meta, with a shortcode and a block,
and a per-category on and off switch.

The README of the built project carries this exact line in its first
section: "Written from scratch; not derived from any third-party plugin."
It also carries the shared sentence: "This project came out of the work on
indianopinion.org and is written from scratch; it is independent of and not
affiliated with any third-party plugin it integrates with."

Names in this document (functions, hooks, meta keys, options, routes) are
this project's own. The namespace is `IndianOpinion\TldrBlock`. Global
functions, hooks, options and meta keys use the prefix `tldr_block_` (meta
keys add a leading underscore so WordPress treats them as protected). The
text domain is `tldr-block`.

## 1. Problem

Long posts lose readers before the point. A short summary at the top helps
the reader decide, helps a screen-reader user skim, and gives a quoted
answer that search and answer engines can lift. Writing that summary by
hand for every long post is a chore editors skip.

Generating it with a language model fixes the chore and creates three new
problems:

1. **Drift.** The post is corrected or extended and the summary still
   describes the old version. On a news site a stale summary can repeat the
   error the correction removed.
2. **Unreviewed text on the page.** A model can invent a figure, flip a
   negation, or follow an instruction hidden in the article text. Nothing
   machine-written may reach readers without a human saying yes.
3. **Runaway spend.** Every save of a long post is a potential paid call.
   An editor who saves forty times in an hour, a bulk edit of five hundred
   posts, or a cron loop can each burn a day's budget.

tldr-block generates the summary in the background, holds it as pending
until an editor approves it, shows only approved text, withdraws it when the
post changes materially and generates a fresh candidate for review, and
caps calls per day with a kill switch.

The README states in plain words what the plugin does: it writes a
draft summary for long posts, holds it for editor approval, and withdraws it
when the post changes. It carries no measured-result line.

## 2. Goals and non-goals

### Goals

- G1. A summary (bullet points by default, or one short paragraph) at the
  top of posts above a word threshold, in the post's own language.
- G2. Generation runs in the background, never in a front-end request and
  never blocking a save.
- G3. Human in the loop: a generated summary is a pending candidate. It is
  shown to readers only after a user with the approve capability approves
  it. Every regeneration goes back to pending.
- G4. Change detection: a material change to the post body marks the
  approved summary stale, hides it (by default), and queues a new
  candidate. Small edits (a typo, a link) do not.
- G5. Storage in post meta, so the front end costs no extra query and no
  external call.
- G6. Three ways to place it: automatic insertion at the top of the
  content, a shortcode, and a dynamic block.
- G7. A per-category switch (on, off, inherit), a per-post override, and a
  global default.
- G8. A daily cap on generations, an optional daily cost cap, a per-post
  daily limit, and a kill switch, all in this plugin's own settings, even
  when a shared queue or spend meter is present.
- G9. Soft integration with `gen-queue`, `spend-meter` and `prompt-library`
  through their public hooks, used only when they are active.

### Non-goals

- N1. Auto-publishing any generated text. There is no setting that skips
  approval.
- N2. Summarising comments, attachments, PDFs, or linked pages. Only the
  post's own title and body are read.
- N3. Fetching anything from the web.
- N4. Translating summaries between language editions. Each post is
  summarised in its own language.
- N5. Structured data output (schema.org abstract and the like).
- N6. Approving from WP-CLI or in bulk (see section 13).
- N7. A provider SDK. All model calls go through `indianopinion/llm-json-php`,
  or through a site's own `Caller` supplied by filter (case 30).
- N8. Checking the summary for truth beyond the figure check in case 41.
  The editor is the check.

## 3. Users and setting

- **Site operator** (administrator): installs the plugin, sets the provider
  key, model, caps, kill switch, global default and category switches.
- **Editor** (default capability `edit_others_posts`): reviews candidates,
  approves, edits, rejects, withdraws, restores.
- **Author or contributor**: sees the status of the summary on their own
  post and can ask for a regeneration (counts against caps). Cannot approve
  by default.
- **Reader**: sees approved summaries only.
- **Developer or theme author**: uses the shortcode, block, template
  function, REST field and filters.

Runs as a standard WordPress plugin (WordPress 6.4+, PHP 8.1+), single site
or multisite (per-site settings and caps). Generation runs from WP-Cron, a
real system cron calling WP-Cron, WP-CLI, or `gen-queue` when active. The
only outbound traffic is the model call through `llm-json-php`.

## 4. Behaviour

Terms used below:

- **Source text**: the post body prepared as in case 10.
- **Fingerprint**: a SHA-256 of the source text plus a bottom-k sketch of
  its word 3-shingles (case 12).
- **Candidate**: a generated summary awaiting review, with its own random
  id (UUID v4).
- **Live summary**: the most recent approved summary.
- **Displayable**: a live summary that passes every check in case 50.

### 4.1 Eligibility and switches

1. A post is eligible for generation when all hold: its post type is in the
   allowed list (default `post`); its status is in the trigger list
   (default `publish`, `future`, `pending`); it has no password; it is not
   `private` (unless the filter in 5.4 allows it); its source text has at
   least the minimum word count (default 600); the resolved mode (case 4) is
   on; the kill switch is off; a provider is configured.
2. The word count counts runs of letters, combining marks and digits
   (Unicode classes L, M, N) separated by anything else. Combining marks
   belong to the word they follow, so Devanagari, Bengali, Tamil and other
   Indic words count once, not once per syllable. For scripts written
   without spaces (Han, Hiragana, Katakana, Thai, Lao, Khmer, Myanmar) every
   two characters count as one word. The count is filterable.
3. The minimum word count is a setting (default 600, range 100 to 10000)
   and can be overridden per category (term setting, blank means inherit).
4. Mode resolution, first match wins:
   1. Per-post override `on` or `off` (default `auto`).
   2. Categories of the post. For each category, walk up its ancestors to
      the nearest explicit `on` or `off`. If any category resolves to
      `off`, the result is `off`. Otherwise if any resolves to `on`, the
      result is `on`.
   3. The global default (default `off`, ruling 10 in section 13: the
      operator opts in per category or globally).
   The result passes through a filter (5.4). Post types without the
   `category` taxonomy go straight from step 1 to step 3.
5. The switch governs both generation and display. Turning a category off
   hides its summaries at once (no regeneration needed) and stops new
   generations. Stored summaries are kept. Turning it back on shows the
   approved, non-stale summaries again without spending anything.
6. Turning a category on does not generate summaries for its existing
   posts. They are generated on their next material save, by the
   "Generate" button, or by the backfill command (case 66).
7. The kill switch stops all generation: nothing is queued, queued jobs are
   not run, the manual Generate button returns an error that names the
   switch. Display of approved summaries is unaffected. The constant
   `TLDR_BLOCK_DISABLED` set to true forces the switch on and the settings
   screen shows it as locked.
8. A separate display switch hides every summary on the front end without
   touching generation or data. It does not stop the editor preview.
9. On a fresh install generation is off until an API key and at least one
   model are set. The settings screen says so. Activation never calls a
   provider and never queues anything.

### 4.2 Source text and fingerprint

10. Source text is built from the raw `post_content`: remove this plugin's
    own block and shortcode (case 14), remove block comment delimiters,
    expand nothing, strip all shortcode tags (keep text between enclosing
    shortcodes), strip HTML tags, drop `<script>` and `<style>` contents,
    decode HTML entities, normalise to Unicode NFC when `intl` is present,
    collapse whitespace, trim. Image captions and headings stay as text.
    The result passes through a filter (5.4).
11. The title is sent to the model alongside the source text but is not
    part of the fingerprint. A title-only change is not a material change.
12. The fingerprint is: an exact hash (SHA-256 of the source text after
    lower-casing) and a sketch: the 128 smallest distinct 32-bit hashes of
    the word 3-shingles (words as in case 2, lower-cased). Texts with fewer
    than three words use single words as shingles.
    The 32-bit hash of a shingle is its CRC32 (builder decision, recorded
    in ARCHITECTURE.md): native, fast and well spread, which is all a
    bottom-k estimate needs. An empty text has an empty sketch; two empty
    sketches are at distance 0, an empty and a non-empty one at 1.
13. Material change: the exact hash differs and the estimated Jaccard
    distance between the current sketch and the comparison sketch is at or
    above the change threshold (default 0.10, range 0.01 to 0.90, also
    filterable with `tldr_block_change_threshold`). The comparison sketch
    for queueing is the newest generated-from fingerprint: the source of
    the queued or last generated job, else the pending candidate's, else
    the live summary's (builder decision). Rejections, failures and
    hand-written summaries also set it, so a rejection does not trigger an
    automatic regeneration of the same text. Staleness (case 19) is judged
    separately, against the live summary's own generated-from sketch.
    Comparison is never against the previous save, so many small edits add
    up.
14. The plugin's own block and shortcode are never part of the source text.
    Inserting the block, or the theme printing the summary, must not change
    the fingerprint. Without this rule, approving a summary changes the post
    and queues a regeneration of itself.
15. When the source text is longer than the input limit (default 30000
    characters), it is cut at the last paragraph break before the limit
    (or the last sentence end, or the limit itself), and the candidate
    records `truncated: true`. The editor sees a note.

### 4.3 Triggers and queueing

16. The plugin listens to `wp_after_insert_post` (so terms and meta are
    saved first). It ignores autosaves, revisions, `DOING_AUTOSAVE`, and
    saves while `WP_IMPORTING` is defined.
17. On a save of an eligible post, it computes the fingerprint. If there is
    no live summary and no candidate, it queues a generation. If there is
    one, it queues only on a material change. Saves with no material change
    (bulk edits of categories, status flips, meta-only updates) queue
    nothing.
18. Debounce: a queued generation runs after a delay (default 300 seconds,
    range 0 to 3600). Each new material save pushes the run time back. One
    post has at most one queued job at any time.
19. On a material change to a post with a live summary, the live summary is
    marked stale at once (before any generation), action `stale` fires, and
    the page caches are told (case 58). With the default stale display
    setting `hide`, readers stop seeing it at that moment. Approving a
    candidate whose source the post has since moved away from materially
    makes a live summary that is stale from the start.
20. If the stale display setting is `keep`, the stale live summary stays
    visible until a new candidate is approved, rejected with "keep
    previous", or the editor withdraws it.
21. A post that drops below the word minimum keeps its stored data but is
    not displayed and not regenerated. Status shows `short`. Rising back
    above the minimum makes it eligible again and the normal rules apply.
22. A post leaving the trigger statuses (to draft, or to trash) cancels its
    queued job. Trashing keeps all data; restoring makes it eligible again.
23. A post that gains a password or becomes private cancels its queued job
    and its summary is not displayed.
24. Manual Generate (editor sidebar, REST, or WP-CLI `generate`) queues a
    job with no debounce, even with no material change and for drafts. It
    still respects the kill switch, the daily caps and the per-post limit,
    and the word minimum unless `force` is given by a user who holds the
    settings capability.
25. Queue backend: when `gen-queue` is active, the plugin uses its
    published soft interface. It registers job type `tldr-block.generate`
    on the action `gen_queue_register_types` with
    `gen_queue_register_type()` (group `tldr-block`, one attempt, its
    `meter` set to purpose `tldr-block.summary`), and enqueues through
    `apply_filters('gen_queue_enqueue_job', null, $type, $payload, $args)`
    with payload `{post_id, token}`, idempotency key
    `post:<id>:<token>`, `run_after` set from the debounce, and group
    `tldr-block`. The filter returns a job id; `null` means gen-queue is
    absent and a `WP_Error` means the enqueue failed, and in both cases
    the plugin falls back to WP-Cron. gen-queue's idempotency returns an
    existing queued job unchanged, so a reschedule cannot move its run
    time: each schedule therefore gets a fresh token, the earlier job is
    cancelled with `gen_queue_cancel()`, and an earlier job that still
    runs finds a token that no longer matches and does nothing. Without
    gen-queue the plugin schedules a single WP-Cron event
    `tldr_block_generate` with argument `[post_id]`, removing any earlier
    event for the same post first. gen-queue's own cap and switch apply on
    top of the plugin's: with no gen-queue cap set, its jobs wait. The
    handler reports the provider's cost to gen-queue with
    `$ctx->addCost()`, marked `metered` when spend-meter already recorded
    the call, so the queue's cap sees the spend once.
26. The handler for either backend is the same function. It re-reads the
    post and re-checks eligibility at run time. A post no longer eligible
    is skipped with a logged reason and no spend.

### 4.4 Generation

27. A running job takes a per-post lease (status `generating`, lease 10
    minutes). A second runner that finds a live lease skips the post. An
    expired lease is taken over.
28. Before the call, the job reserves one unit of the daily cap (case 34)
    and checks the per-post limit (case 36), the cost cap (case 35) and
    `spend-meter` when present (case 38). Any refusal sets status
    `deferred` with the reason and stops without calling.
29. The prompt is built from the template (case 44) with: title, source
    text, target language, format, point counts and word limits.
30. The call goes through the plugin's own `Caller` interface (builder
    decision): the filter `tldr_block_client` may return a `Caller` (so a
    site can run without `llm-json-php`, group C ruling of 14 November) or
    an `llm-json-php` `Client`, which the plugin adapts; otherwise the
    plugin builds a client from settings (provider, key, model chain).
    When no caller can be had, the job fails before anything is reserved. Purpose name `tldr-block.summary`.
    Temperature 0.2, output token cap 800, total deadline 90 seconds.
31. The JSON schema in bullet mode is an object with one required key
    `points`, an array of strings. In paragraph mode it is an object with
    one required key `summary`, a string. `additionalProperties` is false.
    The guard profile turns on sentence-end checks and rejects control,
    zero-width and replacement characters.
32. Output validation after the library returns (all must pass, else the
    call counts as a failed attempt):
    1. Bullet mode: point count between the minimum and maximum (default 3
       and 5). Paragraph mode: one non-empty string.
    2. Each point at most the word limit times 1.5 (default limit 30 words
       per point); the paragraph at most the paragraph limit times 1.5
       (default 80 words). Points between the limit and 1.5 times it pass
       with a warning.
    3. After trimming, no empty point and no duplicate point (compared
       case-insensitively).
    4. Any HTML tag or Markdown markup (leading `-`, `*`, `#`, numbering)
       is stripped from each point; a point that is empty after stripping
       fails the check.
    5. No URL in any point.
    6. Optional script check: when the target language maps to a known
       script, at least 50 percent of the letters must be in that script.
33. On success the candidate is stored (section 6) with status `pending`,
    action `generated` fires, and the job ends. A candidate is never shown
    to readers.
34. Daily cap: a count of generation attempts per site per local day (site
    time zone), default 50, range 0 to 10000 (0 means no generations). The
    count is reserved before the call under a lock and is not given back on
    failure, because a failed call may still be billed. When the cap is
    reached, action `cap_reached` fires once per day and every further job
    is deferred until the next local day.
35. Optional daily cost cap (decimal, blank means none). It applies only
    when the client reports cost. Cost of each call (from the library's
    usage report) is added to the day's total. Once the total reaches the
    cap, further jobs are deferred. When cost is not reported, the setting
    screen says the cost cap is inactive.
36. Per-post limit: at most 3 generations per post per local day (setting,
    range 1 to 20), counting manual and automatic ones. Further triggers
    for that post are deferred to the next day.
    Every attempt counts, retries included (builder decision): with the
    default limit of 3, a post whose first three attempts fail is deferred
    to the next day rather than making a fourth call the same day.
37. Deferred jobs are retried after the local day rolls over (scheduled at
    00:05 site time plus a random jitter up to 30 minutes). Deferred jobs
    run in order of oldest change first.
38. When `spend-meter` is active (`SPEND_METER_API` equals 1), the job
    applies its filter `spend_meter_pre_spend` with `null` and an intent
    array: `purpose` `tldr-block.summary`, `caller` `tldr-block`,
    `provider` (a lower-case slug), `model`, `est_input_tokens` (prompt
    characters divided by four) and `max_output_tokens` (800). A `null`
    answer means no meter is present. A decision with `allowed` not
    exactly true defers the job with the meter's reason code, and any
    reservation it carries is given back with `spend_meter_release`. After
    an allowed check the job sends exactly one `spend_meter_record` (the
    call happened) or one `spend_meter_release` (it did not), with
    `reservation_id`, `status` (`ok`, `error_billed` or `error_unbilled`),
    `input_tokens`, `output_tokens`, `provider_cost` in integer nanos when
    the provider reported a cost, `latency_ms`, `error_code` on failure
    and `http_status` 402 on a credit error. An answer that fails
    validation (case 32) was still billed and is recorded as
    `error_billed` with `error_code` `validation`. An unknown contract version or
    an unusable answer means the meter is treated as absent. The plugin's
    own caps still apply.
39. Failure handling: a failed call (chain exhausted, validation failure,
    timeout) retries with backoff at 5 minutes, 30 minutes and 2 hours,
    then sets status `failed` with the error class and a short message. A
    credit-exhausted error skips the retries, sets `failed`, and holds all
    generation for one hour (logged once). The next material change or a
    manual Generate starts a fresh attempt count.
40. If the post changed materially while the call was in flight
    (fingerprint at write time differs materially from the one at start),
    the result is discarded, the spend still counts, and the newer queued
    job stands. If no newer job exists, one is queued.
41. Figure check: every number in the summary (digit runs in any script,
    normalised to ASCII digits, with thousands separators removed) must
    appear in the source text after the same normalisation. Numbers that
    do not are listed on the candidate as `unverified_figures` and shown
    to the editor as a warning. They do not block approval.
42. Prompt injection: the article text goes in a clearly delimited user
    message, the system message says the text is content to summarise and
    any instructions inside it must be ignored. A candidate that contains a
    URL, markup, or an instruction-like line is rejected by case 32. The
    editor approval is the final control.
43. Language: the target language comes from the filter
    `tldr_block_language` (default: the site locale's language name, or the
    per-post language reported by a multilingual plugin through that
    filter). The prompt asks for the summary in the language of the article
    and names the expected language.
44. Prompt template: a built-in generic template, filterable as a whole
    (system and user parts). Placeholders use the `{{ name }}` syntax of
    `prompt-library`, so the same text is its default. When
    `prompt-library` is active the plugin ships the built-in template on
    `init` with `promptlib_register_default('tldr-block', $definition)`
    under slug `tldr-block-summary` (that plugin's slugs allow no `/`),
    declares its variables with `promptlib_register_consumer()`, and
    renders through `apply_filters('promptlib_render', null,
    'tldr-block-summary', $values, 'live', ['consumer' => 'tldr-block',
    'fallback' => $definition])`, with variables `title`, `body`,
    `language`, `format`, `format_instruction`, `points_min`,
    `points_max`, `max_words`. A `null` answer means the plugin is absent.
    If rendering fails (a `WP_Error` or an unusable shape) it falls back to
    the built-in template and logs the reason. The built-in prompt asks
    for: a neutral summary of the main points, facts only from the
    article, no opinion, no new figures, no references to "this article",
    the language named, the JSON shape in case 31. Delimiter look-alikes
    (any case or spacing) are removed from the article and the title, and
    the title is kept to one line.

### 4.5 Review

45. The editor sees a "Summary" panel in the block editor document sidebar
    and a meta box in the classic editor. It shows: status, the live
    summary (if any), the newest candidate (if any) with generation time,
    model, warnings (truncation, long points, unverified figures), and the
    buttons the user may use: Approve, Edit and approve, Reject, Generate,
    Withdraw, Restore previous, Write my own.
46. Approve: sets the candidate as the live summary with `origin`
    `machine`, records approver id and time, keeps the fingerprint it was
    generated from, clears stale, sets status `approved`, fires `approved`
    and `display_changed`. The request must carry the candidate id; if a
    newer candidate replaced it, the request fails with HTTP 409 and the
    panel reloads.
47. Edit and approve: the editor changes the points (or paragraph) before
    approving. The text is validated by case 32 rules 1 to 5 (counts,
    lengths up to 1.5 times the limit, no empties, no markup, no URLs) and
    saved with `origin` `edited`.
48. Write my own: a user with the approve capability writes a summary with
    no candidate. Saved as live with `origin` `human` and the current
    fingerprint. No model call.
49. Reject: marks the candidate rejected (kept in history with an optional
    reason up to 200 characters), sets status `rejected`. No automatic
    regeneration follows for the same fingerprint; the next material change
    or manual Generate makes a new candidate. If a stale live summary
    exists, Reject offers "keep previous": the previous live summary is
    re-approved against the current fingerprint (stale cleared), recorded
    as an approval by this user.
50. A live summary is displayable when: the display switch is on; the
    resolved mode is on; the post is published and has no password, or the
    current user can read it; the word count is at or above the minimum;
    the summary is not withdrawn; and it is not stale, or stale display is
    `keep`.
51. Withdraw: hides the live summary without deleting it (status
    `withdrawn`), fires `withdrawn` and `display_changed`. Restore previous
    or Approve brings a summary back.
52. Restore: picks any entry from history (live summaries and candidates)
    and makes it live with the current fingerprint, recorded as an
    approval by this user with `origin` `restored`.
53. Authors without the approve capability see the panel read-only, plus
    Generate on their own posts (subject to caps). Users without
    `edit_post` on the post see nothing.
54. A review screen (Posts, Summaries) lists posts with status `pending`,
    `failed`, `deferred` and stale summaries, newest first, with filters by
    status and category, a preview of the candidate, and links to the
    editor. Each row has Approve and Reject for users with the capability.
    There is no bulk approve (section 13).
55. A Posts list column shows the summary status with a filter dropdown.

### 4.6 Display

56. Automatic insertion (default placement `top`): the summary is prepended
    to the post content through `the_content` at priority 20 (after
    paragraphs are formed). It is inserted only when all hold: the request
    is a singular view of that post; the filter runs inside the main query
    loop for the global post; it is not a feed (unless feed display is on);
    it is not running inside `get_the_excerpt` or `wp_trim_excerpt`; it is
    not a REST request (unless REST content insertion is on); the post
    content does not already contain the block or the shortcode; and the
    summary has not been printed for this post in this request.
57. Placement `after_first_paragraph` inserts after the first closing
    `</p>` at the top level; if there is none, it falls back to the top.
    Placement `none` disables automatic insertion; the shortcode, block and
    template function still work.
58. Display changes: approve, withdraw, restore, stale and switch changes
    call `clean_post_cache()` for the post and fire
    `tldr_block_display_changed` with the post id and reason, so page
    caches and CDNs can purge. Category switch changes fire it once per
    affected category with the term id, not once per post.
59. Shortcode `[tldr]` with optional attributes `post_id` (default the
    current post), `heading` (text, default the setting), and `heading`
    empty string to hide the heading. With another post's id it renders
    only if that post is displayable to the current user (case 50). An
    invalid id renders nothing. Nested or repeated shortcodes for the same
    post render once per request.
60. Block `tldr-block/summary`: a dynamic block (server-side render from
    `block.json`, `apiVersion` 3) with attributes `heading` (string) and
    `showHeading` (boolean, default true). It always shows the current
    post's summary. In the editor it previews the live summary, or a muted
    placeholder ("Summary pending review", "No summary yet", "Summaries are
    off for this category") that is never rendered on the front end.
    Inserting the block disables automatic insertion for that post.
61. Template function `tldr_block_the_summary( ?int $post_id = null,
    array $args = [] )` echoes the escaped markup;
    `tldr_block_render()` returns it.
62. Markup: an `aside` with class `tldr-block`, `aria-label` equal to the
    heading text, a heading element (default `p` with class
    `tldr-block__heading`, filterable to `h2` to `h6`), then a `ul` with
    class `tldr-block__points` and one `li` per point, or one `p` with
    class `tldr-block__text` in paragraph mode. A `lang` attribute is set
    when the summary language differs from the page language. Every string
    is escaped. The whole HTML is filterable.
63. Styles: one small stylesheet (under 2 KB) loaded only on pages that
    print a summary, with logical properties so right-to-left scripts work.
    A filter turns it off.
64. REST field `tldr_block` on the post endpoint (public read): the
    displayable summary as `{points|text, format, approved_at, origin}`, or
    null. Never exposes candidates, history, reasons or approver ids
    publicly.
65. Feeds: off by default. When on, the summary is prepended to the feed
    content using the same markup without styles.

### 4.7 Administration and tools

66. WP-CLI `wp tldr-block backfill [--category=<slug>] [--post_type=<t>]
    [--limit=<n>] [--dry-run]` queues eligible published posts that have no
    live summary and no candidate, most recently modified first, up to the limit
    (default 50) and never more than the day's remaining cap unless
    `--spread` is given, which schedules the rest on following days. It
    never approves anything. `--dry-run` prints what would be queued and
    the estimated number of days.
67. WP-CLI `wp tldr-block status [<post_id>]` prints today's count and cap,
    cost total if known, queue length, counts by status, and for a post its
    full state. `wp tldr-block generate <post_id> [--force]` queues one.
    `wp tldr-block run [--max=<n>]` runs due jobs now (for system cron).
    `wp tldr-block pause` and `resume` flip the kill switch.
68. Settings screen (Settings, Summaries; capability `manage_options`):
    every option in section 7, the provider key field (write-only, shows
    the last four characters), a "Test connection" button that makes one
    tiny call (counted against the cap and labelled so), and today's
    counters.
69. Category edit screen: mode (inherit, on, off) and minimum words
    override. Saving fires case 58.
70. Site Health: one test that reports provider configured or not, kill
    switch state, WP-Cron disabled without a system cron, jobs overdue by
    more than an hour, and the last error.
71. Uninstall removes the plugin's options, term meta, post meta with the
    plugin's prefix, scheduled events and transients, in batches of 500, on
    each site of a network. It does not call any provider.

### 4.8 Failure modes

| Failure | Behaviour | Open or closed |
|---|---|---|
| Provider down, chain exhausted | Retries then `failed`; live summary unchanged | Closed: nothing new is shown |
| Credit exhausted | `failed`, one-hour hold, logged once | Closed |
| Malformed or invalid output | Counts as a failed attempt | Closed |
| Cap reached | `deferred` until next local day | Closed |
| Lock or option write fails | Job skipped, logged, retried next tick | Closed |
| WP-Cron not running | Jobs wait; Site Health warns | Closed |
| Meta read fails on the front end | Nothing printed | Open for the page: the page renders without the block |
| Stale summary | Hidden by default | Closed |
| `spend-meter` errors or unknown version | Treated as absent; own caps apply | Open to own caps only |
| `prompt-library` render fails | Built-in template, logged | Open to the built-in prompt |
| Post edited mid-call | Result discarded, newer job stands | Closed |

Every error path writes a log line (section 11). None is silent.

## 5. Public interface

### 5.1 PHP functions

| Function | Returns | Notes |
|---|---|---|
| `tldr_block_get( int $post_id ): ?\IndianOpinion\TldrBlock\Summary` | displayable summary or null | applies case 50 for the current user |
| `tldr_block_render( ?int $post_id = null, array $args = [] ): string` | escaped HTML or `''` | args `heading`, `show_heading`, `heading_tag`, `context` |
| `tldr_block_the_summary( ?int $post_id = null, array $args = [] ): void` | echoes | |
| `tldr_block_state( int $post_id ): array` | full state (section 6) | requires nothing; callers must check capability before showing it |
| `tldr_block_queue( int $post_id, array $args = [] ): true\|\WP_Error` | | args `manual` (bool), `force` (bool). Errors: `kill_switch`, `not_eligible`, `cap_reached`, `post_limit`, `no_provider` |
| `tldr_block_mode_for( int $post_id ): string` | `on` or `off` | case 4 |
| `tldr_block_is_enabled(): bool` | false when the kill switch is on | fresh read |

`true|WP_Error` is written as a PHPDoc union; PHP 8.1 code returns `bool|\WP_Error` in the signature.

### 5.2 Value object

`Summary` (immutable): `format()` (`bullets` or `paragraph`), `points()`
(list of strings, empty in paragraph mode), `text()`, `language()`,
`origin()` (`machine`, `edited`, `human`, `restored`), `approvedAt()`
(UTC `DateTimeImmutable`), `approvedBy()` (int), `generatedAt()` (nullable),
`model()` (nullable string), `isStale()`, `toArray()`.

### 5.3 Actions

All receive the post id first.

| Action | Arguments |
|---|---|
| `tldr_block_queued` | `int $post_id, string $reason` (`save`, `manual`, `backfill`, `retry`) |
| `tldr_block_generated` | `int $post_id, array $candidate` |
| `tldr_block_failed` | `int $post_id, string $error_class, string $message, int $attempt` |
| `tldr_block_deferred` | `int $post_id, string $reason` |
| `tldr_block_approved` | `int $post_id, array $live, int $user_id` |
| `tldr_block_rejected` | `int $post_id, string $candidate_id, int $user_id, string $reason` |
| `tldr_block_withdrawn` | `int $post_id, int $user_id` |
| `tldr_block_stale` | `int $post_id, float $distance` |
| `tldr_block_display_changed` | `int $id, string $reason` (`approved`, `withdrawn`, `restored`, `stale`, `category_switch`, `post_switch`); for `category_switch` the id is a term id |
| `tldr_block_cap_reached` | `string $kind` (`count`, `cost`), `string $day` (no post id) |
| `tldr_block_log` | `string $level, string $message, array $context` (no post id) |

### 5.4 Filters

| Filter | Default | Purpose |
|---|---|---|
| `tldr_block_is_eligible` | computed bool, `int $post_id`, `string $reason` | veto or allow (cannot override kill switch or caps) |
| `tldr_block_allow_private` | false | allow private posts |
| `tldr_block_mode` | resolved `on`/`off`, `int $post_id` | case 4 |
| `tldr_block_word_count` | computed int, `string $text` | case 2 |
| `tldr_block_source_text` | built text, `\WP_Post $post` | case 10 |
| `tldr_block_language` | locale language, `int $post_id` | case 43 |
| `tldr_block_prompt` | `['system' => ..., 'user' => ...]`, `array $vars` | case 44 |
| `tldr_block_schema` | schema array, `string $format` | case 31 |
| `tldr_block_validate` | `string[]` errors, `array $output`, `array $context` | add checks |
| `tldr_block_client` | null, `array $settings` | supply a `Caller` or an `llm-json-php` `Client` (case 30) |
| `tldr_block_daily_cap` | setting | constant `TLDR_BLOCK_DAILY_CAP` wins over both |
| `tldr_block_approve_capability` | `edit_others_posts` | review rights |
| `tldr_block_settings_capability` | `manage_options` | settings and `force` |
| `tldr_block_should_autoinsert` | computed bool, `int $post_id` | case 56 |
| `tldr_block_heading_tag` | `p` | case 62 |
| `tldr_block_html` | HTML, `Summary $summary`, `array $args` | final markup |
| `tldr_block_load_styles` | true | case 63 |
| `tldr_block_debounce_seconds` | setting | case 18 |
| `tldr_block_change_threshold` | setting | case 13 |

### 5.5 REST routes

Namespace `tldr-block/v1`. Cookie authentication with the `wp_rest` nonce
or application passwords. Every route checks `edit_post` on the post; the
write routes marked A also check the approve capability.

| Method and route | Body | Result |
|---|---|---|
| `GET /posts/<id>` | | state for the panel |
| `POST /posts/<id>/generate` | `force` (bool) | 202 queued, or 409/429 with the error code |
| `POST /posts/<id>/approve` (A) | `candidate_id`, optional `points` or `text` | 200 state, 409 when superseded, 422 on validation |
| `POST /posts/<id>/reject` (A) | `candidate_id`, `reason`, `keep_previous` (bool) | 200 state |
| `POST /posts/<id>/withdraw` (A) | | 200 state |
| `POST /posts/<id>/restore` (A) | `history_id` | 200 state |
| `PUT /posts/<id>/manual` (A) | `points` or `text` | 200 state, 422 on validation |
| `POST /posts/<id>/override` (A) | `mode` (`auto`, `on`, `off`) | 200 state; fires `display_changed` with `post_switch` (builder addition: the per-post switch of G7) |

Meta keys are registered as protected and are not writable through the core
posts endpoint. The only public read is the field in case 64.

### 5.6 Shortcode, block, constants, CLI

- Shortcode `[tldr post_id="" heading=""]` (case 59).
- Block `tldr-block/summary` (case 60), category `text`, supports
  `align` (wide, full), `spacing`, `color`. No build step: editor scripts
  use the `wp` globals.
- Constants: `TLDR_BLOCK_DISABLED` (bool), `TLDR_BLOCK_API_KEY` (string),
  `TLDR_BLOCK_DAILY_CAP` (int). Constants win over settings and lock the
  field on the settings screen.
- WP-CLI: `wp tldr-block backfill|status|generate|run|pause|resume`
  (cases 66 and 67).

## 6. Data

### 6.1 Post meta (all protected, single values, not revisioned)

| Key | Content |
|---|---|
| `_tldr_block_status` | one of `none`, `queued`, `generating`, `pending`, `approved`, `rejected`, `failed`, `deferred`, `withdrawn`, `short` |
| `_tldr_block_live` | array: `format`, `points`, `text`, `language`, `origin`, `approved_by`, `approved_at`, `generated_at`, `model`, `source_hash`, `source_sketch`, `stale` (bool) |
| `_tldr_block_candidate` | array: `id`, `format`, `points`, `text`, `language`, `model`, `provider`, `generated_at`, `source_hash`, `source_sketch`, `truncated`, `warnings`, `unverified_figures`, `prompt_ref` (template slug and version, or `builtin`) |
| `_tldr_block_history` | list of up to N earlier live summaries and rejected candidates (default 5, oldest dropped), each with `history_id`, `kind`, the fields above, and `reason` for rejections |
| `_tldr_block_override` | `auto`, `on` or `off` |
| `_tldr_block_job` | `attempts`, `next_run`, `lease_until`, `last_error_class`, `last_error`, `day`, `day_count` (per-post limit) |

The sketch is stored as a base64 string of 128 packed 32-bit integers
(about 700 bytes). No source text is stored.

### 6.2 Term meta

`_tldr_block_mode` (`inherit`, `on`, `off`), `_tldr_block_min_words`
(int or empty).

### 6.3 Options

`tldr_block_settings` (autoloaded, no secrets), `tldr_block_api_key`
(not autoloaded), `tldr_block_counters` (`day`, `count`, `cost`,
`cap_reached_sent`, not autoloaded), `tldr_block_hold_until` (credit hold).

### 6.4 Retention and uninstall

Data lives as long as the post. Deleting a post deletes its meta through
core. History is capped per post. Uninstall is case 71. No logs are stored
in the database; logging goes to the PHP error log and the action.

## 7. Configuration and defaults

| Setting | Default | Range or values |
|---|---|---|
| Generation (kill switch) | off until a key and model are set, then on | on, off; constant wins |
| Display | on | on, off |
| Global default mode | off (ruling 10) | on, off |
| Post types | `post` | any public type that supports the editor |
| Trigger statuses | `publish`, `future`, `pending` | any of these plus `draft` |
| Minimum words | 600 | 100 to 10000 |
| Format | bullets | bullets, paragraph |
| Points | 3 to 5 | min 1 to 8, max min to 10 |
| Words per point | 30 | 8 to 60 |
| Paragraph words | 80 | 30 to 200 |
| Heading text | "Summary" (translatable) | up to 60 characters, empty hides |
| Placement | top | top, after_first_paragraph, none |
| Stale display | hide | hide, keep |
| Change threshold | 0.10 | 0.01 to 0.90 |
| Debounce | 300 s | 0 to 3600 |
| Daily cap | 50 generations | 0 to 10000 |
| Daily cost cap | none | decimal, provider currency |
| Per-post daily limit | 3 | 1 to 20 |
| Input limit | 30000 characters | 2000 to 200000 |
| History entries | 5 | 0 to 20 |
| Feeds | off | on, off |
| REST content insertion | off | on, off |
| Provider | OpenRouter | OpenRouter, OpenAI, OpenAI-compatible (base URL) |
| Model chain | empty | one to three model ids |

Every default passes through `tldr_block_default_settings` (filter) before
the stored values are merged.

## 8. Security and privacy

- Capability checks on every admin action and REST route (5.5); nonces on
  every form; the settings screen needs `manage_options`.
- Meta is protected and not editable through the generic posts endpoint, so
  a contributor cannot write an approved summary by saving meta.
- All output escaped: points with `esc_html`, attributes with `esc_attr`.
  Stored text is plain text; markup is stripped on the way in (case 32).
- The API key is read from the constant, then the environment variable
  `TLDR_BLOCK_API_KEY`, then the option. It is never printed back, never
  sent to the browser, never logged.
- Only the title and the source text of eligible posts go to the provider.
  Password-protected and private posts are never sent by default. No author
  data, comments or reader data is sent.
- The README tells operators that post text is sent to a third-party model
  provider and that their privacy policy should say so. The plugin adds a
  suggested paragraph through the core privacy policy guide.
- Article text is treated as untrusted input to the model (case 42).
- No `eval`, no shell, no remote fetch other than the model call. No `@`
  error suppression.

## 9. Performance budget

- Front end: zero external calls, zero extra database queries when the
  post meta cache is primed (the main query primes it), at most one query
  otherwise. Rendering under 1 ms. The stylesheet is under 2 KB and loads
  only where a summary prints.
- Save hook: under 20 ms for a 10000-word post (fingerprint is one pass to
  hash shingles plus a bounded heap of 128). No model call on save.
- Generation: one model call per job (plus retries the library makes),
  deadline 90 seconds. A cron tick runs at most 3 jobs or 60 seconds,
  whichever comes first. `wp tldr-block run` takes `--max`.
- Uninstall and backfill work in batches of 500 posts.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 or later. Block editor and classic
  editor. Multisite with per-site settings.
- Runtime dependency: `indianopinion/llm-json-php`, listed as a `vcs`
  repository in `composer.json`. The release zip bundles it under `lib/`
  and loads it with its bootstrap file, so no Composer is needed at
  runtime. If another plugin already loaded the library, the bundled copy
  is not loaded.
- Optional: `gen-queue` (filter `gen_queue_enqueue_job` hooked),
  `spend-meter` (`SPEND_METER_API` equals 1) and `prompt-library` (filter
  `promptlib_render` hooked), each used through its published 0.1.0
  interface (cases 25, 38 and 44).
- Page caches and CDNs: the plugin fires `tldr_block_display_changed` and
  calls `clean_post_cache()`; it does not purge any cache itself. The
  README shows how to hook a purge.
- Object caches: counters use a lock held in the options table, not the
  object cache, so a flushed or split cache cannot double-count.
- Multilingual plugins: per-post language through `tldr_block_language`;
  each translation is its own post and gets its own summary.
- Themes: minimal CSS, classes documented, heading tag filterable.
- `intl` extension optional (NFC normalisation and better word breaking).

## 11. Observability

- Log lines go to `error_log` with the prefix `tldr-block:`, one line per
  event: queued, generated, failed (error class, attempt), deferred
  (reason), approved, rejected, stale (distance), cap reached. Lines name
  the post id, never the post text. Repeating error lines are throttled to
  one per ten minutes per kind. Every line also fires `tldr_block_log`.
- Debug level (setting, off by default) adds token counts, model, and
  duration.
- The settings screen shows today's count against the cap, cost if known,
  pending reviews, failed jobs, deferred jobs, next scheduled run, and the
  last five errors.
- Posts list column and filter (case 55), the review screen (case 54), Site
  Health (case 70), and WP-CLI `status` (case 67).

## 12. Acceptance tests

Each test names the case it covers. Model calls use a fake `llm-json-php`
client supplied through `tldr_block_client`.

1. T1 (case 1): an eligible post queues a job; each failing condition
   alone (type, status, password, private, short, mode off, kill switch, no
   provider) queues nothing.
2. T2 (case 2): word counts for English, Hindi with combining marks,
   Tamil, Japanese and mixed text match fixed expected values.
3. T3 (case 3): a category minimum of 300 makes a 400-word post eligible;
   blank inherits the global 600.
4. T4 (case 4): post override beats category; `off` in one category beats
   `on` in another; a child inherits a parent's `off`; no setting falls to
   the global default; a type without categories uses the global default.
5. T5 (case 5): switching a category off hides an approved summary at the
   next render with no model call; switching it on shows it again.
6. T6 (case 6): switching a category on queues nothing.
7. T7 (case 7): kill switch on: saves queue nothing, a queued job does not
   call, manual Generate returns `kill_switch`, approved summaries still
   render; the constant locks the switch.
8. T8 (case 8): display off prints nothing anywhere; generation continues.
9. T9 (case 9): activation with no key makes no call and queues nothing.
10. T10 (case 10): source text drops tags, scripts, shortcode tags and
    entities and keeps caption and heading text.
11. T11 (case 11): changing only the title is not a material change.
12. T12 (case 12): the sketch of a fixed text equals a stored fixture;
    a two-word text produces a sketch.
13. T13 (case 13): a one-word edit to a 1000-word post is not material;
    adding 150 words is; ten small edits that add up past the threshold
    are material.
14. T14 (case 14): inserting the block or shortcode into the content leaves
    the fingerprint unchanged and queues nothing.
15. T15 (case 15): a 40000-character text is cut at a paragraph break and
    the candidate has `truncated` true.
16. T16 (case 16): autosave, revision and `WP_IMPORTING` saves queue
    nothing.
17. T17 (case 17): a bulk category edit on a post with a live summary
    queues nothing.
18. T18 (case 18): three material saves within the debounce leave one job,
    run time set from the last save.
19. T19 (case 19): a material change marks the live summary stale and it
    no longer renders; `display_changed` fires with `stale`.
20. T20 (case 20): with stale display `keep` the stale summary renders
    until a new approval.
21. T21 (case 21): cutting a post below the minimum hides it and sets
    `short`; extending it restores display.
22. T22 (case 22): moving to draft or trash cancels the job; restoring
    from trash keeps data.
23. T23 (case 23): adding a password cancels the job and hides the summary.
24. T24 (case 24): manual Generate on a draft queues with no debounce;
    `force` on a short post works only for a settings-capable user.
25. T25 (case 25): with a fake gen-queue filter returning an id, the
    payload, idempotency key and run time match; without it a single
    WP-Cron event exists for the post.
26. T26 (case 26): a post made ineligible after queueing is skipped with
    no call.
27. T27 (case 27): a live lease makes a second runner skip; an expired
    lease is taken over.
28. T28 (case 28): a refused reservation sets `deferred` and makes no call.
29. T29 (case 29): the prompt variables contain title, text, language and
    limits.
30. T30 (case 30): a filter-supplied client is used; settings build a
    client with the configured chain otherwise.
31. T31 (case 31): the schema for each format matches the fixture.
32. T32 (case 32): outputs with too few points, too many, an over-long
    point, an empty point, duplicates, Markdown bullets, HTML, a URL, and
    the wrong script are each handled as specified.
33. T33 (case 33): a valid output becomes a pending candidate and does not
    render on the front end.
34. T34 (case 34): with a cap of 2, the third job defers; a failed call
    still uses a unit; `cap_reached` fires once.
35. T35 (case 35): a reported cost reaching the cost cap defers the next
    job; no reported cost leaves the cap inactive.
36. T36 (case 36): the fourth generation for one post in a day defers.
37. T37 (case 37): deferred jobs are scheduled after local midnight in
    oldest-change order.
38. T38 (case 38): a fake spend-meter deny defers with its reason; an allow
    with a reservation is followed by exactly one record; a failure sends
    one record with an error status or a release; an unknown version is
    ignored.
39. T39 (case 39): failures retry at the three delays then set `failed`; a
    credit error sets the one-hour hold.
40. T40 (case 40): a material edit during the call discards the result.
41. T41 (case 41): a summary with a figure absent from the text lists it
    as unverified; Devanagari digits match ASCII digits; "1,200" matches
    "1200".
42. T42 (case 42): the article text sits only in the user message inside
    delimiters, and the system message carries the ignore-instructions
    sentence.
43. T43 (case 43): a filter-supplied language reaches the prompt.
44. T44 (case 44): with a fake prompt-library the rendered template is
    used; a render error falls back to the built-in one and logs.
45. T45 (case 45): the panel state from `GET /posts/<id>` lists the
    buttons allowed for an editor and for an author.
46. T46 (case 46): approve makes the candidate live; approving a
    superseded id returns 409.
47. T47 (case 47): edited text is validated and saved with `origin`
    `edited`; invalid text returns 422.
48. T48 (case 48): a hand-written summary goes live with no call.
49. T49 (case 49): reject records the reason and queues nothing; "keep
    previous" clears stale.
50. T50 (case 50): each displayability condition alone hides the summary.
51. T51 (case 51): withdraw hides; approve or restore shows again.
52. T52 (case 52): restore from history goes live with `origin` `restored`.
53. T53 (case 53): an author cannot approve (403); a subscriber cannot read
    the state (403).
54. T54 (case 54): the review screen lists pending, failed, deferred and
    stale posts; a user without the capability sees no Approve.
55. T55 (case 55): the list column shows each status and the filter
    narrows the list.
56. T56 (case 56): auto-insertion happens on a singular view; not in an
    archive loop, a feed, an excerpt, a REST response, a secondary query,
    or when the content already holds the block; never twice in one
    request.
57. T57 (case 57): `after_first_paragraph` inserts after the first `</p>`
    and falls back to the top; `none` inserts nothing.
58. T58 (case 58): approve, withdraw, stale and a category switch each
    fire `display_changed` once with the right reason.
59. T59 (case 59): the shortcode renders the current post, another public
    post, nothing for a private post the user cannot read, and nothing for
    an invalid id.
60. T60 (case 60): the block renders server-side; its placeholder never
    reaches the front end; its presence disables auto-insertion.
61. T61 (case 61): the template function echoes what `tldr_block_render`
    returns.
62. T62 (case 62): markup matches a fixture, including `lang`, escaping of
    `<script>` in a point, and a filtered `h2`.
63. T63 (case 63): the stylesheet is enqueued only when a summary prints
    and not when the filter returns false.
64. T64 (case 64): the REST field returns the displayable summary or null
    and never candidate data.
65. T65 (case 65): feed output carries the summary only when feeds are on.
66. T66 (case 66): backfill `--dry-run` queues nothing; a real run queues
    up to the remaining cap; `--spread` schedules the rest on later days.
67. T67 (case 67): each CLI command prints and acts as documented.
68. T68 (case 68): the key field never echoes the key; the test call counts
    against the cap.
69. T69 (case 69): saving a category's mode stores it and fires case 58.
70. T70 (case 70): Site Health reports each listed condition.
71. T71 (case 71): uninstall leaves no option, meta, term meta or event
    with the prefix, on each site.
72. T72 (failure table): each row in 4.8 produces its stated behaviour and
    one log line.
73. T73 (section 8): the meta keys cannot be written through the core
    posts endpoint by an author.
74. T74 (section 9): fingerprinting a 10000-word fixture takes under 20 ms
    on the CI runner (reported, not a hard failure on slow runners).

## 13. Open questions

The builder answers each in `docs/ARCHITECTURE.md`.

1. Category conflicts: should `off` in any category win over `on` in
   another (this PRD's default), or should the primary category decide?
2. Stale display default: `hide` (this PRD) or `keep` the old approved
   summary until the new one is reviewed?
3. Change threshold: is 0.10 Jaccard distance on word 3-shingles the right
   default? The builder calibrates it on sample edits (typo fix, added
   paragraph, rewritten lede) and records the numbers.
4. Should a title change alone ever count as material (for example when
   the title changes by more than half its words)?
5. Bulk approve on the review screen: left out of 0.1.0 to keep each
   approval a deliberate read. Confirm or add with a per-item preview.
6. Should authors be allowed to approve summaries of their own posts
   (capability `edit_published_posts`), or only editors?
7. Should backfill also be offered in the admin, or stay WP-CLI only?
8. Block editor scripts with no build step (this PRD) or `@wordpress/scripts`
   with a committed build?
9. The README describes what the plugin does and carries no
   measured-result line, because none can be measured from the plugin
   alone. Confirm the plain statement the builder writes.
10. Global default mode on a fresh install: `on` (this PRD, safe because
    nothing shows without approval) or `off` so the operator opts in by
    category?

### Answers (rulings applied in 0.1.0)

Recorded in full, with reasons, in `docs/ARCHITECTURE.md`.

1. `off` in any category wins.
2. Stale summaries are hidden (`hide` stays the default).
3. 0.10 stays the default and is filterable
   (`tldr_block_change_threshold`).
4. A title change alone is never material.
5. No bulk approve.
6. `edit_others_posts` approves; authors cannot approve their own posts.
7. Backfill stays WP-CLI only.
8. No build step: editor scripts use the `wp` globals, with a hand-written
   asset file for the block's script dependencies.
9. No measured-result line in the README.
10. The global default mode on a fresh install is `off`.

Builder additions, accepted at review: the `Caller` interface and the
client filter (case 30), comparison against the newest generated-from
fingerprint (case 13), CRC32 shingle hashes (case 12), retries counting
towards the per-post limit (case 36), and the per-post override route
(section 5.5).

## 14. Lessons from production

General WordPress and model-integration traps, written as rules.

1. Never let the summary feed its own fingerprint. Strip the plugin's
   block and shortcode before hashing, or approval queues a regeneration
   of itself, forever.
2. Compare against what the summary was generated from, not the previous
   save. Otherwise a run of small edits never counts as a change.
3. Hash before you queue. Bulk edits, status flips and meta updates save
   the post without changing the text; they must cost nothing.
4. `the_content` also runs inside excerpt building and in REST and feed
   output. Insert only in the main singular view, or the summary leaks into
   meta descriptions, archive excerpts and syndication.
5. Count words with Unicode classes for letters, marks and digits. A
   pattern without combining marks splits Indic words into pieces and a
   pattern without the Unicode flag counts bytes.
6. Updating post meta does not fire `save_post`, so page caches do not
   purge. Fire your own action and clear the post cache on every display
   change.
7. Reserve the daily cap before the call and never give it back on failure.
   A failed call can still be billed, and a cap counted after the fact lets
   parallel runners overshoot.
8. Debounce saves. Editors save often; a long post saved twenty times in
   an hour is one generation, not twenty.
9. Put a per-post daily limit under the global cap, so one post in an edit
   loop cannot eat everyone's budget.
10. A model invents figures more readily than words. Check every number in
    the output against the source and show the editor the misses.
11. Treat the article as data in the prompt. Text pasted from elsewhere can
    carry instructions; delimit it and reject outputs with URLs or markup.
12. Check the result after a long call: the post may have changed while
    the model was working. Discard rather than attach a summary to text it
    never saw.
13. Truncated output is the commonest bad answer. Validate the JSON shape,
    the counts and the sentence ends, and retry on the same model once
    before moving down the chain.
14. Protect your meta keys. A key registered for REST without care lets a
    contributor write "approved" text past the review.
15. WP-Cron runs only on traffic. On a quiet site jobs wait; tell the
    operator through Site Health and offer a CLI runner for system cron.
16. Keep the kill switch and caps in this plugin even when a shared queue
    or meter is present. Shared tools can be deactivated; the plugin must
    still be safe alone.
