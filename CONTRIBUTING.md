# Contributing

Thank you for considering contributing to `composer-workspace-plugin`! Every kind of contribution is welcome: bug reports, documentation, tests, and features.

This project adheres to the [Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold it.

## Reporting Bugs

Open an [issue](https://github.com/midsonlajeanty/composer-workspace-plugin/issues) using the bug report template. A minimal monorepo layout (root `composer.json` + one or two members) reproducing the problem makes a fix much faster.

For security vulnerabilities, see [SECURITY.md](SECURITY.md); **never** open a public issue.

## Development Setup

Requires PHP 8.3+ and Composer 2.3+.

```bash
git clone https://github.com/midsonlajeanty/composer-workspace-plugin.git
cd composer-workspace-plugin
composer install
```

## Running the Test Suite

The `test` script runs everything CI runs:

```bash
composer test            # unit tests + refactor check + lint check + static analysis
```

Or individually:

```bash
composer test:unit       # Pest
composer test:types      # PHPStan
composer test:lint       # Pint (check only)
composer test:refactor   # Rector (dry run)
composer lint            # Pint (fix)
composer refactor        # Rector (apply)
```

## Commit Messages

We use [Conventional Commits](https://www.conventionalcommits.org/) for our commit messages. This helps us generate clean changelogs and automate releases.

Please use the following prefixes:

- `feat:` for new features.
- `fix:` for bug fixes.
- `docs:` for documentation changes.
- `style:` for changes that do not affect the meaning of the code (white-space, formatting, etc).
- `refactor:` for code changes that neither fix a bug nor add a feature.
- `test:` for adding missing tests or correcting existing tests.
- `chore:` for updating build tasks, package manager configs, etc.

## Pull Requests

- Branch off `main` (e.g., `feat/my-new-feature`) and keep one change per pull request.
- Add or update tests for any behavior change; `composer test` must pass.
- New subcommand behavior belongs in a dedicated handler class (`src/Command/Handler/`) with unit tests, not in the dispatcher.
- Match the existing code style; Pint and Rector enforce most of it.
- Describe **why** the change is needed, not just what it does.
