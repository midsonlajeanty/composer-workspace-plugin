<?php

declare(strict_types=1);

namespace Mds\Workspace\Command;

use Closure;
use Mds\Workspace\Command\Contracts\MemberRun;
use Mds\Workspace\VcsMirrors;
use Mds\Workspace\WorkspaceMember;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fans a command out one dependency layer at a time. A layer runs concurrently;
 * the next starts once it is drained, preserving the dependencies-first order.
 */
final readonly class FanOut
{
    private const array SPINNER = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    private const int POLL_MICROSECONDS = 50_000;

    /**
     * @param  Closure(list<string>, WorkspaceMember): MemberRun  $runner
     * @param  int  $concurrency  Members allowed to run at once; 1 is sequential
     * @param  bool  $sharedMirror  Treat every member as touching one mirror
     */
    public function __construct(
        private Closure $runner,
        private bool $continueOnError,
        private int $concurrency = 1,
        private bool $sharedMirror = false,
    ) {}

    /**
     * @param  list<string>  $command  Composer arguments, without the binary
     * @param  list<list<WorkspaceMember>>  $layers  Dependency layers, shallowest first
     */
    public function execute(
        array $command,
        array $layers,
        string $label,
        OutputInterface $output,
    ): int {
        /** @var array<string, array{ok: bool, seconds: float}> $results */
        $results = [];
        $failed = false;
        $progress = $output instanceof ConsoleOutputInterface ? $output->section() : null;

        foreach ($layers as $layer) {
            $this->runLayer($command, $layer, $label, $output, $progress, $results, $failed);

            if ($failed && ! $this->continueOnError) {
                break;
            }
        }

        $progress?->clear();
        $this->summary($label, $results, $output);

        return $failed ? 1 : 0;
    }

    /**
     * Index of the first queued member whose mirrors are all free, or null
     * when everything left is blocked by one in flight - see VcsMirrors.
     *
     * @param  list<WorkspaceMember>  $queue
     * @param  list<array{member: WorkspaceMember, run: MemberRun, start: float}>  $active
     */
    private function launchable(array $queue, array $active): ?int
    {
        if ($active === []) {
            return $queue === [] ? null : 0;
        }

        if ($this->sharedMirror) {
            return null;
        }

        $busy = [];

        foreach ($active as $entry) {
            foreach ($entry['member']->vcsMirrors as $mirror) {
                $busy[$mirror] = true;
            }
        }

        // Never starved: the deck drains, then the $active === [] branch fires.
        if (isset($busy[VcsMirrors::ANY])) {
            return null;
        }

        foreach ($queue as $index => $member) {
            if (in_array(VcsMirrors::ANY, $member->vcsMirrors, true)) {
                continue;
            }

            foreach ($member->vcsMirrors as $mirror) {
                if (isset($busy[$mirror])) {
                    continue 2;
                }
            }

            return $index;
        }

        return null;
    }

    /**
     * @param  list<string>  $command
     * @param  list<WorkspaceMember>  $layer
     * @param  array<string, array{ok: bool, seconds: float}>  $results
     */
    private function runLayer(
        array $command,
        array $layer,
        string $label,
        OutputInterface $output,
        ?ConsoleSectionOutput $progress,
        array &$results,
        bool &$failed,
    ): void {
        $queue = $layer;
        /** @var list<array{member: WorkspaceMember, run: MemberRun, start: float}> $active */
        $active = [];
        $tick = 0;

        while ($queue !== [] || $active !== []) {
            while (
                ! $this->halted($failed)
                && count($active) < max(1, $this->concurrency)
                && $queue !== []
            ) {
                $index = $this->launchable($queue, $active);

                if ($index === null) {
                    break;
                }

                $member = $queue[$index];
                unset($queue[$index]);
                $queue = array_values($queue);

                $active[] = [
                    'member' => $member,
                    'run' => ($this->runner)($command, $member),
                    'start' => (float) hrtime(true),
                ];
            }

            // Drain rather than kill: a half-installed vendor/ is worse than a slow exit.
            if ($this->halted($failed)) {
                $queue = [];
            }

            /** @var list<array{member: WorkspaceMember, run: MemberRun, start: float}> $running */
            $running = [];

            foreach ($active as $entry) {
                if (! $entry['run']->finished()) {
                    $running[] = $entry;

                    continue;
                }

                $progress?->clear();
                $this->report($entry, $label, $output, $results, $failed);
            }

            $active = $running;

            if ($active !== []) {
                $this->renderProgress($progress, $active, $tick++);
                usleep(self::POLL_MICROSECONDS);
            }
        }
    }

    private function halted(bool $failed): bool
    {
        return $failed && ! $this->continueOnError;
    }

    /**
     * @param  array{member: WorkspaceMember, run: MemberRun, start: float}  $entry
     * @param  array<string, array{ok: bool, seconds: float}>  $results
     */
    private function report(
        array $entry,
        string $label,
        OutputInterface $output,
        array &$results,
        bool &$failed,
    ): void {
        $member = $entry['member'];
        $exitCode = $entry['run']->exitCode();

        $output->writeln(
            sprintf(
                "\n<info>▸ %s</info> <comment>(%s)</comment> - composer %s",
                $member->name,
                $member->relativePath,
                $label,
            ),
        );

        $buffered = $entry['run']->output();

        if ($buffered !== '') {
            $output->write($buffered);
        }

        $results[sprintf('%s <comment>(%s)</comment>', $member->name, $member->relativePath)] = [
            'ok' => $exitCode === 0,
            'seconds' => ((float) hrtime(true) - $entry['start']) / 1e9,
        ];

        if ($exitCode !== 0) {
            $failed = true;

            if (! $this->continueOnError) {
                $output->writeln(
                    sprintf(
                        '<error>✗ %s failed (exit %d). Stopping. Use --continue-on-error to keep going.</error>',
                        $member->name,
                        $exitCode,
                    ),
                );
            }
        }
    }

    /**
     * @param  list<array{member: WorkspaceMember, run: MemberRun, start: float}>  $active
     */
    private function renderProgress(?ConsoleSectionOutput $progress, array $active, int $tick): void
    {
        if (! $progress instanceof ConsoleSectionOutput) {
            return;
        }

        $parts = array_map(
            static fn (array $entry): string => sprintf(
                '%s <comment>%ds</comment>',
                $entry['member']->name,
                (int) (((float) hrtime(true) - $entry['start']) / 1e9),
            ),
            $active,
        );

        $progress->overwrite(
            sprintf(
                '<info>%s</info> %d running - %s',
                self::SPINNER[$tick % count(self::SPINNER)],
                count($active),
                implode(' · ', $parts),
            ),
        );
    }

    /**
     * @param  array<string, array{ok: bool, seconds: float}>  $results
     */
    private function summary(
        string $label,
        array $results,
        OutputInterface $output,
    ): void {
        $output->writeln(
            sprintf("\n<info>Summary - composer %s</info>", $label),
        );

        foreach ($results as $name => $result) {
            $output->writeln(
                sprintf(
                    '  %s <info>%s</info> <comment>(%.1fs)</comment>',
                    $result['ok'] ? '<info>✓</info>' : '<error>✗</error>',
                    $name,
                    $result['seconds'],
                ),
            );
        }
    }
}
