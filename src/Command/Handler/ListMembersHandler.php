<?php

declare(strict_types=1);

namespace Mds\Workspace\Command\Handler;

use Mds\Workspace\WorkspaceMember;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer ws list` - show every workspace member and its scripts.
 */
final readonly class ListMembersHandler implements CommandHandler
{
    /**
     * @param  list<WorkspaceMember>  $members
     */
    public function __construct(
        private array $members,
    ) {}

    public function handle(OutputInterface $output): int
    {
        if ($this->members === []) {
            $output->writeln(
                '<comment>No workspace members found. Check extra.packages in the root composer.json.</comment>',
            );

            return 0;
        }

        $output->writeln(
            sprintf('<info>%d workspace members:</info>', count($this->members)),
        );

        foreach ($this->members as $member) {
            $output->writeln(
                sprintf(
                    "\n  <info>%s</info> <comment>(%s)</comment>",
                    $member->name,
                    $member->relativePath,
                ),
            );
            $output->writeln(
                '    scripts: '.
                    ($member->scripts === []
                        ? '<comment>none</comment>'
                        : implode(', ', $member->scripts)),
            );
        }

        return 0;
    }
}
