<?php

declare(strict_types=1);

use Mds\Workspace\Command\ArgumentForwarder;
use Mds\Workspace\Command\FanOut;
use Mds\Workspace\Command\Handler\ListMembersHandler;
use Mds\Workspace\Command\Handler\ProxyHandler;
use Mds\Workspace\Command\Handler\RunScriptHandler;
use Mds\Workspace\WorkspaceMember;
use Symfony\Component\Console\Output\BufferedOutput;

function workspace_test_member(string $name, array $scripts = []): WorkspaceMember
{
    return new WorkspaceMember(
        name: $name,
        path: '/tmp/'.$name,
        relativePath: 'packages/'.$name,
        type: 'library',
        scripts: $scripts,
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

it('fans a command out to every member and reports success', function (): void {
    $ran = [];
    $fanOut = new FanOut(static function (array $command, WorkspaceMember $member) use (&$ran): int {
        $ran[] = [$command, $member->name];

        return 0;
    }, false);

    $output = new BufferedOutput;
    $exit = $fanOut->execute(['update'], [
        workspace_test_member('acme/a'),
        workspace_test_member('acme/b'),
    ], 'update', $output);

    expect($exit)->toBe(0);
    expect($ran)->toBe([[['update'], 'acme/a'], [['update'], 'acme/b']]);
    expect($output->fetch())->toContain('Summary — composer update');
});

it('stops at the first failure unless continue-on-error is set', function (): void {
    $ran = [];
    $runner = static function (array $command, WorkspaceMember $member) use (&$ran): int {
        $ran[] = $member->name;

        return 1;
    };

    $members = [workspace_test_member('acme/a'), workspace_test_member('acme/b')];

    expect((new FanOut($runner, false))->execute(['update'], $members, 'update', new BufferedOutput))->toBe(1);
    expect($ran)->toBe(['acme/a']);

    $ran = [];
    expect((new FanOut($runner, true))->execute(['update'], $members, 'update', new BufferedOutput))->toBe(1);
    expect($ran)->toBe(['acme/a', 'acme/b']);
});

it('requires a script name for run', function (): void {
    $fanOut = new FanOut(static fn (): int => 0, false);
    $output = new BufferedOutput;

    expect((new RunScriptHandler([workspace_test_member('acme/a')], [], $fanOut))->handle($output))->toBe(1);
    expect($output->fetch())->toContain('Missing script name');
});

it('only runs the script in members that declare it', function (): void {
    $ran = [];
    $fanOut = new FanOut(static function (array $command, WorkspaceMember $member) use (&$ran): int {
        $ran[] = [$command, $member->name];

        return 0;
    }, false);

    $members = [
        workspace_test_member('acme/a', ['test']),
        workspace_test_member('acme/b'),
    ];

    expect((new RunScriptHandler($members, ['test'], $fanOut))->handle(new BufferedOutput))->toBe(0);
    expect($ran)->toBe([[['run-script', 'test'], 'acme/a']]);
});

it('reports when no member declares the script', function (): void {
    $output = new BufferedOutput;
    $handler = new RunScriptHandler([workspace_test_member('acme/a')], ['lint'], new FanOut(static fn (): int => 0, false));

    expect($handler->handle($output))->toBe(0);
    expect($output->fetch())->toContain('No workspace member declares a "lint" script');
});

it('proxies any action verbatim with --no-interaction appended', function (): void {
    $ran = [];
    $fanOut = new FanOut(static function (array $command, WorkspaceMember $member) use (&$ran): int {
        $ran[] = $command;

        return 0;
    }, false);

    $handler = new ProxyHandler('update', [workspace_test_member('acme/a')], ['--with-all-dependencies'], $fanOut);

    expect($handler->handle(new BufferedOutput))->toBe(0);
    expect($ran)->toBe([['update', '--with-all-dependencies', '--no-interaction']]);
});

it('reports when there are no members to proxy to', function (): void {
    $output = new BufferedOutput;
    $handler = new ProxyHandler('update', [], [], new FanOut(static fn (): int => 0, false));

    expect($handler->handle($output))->toBe(0);
    expect($output->fetch())->toContain('No workspace members found');
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
