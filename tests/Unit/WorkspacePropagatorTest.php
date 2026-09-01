<?php

declare(strict_types=1);

use Mds\Workspace\Command\Contracts\MemberRun;
use Mds\Workspace\WorkspaceMember;
use Mds\Workspace\WorkspacePropagator;
use Symfony\Component\Console\Output\BufferedOutput;

it('fans the action out to members in topological order with forwarded flags', function (): void {
    $root = workspace_test_directory();
    $packages = $root.'/packages';

    mkdir($packages.'/app', 0777, true);
    mkdir($packages.'/library', 0777, true);

    workspace_test_write_json($packages.'/app/composer.json', [
        'name' => 'acme/app',
        'require' => ['acme/library' => 'dev-workspace'],
    ]);
    workspace_test_write_json($packages.'/library/composer.json', [
        'name' => 'acme/library',
    ]);

    $ran = [];
    $runner = static function (array $command, WorkspaceMember $member) use (&$ran): MemberRun {
        $ran[] = [$member->name, $command];

        return workspace_test_run();
    };

    $exit = new WorkspacePropagator($runner)->propagate(
        $root,
        ['./packages'],
        'install',
        ['--no-dev'],
        new BufferedOutput,
    );

    expect($exit)->toBe(0);
    expect($ran)->toBe([
        ['acme/library', ['install', '--no-dev', '--no-interaction']],
        ['acme/app', ['install', '--no-dev', '--no-interaction']],
    ]);
});
