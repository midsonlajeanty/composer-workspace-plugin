<?php

declare(strict_types=1);

use Mds\Workspace\WorkspacePlugin;

it('maps real commands to member actions', function (): void {
    expect(WorkspacePlugin::actionForCommand('install'))->toBe('install');
    expect(WorkspacePlugin::actionForCommand('i'))->toBe('install');
    expect(WorkspacePlugin::actionForCommand('update'))->toBe('update');
    expect(WorkspacePlugin::actionForCommand('upgrade'))->toBe('update');
    expect(WorkspacePlugin::actionForCommand('u'))->toBe('update');
    expect(WorkspacePlugin::actionForCommand('require'))->toBeNull();
    expect(WorkspacePlugin::actionForCommand('remove'))->toBeNull();
    expect(WorkspacePlugin::actionForCommand(null))->toBeNull();
});

it('resolves propagation from the real command, not the script event', function (): void {
    // genuine install → propagate install
    expect(WorkspacePlugin::resolvePropagation('install', ['install', 'update'], false, false))->toBe('install');

    // genuine update → propagate update
    expect(WorkspacePlugin::resolvePropagation('update', ['install', 'update'], false, false))->toBe('update');

    // require/remove reuse the update event but must never propagate
    expect(WorkspacePlugin::resolvePropagation('require', ['install', 'update'], false, false))->toBeNull();
    expect(WorkspacePlugin::resolvePropagation('remove', ['install', 'update'], false, false))->toBeNull();

    // inside a child subprocess → never (recursion guard)
    expect(WorkspacePlugin::resolvePropagation('install', ['install', 'update'], true, false))->toBeNull();

    // already propagated this process → never again (once guard)
    expect(WorkspacePlugin::resolvePropagation('install', ['install', 'update'], false, true))->toBeNull();

    // action not enabled by config → skip
    expect(WorkspacePlugin::resolvePropagation('update', ['install'], false, false))->toBeNull();

    // no command captured → skip
    expect(WorkspacePlugin::resolvePropagation(null, ['install', 'update'], false, false))->toBeNull();
});

it('extracts forwarded flags from argv for the given action', function (): void {
    expect(WorkspacePlugin::forwardedFor('install', ['composer', 'install', '--no-dev']))
        ->toBe(['--no-dev']);

    // command not present in argv (e.g. an alias resolved elsewhere) → empty
    expect(WorkspacePlugin::forwardedFor('update', ['composer', 'require', 'foo/bar']))
        ->toBe([]);

    expect(WorkspacePlugin::forwardedFor('install', null))->toBe([]);
});

it('forwards a positional package argument on the event path', function (): void {
    expect(WorkspacePlugin::forwardedFor('update', ['composer', 'update', 'vendor/pkg', '--no-dev']))
        ->toBe(['vendor/pkg', '--no-dev']);
});

it('exposes a migration notice naming the removed keys and the new one', function (): void {
    expect(WorkspacePlugin::OUTDATED_NOTICE)
        ->toContain('extra.workspaces')
        ->toContain('extra.packages')
        ->toContain('extra.workspace.members');
});
