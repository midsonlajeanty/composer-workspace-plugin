<?php

declare(strict_types=1);

use Mds\Workspace\Command\ArgumentForwarder;
use Mds\Workspace\Command\Concurrency;
use Mds\Workspace\Command\Contracts\MemberRun;
use Mds\Workspace\Command\FanOut;
use Mds\Workspace\Command\Handler\ListMembersHandler;
use Mds\Workspace\Command\Handler\ProxyHandler;
use Mds\Workspace\Command\Handler\RunScriptHandler;
use Mds\Workspace\Command\MemberProcessRunner;
use Mds\Workspace\WorkspaceMember;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

function workspace_test_member(
    string $name,
    array $scripts = [],
    array $dependencies = [],
    array $vcsMirrors = [],
): WorkspaceMember {
    return new WorkspaceMember(
        name: $name,
        path: '/tmp/'.$name,
        relativePath: 'packages/'.$name,
        type: 'library',
        scripts: $scripts,
        dependencies: $dependencies,
        vcsMirrors: $vcsMirrors,
    );
}

it('forwards unknown flags after the action without a -- separator', function (): void {
    expect(ArgumentForwarder::forwarded(['ws', 'update', '--with-all-dependencies', '-W'], 'ws'))
        ->toBe(['--with-all-dependencies', '-W']);
});

it('strips the workspace command own options from forwarded tokens', function (): void {
    expect(ArgumentForwarder::forwarded(
        ['ws', 'update', '--filter=api', '--continue-on-error', '-W'],
        'ws',
    ))->toBe(['-W']);

    expect(ArgumentForwarder::forwarded(['ws', '-f', 'api', 'update', '-W'], 'ws'))
        ->toBe(['-W']);

    expect(ArgumentForwarder::forwarded(['ws', 'update', '--filter', 'api'], 'ws'))
        ->toBe([]);
});

it('forwards everything after a -- separator verbatim', function (): void {
    expect(ArgumentForwarder::forwarded(['ws', 'update', '--', '--filter=x'], 'ws'))
        ->toBe(['--filter=x']);
});

it('keeps positional arguments such as script and package names', function (): void {
    expect(ArgumentForwarder::forwarded(['ws', 'run', 'test', '--filter=api'], 'ws'))
        ->toBe(['test']);

    expect(ArgumentForwarder::forwarded(['ws', 'require', 'foo/bar', '--dev'], 'ws'))
        ->toBe(['foo/bar', '--dev']);
});

it('returns null when the command name is not in the tokens', function (): void {
    expect(ArgumentForwarder::forwarded(['update'], 'ws'))->toBeNull();
});

it('keeps the positional after the command when action-skipping is disabled', function (): void {
    expect(ArgumentForwarder::forwarded(['update', 'vendor/pkg', '--no-dev'], 'update', false))
        ->toBe(['vendor/pkg', '--no-dev']);
});

it('fans a command out to every member and reports success', function (): void {
    $ran = [];
    $fanOut = new FanOut(static function (array $command, WorkspaceMember $member) use (&$ran): MemberRun {
        $ran[] = [$command, $member->name];

        return workspace_test_run();
    }, false);

    $output = new BufferedOutput;
    $exit = $fanOut->execute(['update'], [[
        workspace_test_member('acme/a'),
        workspace_test_member('acme/b'),
    ]], 'update', $output);

    expect($exit)->toBe(0);
    expect($ran)->toBe([[['update'], 'acme/a'], [['update'], 'acme/b']]);
    expect($output->fetch())->toContain('Summary - composer update');
});

it('stops at the first failure unless continue-on-error is set', function (): void {
    $ran = [];
    $runner = static function (array $command, WorkspaceMember $member) use (&$ran): MemberRun {
        $ran[] = $member->name;

        return workspace_test_run(1);
    };

    $members = [workspace_test_member('acme/a'), workspace_test_member('acme/b')];

    expect(new FanOut($runner, false)->execute(['update'], [$members], 'update', new BufferedOutput))->toBe(1);
    expect($ran)->toBe(['acme/a']);

    $ran = [];
    expect(new FanOut($runner, true)->execute(['update'], [$members], 'update', new BufferedOutput))->toBe(1);
    expect($ran)->toBe(['acme/a', 'acme/b']);
});

it('requires a script name for run', function (): void {
    $fanOut = new FanOut(static fn (): MemberRun => workspace_test_run(), false);
    $output = new BufferedOutput;

    expect(new RunScriptHandler([workspace_test_member('acme/a')], [], $fanOut)->handle($output))->toBe(1);
    expect($output->fetch())->toContain('Missing script name');
});

it('only runs the script in members that declare it', function (): void {
    $ran = [];
    $fanOut = new FanOut(static function (array $command, WorkspaceMember $member) use (&$ran): MemberRun {
        $ran[] = [$command, $member->name];

        return workspace_test_run();
    }, false);

    $members = [
        workspace_test_member('acme/a', ['test']),
        workspace_test_member('acme/b'),
    ];

    expect(new RunScriptHandler($members, ['test'], $fanOut)->handle(new BufferedOutput))->toBe(0);
    expect($ran)->toBe([[['run-script', 'test'], 'acme/a']]);
});

it('reports when no member declares the script', function (): void {
    $output = new BufferedOutput;
    $handler = new RunScriptHandler([workspace_test_member('acme/a')], ['lint'], new FanOut(static fn (): MemberRun => workspace_test_run(), false));

    expect($handler->handle($output))->toBe(0);
    expect($output->fetch())->toContain('No workspace member declares a "lint" script');
});

