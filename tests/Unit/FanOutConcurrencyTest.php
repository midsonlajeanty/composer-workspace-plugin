<?php

declare(strict_types=1);

use Mds\Workspace\Command\Contracts\MemberRun;
use Mds\Workspace\Command\FanOut;
use Mds\Workspace\VcsMirrors;
use Mds\Workspace\WorkspaceMember;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A run that reports itself finished only after $ticks polls, so a test can
 * observe several members being in flight at the same time.
 */
final class FanOutFakeRun implements MemberRun
{
    private int $ticks;

    public function __construct(
        int $ticks,
        private readonly int $exitCode,
        private readonly string $output,
        private readonly Closure $onFinish,
    ) {
        $this->ticks = $ticks;
    }

    public function finished(): bool
    {
        if ($this->ticks > 0) {
            $this->ticks--;

            return false;
        }

        ($this->onFinish)();

        return true;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function output(): string
    {
        return $this->output;
    }
}

/**
 * A run that is already over: the common case for tests that only care about
 * ordering or exit codes, not about concurrency.
 */
function workspace_test_run(int $exitCode = 0, string $output = ''): MemberRun
{
    return new FanOutFakeRun(0, $exitCode, $output, static fn () => null);
}

it('runs every member of a layer at the same time', function (): void {
    $inFlight = 0;
    $peak = 0;

    $runner = function (array $command, WorkspaceMember $member) use (&$inFlight, &$peak): MemberRun {
        $inFlight++;
        $peak = max($peak, $inFlight);

        return new FanOutFakeRun(2, 0, '', function () use (&$inFlight): void {
            $inFlight--;
        });
    };

    $layer = [
        workspace_test_member('acme/a'),
        workspace_test_member('acme/b'),
        workspace_test_member('acme/c'),
    ];

    $exit = (new FanOut($runner, false, 4))->execute(['update'], [$layer], 'update', new BufferedOutput);

    expect($exit)->toBe(0);
    expect($peak)->toBe(3);
});

it('never exceeds the concurrency limit', function (): void {
    $inFlight = 0;
    $peak = 0;

    $runner = function (array $command, WorkspaceMember $member) use (&$inFlight, &$peak): MemberRun {
        $inFlight++;
        $peak = max($peak, $inFlight);

        return new FanOutFakeRun(2, 0, '', function () use (&$inFlight): void {
            $inFlight--;
        });
    };

    $layer = array_map(workspace_test_member(...), ['acme/a', 'acme/b', 'acme/c', 'acme/d', 'acme/e']);

    (new FanOut($runner, false, 2))->execute(['update'], [$layer], 'update', new BufferedOutput);

    expect($peak)->toBe(2);
});

it('does not start a layer before the previous one is done', function (): void {
    $events = [];

    $runner = function (array $command, WorkspaceMember $member) use (&$events): MemberRun {
        $events[] = 'start:'.$member->name;

        return new FanOutFakeRun(1, 0, '', function () use (&$events, $member): void {
            $events[] = 'end:'.$member->name;
        });
    };

    $layers = [
        [workspace_test_member('acme/lib')],
        [workspace_test_member('acme/app')],
    ];

    (new FanOut($runner, false, 4))->execute(['install'], $layers, 'install', new BufferedOutput);

    expect($events)->toBe(['start:acme/lib', 'end:acme/lib', 'start:acme/app', 'end:acme/app']);
});

it('prints the buffered output of each member once it finishes', function (): void {
    $runner = static fn (array $command, WorkspaceMember $member): MemberRun => new FanOutFakeRun(
        0,
        0,
        'output of '.$member->name,
        static fn () => null,
    );

    $output = new BufferedOutput;
    (new FanOut($runner, false, 4))->execute(['update'], [[
        workspace_test_member('acme/a'),
        workspace_test_member('acme/b'),
    ]], 'update', $output);

    $rendered = $output->fetch();

    expect($rendered)->toContain('output of acme/a');
    expect($rendered)->toContain('output of acme/b');
    expect($rendered)->toContain('Summary - composer update');
});

it('skips the remaining layers after a failure unless continue-on-error is set', function (): void {
    $started = [];

    $runner = function (array $command, WorkspaceMember $member) use (&$started): MemberRun {
        $started[] = $member->name;

        return new FanOutFakeRun(0, $member->name === 'acme/lib' ? 1 : 0, '', static fn () => null);
    };

    $layers = [
        [workspace_test_member('acme/lib')],
        [workspace_test_member('acme/app')],
    ];

    expect((new FanOut($runner, false, 4))->execute(['install'], $layers, 'install', new BufferedOutput))->toBe(1);
    expect($started)->toBe(['acme/lib']);

    $started = [];
    expect((new FanOut($runner, true, 4))->execute(['install'], $layers, 'install', new BufferedOutput))->toBe(1);
    expect($started)->toBe(['acme/lib', 'acme/app']);
});

it('never runs two members that share a vcs mirror at the same time', function (): void {
    $onMirror = 0;
    $mirrorPeak = 0;
    $peak = 0;
    $inFlight = 0;

    $runner = function (array $command, WorkspaceMember $member) use (
        &$onMirror, &$mirrorPeak, &$inFlight, &$peak,
    ): MemberRun {
        $shares = $member->vcsMirrors !== [];
        $inFlight++;
        $peak = max($peak, $inFlight);

        if ($shares) {
            $onMirror++;
            $mirrorPeak = max($mirrorPeak, $onMirror);
        }

        return new FanOutFakeRun(2, 0, '', function () use (&$onMirror, &$inFlight, $shares): void {
            $inFlight--;

            if ($shares) {
                $onMirror--;
            }
        });
    };

    $layer = [
        workspace_test_member('acme/a', vcsMirrors: ['github.com/acme/lib']),
        workspace_test_member('acme/b', vcsMirrors: ['github.com/acme/lib']),
        workspace_test_member('acme/c'),
    ];

    expect((new FanOut($runner, false, 4))->execute(['update'], [$layer], 'update', new BufferedOutput))
        ->toBe(0);

    expect($mirrorPeak)->toBe(1);
    expect($peak)->toBe(2);
});

it('runs members with different vcs mirrors side by side', function (): void {
    $inFlight = 0;
    $peak = 0;

    $runner = function (array $command, WorkspaceMember $member) use (&$inFlight, &$peak): MemberRun {
        $inFlight++;
        $peak = max($peak, $inFlight);

        return new FanOutFakeRun(2, 0, '', function () use (&$inFlight): void {
            $inFlight--;
        });
    };

    $layer = [
        workspace_test_member('acme/a', vcsMirrors: ['github.com/acme/one']),
        workspace_test_member('acme/b', vcsMirrors: ['github.com/acme/two']),
    ];

    (new FanOut($runner, false, 4))->execute(['update'], [$layer], 'update', new BufferedOutput);

    expect($peak)->toBe(2);
});

it('serialises the whole layer when every member shares the mirror cache', function (): void {
    $inFlight = 0;
    $peak = 0;

    $runner = function (array $command, WorkspaceMember $member) use (&$inFlight, &$peak): MemberRun {
        $inFlight++;
        $peak = max($peak, $inFlight);

        return new FanOutFakeRun(2, 0, '', function () use (&$inFlight): void {
            $inFlight--;
        });
    };

    $layer = array_map(workspace_test_member(...), ['acme/a', 'acme/b', 'acme/c']);

    (new FanOut($runner, false, 4, sharedMirror: true))
        ->execute(['update'], [$layer], 'update', new BufferedOutput);

    expect($peak)->toBe(1);
});

it('never runs a member that touches every mirror alongside another', function (): void {
    $inFlight = 0;
    $peakBesideWildcard = 0;
    $wildcardRan = false;

    $runner = function (array $command, WorkspaceMember $member) use (
        &$inFlight, &$peakBesideWildcard, &$wildcardRan,
    ): MemberRun {
        $wildcard = in_array(VcsMirrors::ANY, $member->vcsMirrors, true);
        $inFlight++;

        if ($wildcard) {
            $wildcardRan = true;
            $peakBesideWildcard = max($peakBesideWildcard, $inFlight);
        }

        return new FanOutFakeRun(2, 0, '', function () use (&$inFlight): void {
            $inFlight--;
        });
    };

    $layer = [
        workspace_test_member('acme/a', vcsMirrors: [VcsMirrors::ANY]),
        workspace_test_member('acme/b', vcsMirrors: ['github.com/acme/lib']),
        workspace_test_member('acme/c'),
    ];

    expect((new FanOut($runner, false, 4))->execute(['update'], [$layer], 'update', new BufferedOutput))
        ->toBe(0);

    expect($wildcardRan)->toBeTrue();
    expect($peakBesideWildcard)->toBe(1);
});

it('still runs a member that touches every mirror when it is queued last', function (): void {
    $ran = [];

    $runner = function (array $command, WorkspaceMember $member) use (&$ran): MemberRun {
        $ran[] = $member->name;

        return new FanOutFakeRun(2, 0, '', static fn () => null);
    };

    $layer = [
        workspace_test_member('acme/a'),
        workspace_test_member('acme/b'),
        workspace_test_member('acme/z', vcsMirrors: [VcsMirrors::ANY]),
    ];

    (new FanOut($runner, false, 4))->execute(['update'], [$layer], 'update', new BufferedOutput);

    expect($ran)->toBe(['acme/a', 'acme/b', 'acme/z']);
});
