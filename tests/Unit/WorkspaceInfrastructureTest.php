<?php

declare(strict_types=1);

use Mds\Workspace\Command\WorkspaceCommand;
use Mds\Workspace\Command\WorkspaceCommandProvider;
use Mds\Workspace\VcsMirrors;
use Mds\Workspace\WorkspaceMember;
use Mds\Workspace\WorkspaceMemberLocator;
use Mds\Workspace\WorkspaceRoot;

function workspace_test_directory(): string
{
    $directory = sys_get_temp_dir()
        .'/composer-workspace-plugin-'
        .bin2hex(random_bytes(6));

    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException(
            sprintf('Failed to create temporary directory "%s".', $directory),
        );
    }

    register_shutdown_function(
        static fn (): bool => workspace_test_remove_directory($directory),
    );

    return $directory;
}

function workspace_test_remove_directory(string $directory): bool
{
    if (! is_dir($directory)) {
        return true;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.') {
            continue;
        }
        if ($entry === '..') {
            continue;
        }
        $path = $directory.'/'.$entry;

        if (is_dir($path) && ! is_link($path)) {
            workspace_test_remove_directory($path);

            continue;
        }

        @unlink($path);
    }

    return @rmdir($directory);
}

function workspace_test_write_json(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        throw new RuntimeException(sprintf('Failed to encode JSON for "%s".', $path));
    }

    $written = file_put_contents($path, $json.PHP_EOL);

    if ($written === false) {
        throw new RuntimeException(sprintf('Failed to write "%s".', $path));
    }
}

it('discovers the workspace root from a nested directory', function (): void {
    $previous = getenv('COMPOSER_WORKSPACE_ROOT');
    $root = workspace_test_directory();
    $nested = $root.'/packages/library';

    mkdir($nested, 0777, true);
    workspace_test_write_json($root.'/composer.json', [
        'extra' => [
            'workspace' => ['members' => ['./packages']],
        ],
    ]);

    putenv('COMPOSER_WORKSPACE_ROOT');

    try {
        $discovered = WorkspaceRoot::discover($nested);
    } finally {
        if ($previous !== false) {
            putenv('COMPOSER_WORKSPACE_ROOT='.$previous);
        }
    }

    expect($discovered)->not->toBeNull();
    expect($discovered?->dir)->toBe(realpath($root) ?: $root);
    expect($discovered?->globs)->toBe(['./packages']);
});

it('walks past a manifest that still uses a removed key', function (): void {
    $previous = getenv('COMPOSER_WORKSPACE_ROOT');
    $root = workspace_test_directory();

    mkdir($root.'/packages/library', 0777, true);
    workspace_test_write_json($root.'/composer.json', [
        'extra' => ['packages' => ['./packages']],
    ]);

    putenv('COMPOSER_WORKSPACE_ROOT');

    try {
        $discovered = WorkspaceRoot::discover($root.'/packages/library');
    } finally {
        if ($previous !== false) {
            putenv('COMPOSER_WORKSPACE_ROOT='.$previous);
        }
    }

    expect($discovered)->toBeNull();
});

it('loads workspace members from package manifests', function (): void {
    $root = workspace_test_directory();
    $packages = $root.'/packages';

    mkdir($packages.'/app', 0777, true);
    mkdir($packages.'/library', 0777, true);

    workspace_test_write_json($root.'/composer.json', [
        'extra' => [
            'packages' => ['./packages'],
        ],
    ]);

    workspace_test_write_json($packages.'/app/composer.json', [
        'name' => 'acme/app',
        'type' => 'project',
        'scripts' => [
            'test' => 'pest',
        ],
    ]);

    workspace_test_write_json($packages.'/library/composer.json', [
        'name' => 'acme/library',
        'scripts' => [
            'lint' => 'pint',
            'test' => 'pest',
        ],
    ]);

    $members = WorkspaceMemberLocator::Locate($root, ['./packages']);

    expect($members)->toHaveCount(2);
    expect($members[0])->toBeInstanceOf(WorkspaceMember::class);
    expect($members[0]->name)->toBe('acme/app');
    expect($members[0]->relativePath)->toBe('packages/app');
    expect($members[0]->isProject())->toBeTrue();
    expect($members[1]->name)->toBe('acme/library');
    expect($members[1]->hasScript('lint'))->toBeTrue();
    expect($members[1]->matches('library'))->toBeTrue();
});

it('returns the workspace command from the provider', function (): void {
    $commands = (new WorkspaceCommandProvider)->getCommands();

    expect($commands)->toHaveCount(1);
    expect($commands[0])->toBeInstanceOf(WorkspaceCommand::class);
});

it('resolves internal dependencies from require and require-dev', function (): void {
    $root = workspace_test_directory();
    $packages = $root.'/packages';

    mkdir($packages.'/app', 0777, true);
    mkdir($packages.'/library', 0777, true);
    mkdir($packages.'/testing', 0777, true);

    workspace_test_write_json($root.'/composer.json', [
        'extra' => ['packages' => ['./packages']],
    ]);

    workspace_test_write_json($packages.'/app/composer.json', [
        'name' => 'acme/app',
        'type' => 'project',
        'require' => [
            'acme/library' => 'dev-workspace',
            'monolog/monolog' => '^3.0',
        ],
        'require-dev' => [
            'acme/testing' => 'dev-workspace',
        ],
    ]);

    workspace_test_write_json($packages.'/library/composer.json', [
        'name' => 'acme/library',
    ]);

    workspace_test_write_json($packages.'/testing/composer.json', [
        'name' => 'acme/testing',
    ]);

    $members = WorkspaceMemberLocator::Locate($root, ['./packages']);

    $byName = [];
    foreach ($members as $member) {
        $byName[$member->name] = $member;
    }

    expect($byName['acme/app']->dependencies)->toBe(['acme/library', 'acme/testing']);
    expect($byName['acme/library']->dependencies)->toBe([]);
    expect($byName['acme/testing']->dependencies)->toBe([]);
});

