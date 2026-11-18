# Contributing

Thanks for helping. Small, focused pull requests are easiest to review.

## Setup

```sh
git clone https://github.com/IndianOpinion/tldr-block
cd tldr-block
composer install
```

PHP 8.1 or later. No WordPress install is needed for the tests: they run against a small stub layer in `tests/stubs/`.

## Checks

```sh
vendor/bin/phpunit        # tests
vendor/bin/phpstan analyse # static analysis, level 8
vendor/bin/phpcs          # PSR-12 style
composer check            # all three
```

All three must pass before a pull request is merged. CI runs them on PHP 8.1, 8.2 and 8.3. Write code that runs on 8.1: no readonly classes, no DNF types, no `true` type, no constants in traits.

## Style

- `declare(strict_types=1);` in every PHP file, namespace `IndianOpinion\TldrBlock`.
- Escape every output, check capabilities and nonces on everything admin-facing.
- No `eval`, no shell, no `@` error suppression, no silent failure: log through `Logger`.
- Prose: short sentences, plain words, British spelling. No em dashes.

## Pull requests

- One change per pull request, with a test that fails without it.
- Update `CHANGELOG.md` under Unreleased, and the README when behaviour or options change.
- Any change that could show unreviewed text to readers will not be merged.
- A DCO-style sign-off is not required.

## Reporting security issues

Do not open a public issue. See [SECURITY.md](SECURITY.md).
