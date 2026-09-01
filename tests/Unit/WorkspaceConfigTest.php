<?php

declare(strict_types=1);

use Mds\Workspace\WorkspaceConfig;

it('returns no actions when propagate is absent', function (): void {
    expect(WorkspaceConfig::propagatedActions([]))->toBe([]);
    expect(WorkspaceConfig::propagatedActions(['workspace' => []]))->toBe([]);
});

it('returns no actions when propagate is false', function (): void {
    expect(WorkspaceConfig::propagatedActions(['workspace' => ['propagate' => false]]))->toBe([]);
});

it('returns the default action set when propagate is true', function (): void {
    expect(WorkspaceConfig::propagatedActions(['workspace' => ['propagate' => true]]))
        ->toBe(['install', 'update']);
});

it('returns the explicit list, dropping unknown actions', function (): void {
    expect(WorkspaceConfig::propagatedActions([
        'workspace' => ['propagate' => ['install', 'require', 'update', 7]],
    ]))->toBe(['install', 'update']);
});

it('runs no script in parallel when the parallel key is absent or false', function (): void {
    expect(WorkspaceConfig::isParallelScript([], 'lint'))->toBeFalse();
    expect(WorkspaceConfig::isParallelScript(['workspace' => []], 'lint'))->toBeFalse();
    expect(WorkspaceConfig::isParallelScript(['workspace' => ['parallel' => false]], 'lint'))->toBeFalse();
});

it('runs every script in parallel when parallel is true', function (): void {
    $extra = ['workspace' => ['parallel' => true]];

    expect(WorkspaceConfig::isParallelScript($extra, 'lint'))->toBeTrue();
    expect(WorkspaceConfig::isParallelScript($extra, 'anything'))->toBeTrue();
});

it('runs only the listed scripts in parallel', function (): void {
    $extra = ['workspace' => ['parallel' => ['lint', 'test:types']]];

    expect(WorkspaceConfig::isParallelScript($extra, 'lint'))->toBeTrue();
    expect(WorkspaceConfig::isParallelScript($extra, 'test:types'))->toBeTrue();
    expect(WorkspaceConfig::isParallelScript($extra, 'test'))->toBeFalse();
});

it('ignores a parallel value that is neither true nor a list', function (): void {
    expect(WorkspaceConfig::isParallelScript(['workspace' => ['parallel' => 'lint']], 'lint'))->toBeFalse();
    expect(WorkspaceConfig::isParallelScript(['workspace' => ['parallel' => 1]], 'lint'))->toBeFalse();
    expect(WorkspaceConfig::isParallelScript(['workspace' => ['parallel' => [7]]], '7'))->toBeFalse();
});
