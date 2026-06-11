<?php

declare(strict_types=1);

namespace Mds\Workspace\Command\Handler;

use Mds\Workspace\Command\FanOut;
use Mds\Workspace\WorkspaceMember;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer ws run <script>` - run a Composer script in every member that
 * declares it, by proxying `run-script` per member.
 */
final readonly class RunScriptHandler implements CommandHandler
{
    /**
     * @param  list<WorkspaceMember>  $members
     * @param  list<string>  $forwarded  Script name followed by extra forwarded tokens
     */
    public function __construct(
        private array $members,
        private array $forwarded,
        private FanOut $fanOut,
    ) {}

    public function handle(OutputInterface $output): int
    {
        $script = $this->forwarded[0] ?? '';

        if ($script === '' || str_starts_with($script, '-')) {
            $output->writeln(
                '<error>Missing script name. Usage: composer ws run <script></error>',
            );

            return 1;
        }

        $eligible = array_values(
            array_filter(
                $this->members,
                static fn (WorkspaceMember $m): bool => $m->hasScript($script),
            ),
        );

        if ($eligible === []) {
            $output->writeln(
                sprintf(
                    '<comment>No workspace member declares a "%s" script. Nothing to do.</comment>',
                    $script,
                ),
            );

            return 0;
        }

        return $this->fanOut->execute(
            ['run-script', ...$this->forwarded],
            $eligible,
            $script,
            $output,
        );
    }
}