it('proxies any action verbatim with --no-interaction appended', function (): void {
    $ran = [];
    $fanOut = new FanOut(static function (array $command, WorkspaceMember $member) use (&$ran): MemberRun {
        $ran[] = $command;

        return workspace_test_run();
    }, false);

    $handler = new ProxyHandler('update', [workspace_test_member('acme/a')], ['--with-all-dependencies'], $fanOut);

    expect($handler->handle(new BufferedOutput))->toBe(0);
    expect($ran)->toBe([['update', '--with-all-dependencies', '--no-interaction']]);
});

it('reports when there are no members to proxy to', function (): void {
    $output = new BufferedOutput;
    $handler = new ProxyHandler('update', [], [], new FanOut(static fn (): MemberRun => workspace_test_run(), false));

    expect($handler->handle($output))->toBe(0);
    expect($output->fetch())->toContain('extra.workspace.members');
});

it('proxies members in topological order, dependencies first', function (): void {
    $ran = [];
    $fanOut = new FanOut(static function (array $command, WorkspaceMember $member) use (&$ran): MemberRun {
        $ran[] = $member->name;

        return workspace_test_run();
    }, false);

    // app depends on library; passed app-first (alphabetical) → expect library first
    $members = [
        workspace_test_member('acme/app', [], ['acme/library']),
        workspace_test_member('acme/library'),
    ];

    expect(new ProxyHandler('update', $members, [], $fanOut)->handle(new BufferedOutput))->toBe(0);
    expect($ran)->toBe(['acme/library', 'acme/app']);
});

it('fails without running any member when the dependency graph has a cycle', function (): void {
    $ran = [];
    $fanOut = new FanOut(static function (array $command, WorkspaceMember $member) use (&$ran): MemberRun {
        $ran[] = $member->name;

        return workspace_test_run();
    }, false);

    $members = [
        workspace_test_member('acme/a', [], ['acme/b']),
        workspace_test_member('acme/b', [], ['acme/a']),
    ];

    $output = new BufferedOutput;

    expect(new ProxyHandler('update', $members, [], $fanOut)->handle($output))->toBe(1);
    expect($ran)->toBe([]);
    expect($output->fetch())->toContain('Cyclic workspace dependency detected');
});

it('lists members with their scripts', function (): void {
    $output = new BufferedOutput;
    $handler = new ListMembersHandler([
        workspace_test_member('acme/a', ['test', 'lint']),
        workspace_test_member('acme/b'),
    ]);

    expect($handler->handle($output))->toBe(0);

    $text = $output->fetch();
    expect($text)->toContain('2 workspace members:');
    expect($text)->toContain('acme/a');
    expect($text)->toContain('test, lint');
    expect($text)->toContain('none');
});

it('builds a member subprocess carrying the recursion sentinel', function (): void {
    $process = MemberProcessRunner::process(
        ['install', '--no-interaction'],
        workspace_test_member('acme/a'),
        false,
    );

    expect($process)->toBeInstanceOf(Process::class);
    expect($process->getEnv())->toBe([MemberProcessRunner::CHILD_ENV => '1']);
    expect($process->getCommandLine())->toContain('install');
    expect($process->getWorkingDirectory())->toBe('/tmp/acme/a');
});

it('appends --ansi when the output is decorated', function (): void {
    $process = MemberProcessRunner::process(['update'], workspace_test_member('acme/a'), true);

    expect($process->getCommandLine())->toContain('--ansi');
});

it('does not forward the concurrency option to members', function (): void {
    // --concurrency drives the fan-out itself; Composer would reject it
    expect(ArgumentForwarder::forwarded(['ws', 'update', '--concurrency=4', '--no-dev'], 'ws'))
        ->toBe(['--no-dev']);

    expect(ArgumentForwarder::forwarded(['ws', 'update', '--concurrency', '4', '--no-dev'], 'ws'))
        ->toBe(['--no-dev']);
});

it('detects a concurrency that stays within sane bounds', function (): void {
    expect(Concurrency::detect())
        ->toBeGreaterThanOrEqual(1)
        ->toBeLessThanOrEqual(8);
});

it('honours an explicit concurrency, cap included', function (): void {
    expect(Concurrency::resolve('3'))->toBe(3);
    // an explicit value beats the auto-detection cap - the user asked for it
    expect(Concurrency::resolve('16'))->toBe(16);
});

it('falls back to detection when the concurrency option is absent or junk', function (): void {
    expect(Concurrency::resolve(null))->toBe(Concurrency::detect());
    expect(Concurrency::resolve('abc'))->toBe(Concurrency::detect());
});

it('never resolves a concurrency below one', function (): void {
    expect(Concurrency::resolve('0'))->toBe(1);
    expect(Concurrency::resolve('-4'))->toBe(1);
});

it('keeps scripts sequential unless a concurrency is explicitly asked for', function (): void {
    // parallel test suites would fight over a shared database
    expect(Concurrency::forAction('run', null))->toBe(1);
    expect(Concurrency::forAction('run', '4'))->toBe(4);

    // Composer commands only write to their own vendor/, so they fan out
    expect(Concurrency::forAction('update', null))->toBe(Concurrency::detect());
    expect(Concurrency::forAction('install', '2'))->toBe(2);
});

it('parallelises a script the workspace config marks as parallel', function (): void {
    expect(Concurrency::forAction('run', null, true))->toBe(Concurrency::detect());
    expect(Concurrency::forAction('run', null, false))->toBe(1);
});

it('lets an explicit concurrency override the parallel config in both directions', function (): void {
    expect(Concurrency::forAction('run', '1', true))->toBe(1);
    expect(Concurrency::forAction('run', '4', false))->toBe(4);
});
