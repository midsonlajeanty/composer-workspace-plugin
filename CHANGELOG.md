# Changelog

All notable changes to `midsonlajeanty/composer-workspace-plugin` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [v1.0.1] - 2026-06-11

- [f7cfbb1](http://github.com/midsonlajeanty/composer-workspace-plugin/commit/f7cfbb1a2721d48e1811c74cd78c10ba8f165b88) - fix: release.yml



## [1.0.0-dev] - 2026-06-11

### Added

- Workspace root discovery: walk up from the current directory to the first `composer.json` declaring member globs under `extra.packages`, overridable with `COMPOSER_WORKSPACE_ROOT`.
- Auto-registration of every workspace library as a symlinked path repository; no `repositories` blocks needed in members.
- `dev-workspace` version: a constraint that can only be satisfied locally, never by Packagist. Plain `@dev` constraints resolve against the workspace too.
- `composer ws list`: show members and their scripts.
- `composer ws run <script>`: run a Composer script in every member that declares it.
- Proxying of any other Composer command (`install`, `update`, `require`, `remove`, `dump-autoload`, `outdated`, `audit`, ...) to every member, each in an isolated subprocess.
- Verbatim flag forwarding: `composer ws update --with-all-dependencies` works without a `--` separator.
- `--filter` (repeatable glob on member name/path) and `--continue-on-error` options.
- Per-member timing and summary output using a monotonic clock.

[Unreleased]: https://github.com/midsonlajeanty/composer-workspace-plugin/compare/1.0.0-dev...HEAD
[1.0.0-dev]: https://github.com/midsonlajeanty/composer-workspace-plugin/releases/tag/1.0.0-dev
