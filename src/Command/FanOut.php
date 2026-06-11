<?php

declare(strict_types=1);

namespace Mds\Workspace\Command;

use Closure;
use Mds\Workspace\WorkspaceMember;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fans a Composer command out to every workspace member sequentially and
 * renders a per-member summary. The actual execution is delegated to the
 * runner closure so it can be swapped out in tests.
 */
final readonly class FanOut
{
    /**
     * @param  Closure(list<string>, WorkspaceMember): int  $runner  Executes a Composer command for one member, returns the exit code
     */
    public function __construct(
        private Closure $runner,
        private bool $continueOnError,
    ) {}

    /**
     * @param  list<string>  $command  Composer arguments, without the binary
     * @param  list<WorkspaceMember>  $members
     */
    public function execute(
        array $command,
        array $members,
        string $label,
        OutputInterface $output,
    ): int {
        /** @var array<string, array{ok: bool, seconds: float}> $results */
        $results = [];
        $failed = false;

        foreach ($members as $member) {
            $output->writeln(
                sprintf(
                    "\n<info>▸ %s</info> <comment>(%s)</comment> - composer %s",
                    $member->name,
                    $member->relativePath,
                    $label,
                ),
            );

            $start = hrtime(true);
            $exitCode = ($this->runner)($command, $member);
            $seconds = (hrtime(true) - $start) / 1e9;

            $results[sprintf('%s <comment>(%s)</comment>', $member->name, $member->relativePath)] = [
                'ok' => $exitCode === 0,
                'seconds' => $seconds,
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

                    break;
                }
            }
        }

        $this->summary($label, $results, $output);

        return $failed ? 1 : 0;
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
