<?php

declare(strict_types=1);

use Mds\Workspace\Command\WorkspaceCommand;
use Mds\Workspace\Command\WorkspaceCommandProvider;
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
            'packages' => ['./packages'],
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
    expect($discovered?->dir)->toBe($root);
    expect($discovered?->globs)->toBe(['./packages']);
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
