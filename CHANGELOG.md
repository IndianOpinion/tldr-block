# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-18

### Added

- Background generation of a short summary (bullets or one paragraph) for posts above a word minimum, through indianopinion/llm-json-php or a caller supplied by filter.
- Human review: candidates stay pending until a user with `edit_others_posts` approves, edits and approves, rejects, withdraws or restores. Hand-written summaries.
- Change detection with a SHA-256 plus a bottom-k sketch of word 3-shingles, compared with the generated-from text; stale summaries hidden by default.
- Debounced queue on WP-Cron, `gen-queue` when present, or `wp tldr-block run`.
- Daily cap, optional daily cost cap, per-post daily limit, kill switch, one-hour hold on credit exhaustion, retries at 5 minutes, 30 minutes and 2 hours.
- Output validation, figure check and prompt-injection delimiting.
- Automatic insertion, `[tldr]` shortcode, `tldr-block/summary` block, template functions and a REST field.
- Global, per-category and per-post switches; per-category minimum words.
- Block editor panel and classic meta box, review screen, Posts column and filter, settings screen, Site Health test, WP-CLI commands, uninstall routine.
- Soft integration with `spend-meter` and `prompt-library`.

[Unreleased]: https://github.com/IndianOpinion/tldr-block/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/tldr-block/releases/tag/v0.1.0
