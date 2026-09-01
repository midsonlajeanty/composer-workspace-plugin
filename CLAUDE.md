# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

`midsonlajeanty/composer-workspace-plugin` - a Composer plugin that brings
Bun/pnpm-style monorepo workspaces to Composer:

- **Auto-registered path repositories.** Workspace libraries resolve as symlinked
  path repos published as `dev-workspace`, with no `repositories` block. A member
  can require another with `"acme/lib": "dev-workspace"`.
- **Fan-out commands.** `composer ws run|install|update|require|…` run across every
  member in an isolated subprocess, in topological (dependency-first) order.
- **Opt-in auto-fanout.** With `extra.workspace.propagate`, root `composer
  install`/`update` propagate to every member automatically via Composer events.

## Commands

```bash
composer test          # full gate: pest + rector(dry) + pint(test) + phpstan
composer test:unit     # Pest tests only          (vendor/bin/pest)
composer test:types    # PHPStan (level max)       (phpstan analyse)
composer test:lint     # Pint check-only           (pint --test)
composer lint          # Pint auto-fix             (pint)
composer refactor      # Rector auto-fix           (rector)
```

Run a single test: `vendor/bin/pest --filter="<description>"`.

`pest-plugin-type-coverage` and `pest-plugin-rector` are installed but not wired
into any script yet - `pest --type-coverage` and `pest --rector` run them.

## Conventions

- **Pre-1.0.** The package is on `0.x`, so a minor release may break behaviour
  and no deprecation cycle is owed. Say so in the changelog; do not invent
  compatibility shims.
- PHP `^8.4`, `composer-plugin-api ^2.3`. No runtime deps beyond those.
- **`rector.php` must call `withPhpSets()` with no arguments** so Rector follows
  `require.php` from composer.json. Pinning a version there (`php85: true`)
  makes Rector rewrite code past the declared floor - that is how `array_any()`
  (PHP 8.4+) landed in a project declaring `^8.3` and would have broken the CI
  matrix, invisibly, because the dev machine ran a newer PHP.
- `declare(strict_types=1)`, `final` classes, typed class constants
  (`public const string X = ...`). Static factory-style method casing is used in
  places (`Locate`, `Sort`, `From`, `ForManifest`).
- PSR-4: `Mds\Workspace\` → `src/`. Classes in `Mds\Workspace` need no import from
  each other; classes in `Mds\Workspace\Command` must be imported when used from
  `Mds\Workspace`.
- Tests are Pest 5 in `tests/Unit/`. Shared test helpers
  (`workspace_test_directory`, `workspace_test_write_json`) live at the top of
  `tests/Unit/WorkspaceInfrastructureTest.php` and are globally available via
  Pest's collection phase. Always add a failing test first (TDD).
- **No comments.** The code explains itself. Docblocks are the only sanctioned
  form; a free-floating `//` is allowed only for something the code cannot show
  (an upstream bug, a race, an ordering constraint) and must fit on one line.
  `@param`/`@return` stay - PHPStan level max needs them.
- Before committing: run `composer test:unit`, `composer test:types`,
  `composer test:lint` - Pint flags unused/redundant (same-namespace) imports.

## Architecture (key files)

- `src/WorkspacePlugin.php` - plugin entry point. Implements `Capable`,
  `EventSubscriberInterface`, `PluginInterface`. On `activate()` it registers path
  repos for members; as an event subscriber it captures the real command
  (`PRE_COMMAND_RUN`) and fans install/update out to members
  (`POST_INSTALL_CMD`/`POST_UPDATE_CMD` → `propagateToMembers`).
- `src/WorkspaceRoot.php` - walks up to find the root; `readGlobs()` is the single
  source of truth for the workspace globs (reads `extra.workspace.members`;
  `isOutdated()` flags a manifest still on the removed `extra.workspaces` /
  `extra.packages` so the plugin can warn instead of silently doing nothing).
- `src/WorkspaceConfig.php` - parses the opt-in `extra.workspace.propagate` and
  `extra.workspace.parallel`.
- `src/VcsMirrors.php` - the shared VCS mirror keys a member touches.
- `src/WorkspaceMemberLocator.php` / `WorkspaceMember.php` - discover + model
  members. `WorkspaceMemberSorter.php` - topological sort (throws
  `CyclicDependencyException` on a cycle) and `Layers()`, which groups that
  order into concurrently-runnable layers.