it('dedupes internal dependencies and keeps require before require-dev', function (): void {
    $root = workspace_test_directory();
    $packages = $root.'/packages';

    mkdir($packages.'/app', 0777, true);
    mkdir($packages.'/library', 0777, true);
    mkdir($packages.'/testing', 0777, true);

    workspace_test_write_json($root.'/composer.json', [
        'extra' => ['packages' => ['./packages']],
    ]);

    // acme/library is in both sections (dedupe); require-dev-only comes after.
    workspace_test_write_json($packages.'/app/composer.json', [
        'name' => 'acme/app',
        'type' => 'project',
        'require' => [
            'acme/library' => 'dev-workspace',
        ],
        'require-dev' => [
            'acme/library' => 'dev-workspace',
            'acme/testing' => 'dev-workspace',
        ],
    ]);

    workspace_test_write_json($packages.'/library/composer.json', [
        'name' => 'acme/library',
    ]);

    workspace_test_write_json($packages.'/testing/composer.json', [
        'name' => 'acme/testing',
    ]);

    $members = WorkspaceMemberLocator::Locate($root, ['./packages']);

    $byName = [];
    foreach ($members as $member) {
        $byName[$member->name] = $member;
    }

    expect($byName['acme/app']->dependencies)->toBe(['acme/library', 'acme/testing']);
});

it('exposes its internal dependencies and defaults them to empty', function (): void {
    $withDeps = new WorkspaceMember(
        name: 'acme/app',
        path: '/tmp/app',
        relativePath: 'packages/app',
        type: 'project',
        scripts: [],
        dependencies: ['acme/library'],
    );

    $withoutDeps = new WorkspaceMember(
        name: 'acme/library',
        path: '/tmp/library',
        relativePath: 'packages/library',
        type: 'library',
        scripts: [],
    );

    expect($withDeps->dependencies)->toBe(['acme/library']);
    expect($withoutDeps->dependencies)->toBe([]);
});

it('reads the member globs from extra.workspace.members', function (): void {
    expect(WorkspaceRoot::readGlobs(['workspace' => ['members' => ['./packages', 'apps/*', 5]]]))
        ->toBe(['./packages', 'apps/*']);
});

it('ignores the removed workspaces and packages keys', function (): void {
    expect(WorkspaceRoot::readGlobs(['workspaces' => ['./packages']]))->toBeNull();
    expect(WorkspaceRoot::readGlobs(['packages' => ['./packages']]))->toBeNull();
});

it('finds no globs when the workspace object is absent or memberless', function (): void {
    expect(WorkspaceRoot::readGlobs(['foo' => 1]))->toBeNull();
    expect(WorkspaceRoot::readGlobs(['workspace' => []]))->toBeNull();
    expect(WorkspaceRoot::readGlobs(['workspace' => 'nonsense']))->toBeNull();
    expect(WorkspaceRoot::readGlobs(['workspace' => ['members' => 'nope']]))->toBeNull();
});

it('reports a manifest still using a removed key', function (): void {
    expect(WorkspaceRoot::isOutdated(['workspaces' => ['./packages']]))->toBeTrue();
    expect(WorkspaceRoot::isOutdated(['packages' => ['./packages']]))->toBeTrue();
});

it('reports nothing outdated once the globs moved under workspace', function (): void {
    expect(WorkspaceRoot::isOutdated([]))->toBeFalse();
    expect(WorkspaceRoot::isOutdated(['workspace' => ['members' => ['./p']]]))->toBeFalse();
    expect(WorkspaceRoot::isOutdated([
        'workspace' => ['members' => ['./p']],
        'workspaces' => ['./old'],
    ]))->toBeFalse();
});

it('collects the vcs mirrors a member declares', function (): void {
    $root = workspace_test_directory();
    $packages = $root.'/packages';
    mkdir($packages.'/app', 0777, true);
    mkdir($packages.'/library', 0777, true);

    workspace_test_write_json($root.'/composer.json', [
        'extra' => ['workspace' => ['members' => ['./packages']]],
    ]);

    workspace_test_write_json($packages.'/app/composer.json', [
        'name' => 'acme/app',
        'repositories' => [
            ['type' => 'vcs', 'url' => 'git@github.com:acme/private-lib.git'],
            ['type' => 'path', 'url' => '../library'],
        ],
    ]);

    workspace_test_write_json($packages.'/library/composer.json', [
        'name' => 'acme/library',
    ]);

    $members = WorkspaceMemberLocator::Locate($root, ['./packages']);
    $mirrors = array_map(static fn (WorkspaceMember $m): array => $m->vcsMirrors, $members);

    expect($mirrors)->toBe([['github.com/acme/private-lib'], []]);
});

it('marks a source-preferring member as touching every mirror', function (): void {
    $root = workspace_test_directory();
    $packages = $root.'/packages';
    mkdir($packages.'/app', 0777, true);

    workspace_test_write_json($root.'/composer.json', [
        'extra' => ['workspace' => ['members' => ['./packages']]],
    ]);

    workspace_test_write_json($packages.'/app/composer.json', [
        'name' => 'acme/app',
        'config' => ['preferred-install' => 'source'],
    ]);

    $members = WorkspaceMemberLocator::Locate($root, ['./packages']);

    expect($members[0]->vcsMirrors)->toBe([VcsMirrors::ANY]);
});