- `src/WorkspacePropagator.php` - event-side fan-out; reuses the topological
  `ProxyHandler` + `FanOut` (no duplicated sort/exec logic).
- `src/Command/WorkspaceCommand.php` - the `composer ws` command (dispatcher).
- `src/Command/FanOut.php` - layer-by-layer execution + summary. Members inside
  a layer run concurrently (up to `Concurrency`), the next layer starts once the
  current one is drained. After a failure it stops launching but lets in-flight
  members finish (a killed `composer install` leaves a broken `vendor/`).
- `src/Command/Concurrency.php` - core detection (capped at 8), `--concurrency`
  parsing, and the `run`-stays-sequential rule.
- `src/Command/Contracts/MemberRun.php` / `ProcessRun.php` - a member already in
  flight; `finished()` is polled, output is buffered and printed as one block.
- `src/Command/MemberProcessRunner.php` - spawns the per-member Composer
  subprocess and stamps the recursion sentinel.
- `src/Command/ArgumentForwarder.php` - extracts flags/args to forward from argv.
- `src/Command/Contracts/CommandHandler.php` - handler interface;
  `src/Command/Handler/*` - the `list`/`run`/proxy handlers.

## Invariants - do not break

- **Global-only by design.** The plugin must be installed globally so it is active
  inside each member subprocess (that is what re-registers the path repos so
  cross-member `dev-workspace` deps resolve). Do NOT add filesystem-traversal
  resolution or per-member plugin requirements to work around this.
- **Recursion sentinel.** `MemberProcessRunner` sets `COMPOSER_WORKSPACE_CHILD=1`
  on every member subprocess; `WorkspacePlugin` skips propagation (and the
  outdated-key notice) when that env var is present. Both the `ws` command runner
  and the event propagator must use `MemberProcessRunner` so the sentinel is
  always set - otherwise auto-fanout recurses infinitely.
- **Opt-in is strict.** No propagation unless `extra.workspace.propagate` enables
  it. Propagation is keyed on the REAL command (`actionForCommand`), not the script
  event name - so a lockless `composer install` (which fires post-update-cmd) still
  fans out as an install, while `require`/`remove` never propagate.
- **One config object.** Everything the plugin reads lives under
  `extra.workspace`: `members` (globs), `propagate`, `parallel`. There is no
  top-level `extra.workspaces` and no legacy fallback - do not reintroduce one.
- **Workspace version.** Every member path repo is published as
  `WorkspacePlugin::WORKSPACE_VERSION` (`dev-workspace`).
- **Scripts stay sequential by default.** `composer ws run` uses concurrency 1
  unless `--concurrency` is passed or the script is named in
  `extra.workspace.parallel` (`WorkspaceConfig::isParallelScript`): scripts are
  user code and routinely share a database, a port or a cache. `--concurrency`
  outranks the config in both directions. Proxied Composer commands only write
  to their own member's `vendor/`, so they always fan out.
- **Shared VCS mirrors are serialised.** Composer takes no lock on
  `cache-vcs-dir`: `Git::syncMirror()` calls `removeDirectory()` on any mirror
  that does not look like a valid git dir, so a second process arriving
  mid-clone destroys the first one's work (and the `origin` set-url dance
  breaks auth on private repos). `VcsMirrors` derives a coarse key per declared
  `repositories` entry - including the `source` of an inline `package` entry,
  which `GitDownloader` clones through the same cache; `FanOut::launchable()`
  refuses to launch a member whose keys overlap one in flight. Keys are
  deliberately coarser than Composer's own cache paths - over-serialising costs
  parallelism, under-serialising corrupts a cache. A member with `config.preferred-install: source` may clone anything,
  so it gets `VcsMirrors::ANY` and runs alone (it is never starved: the deck
  drains and the `$active === []` branch launches it). `--prefer-source` sets
  `FanOut`'s `$sharedMirror`, which serialises everything.
- **`ws` options are not forwarded.** `--filter`, `--continue-on-error` and
  `--concurrency` belong to `ws`; `ArgumentForwarder` must strip every new one,
  or members get an option Composer rejects.

## Notes

- Planning/design docs under `docs/superpowers/` are git-ignored (process
  artifacts), including the open follow-ups: an integration test of the real
  subprocess path, a DX hint when the plugin is not installed globally, and CI
  exit-code handling.
- Git history was reset at `0.1.0`; the CHANGELOG starts there. Commit messages
  are one line and never mention an assistant.
