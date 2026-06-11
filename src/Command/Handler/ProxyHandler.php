<?php

declare(strict_types=1);

namespace Mds\Workspace\Command\Handler;

use Mds\Workspace\Command\FanOut;
use Mds\Workspace\WorkspaceMember;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Any other action — `composer ws update`, `ws require`, `ws audit`, … —
 * is proxied verbatim to Composer in every member, flags included.
 */
final readonly class ProxyHandler implements CommandHandler
{
    /**
     * @param  list<WorkspaceMember>  $members
     * @param  list<string>  $forwarded
     */
    public function __construct(
        private string $action,
        private array $members,
        private array $forwarded,
        private FanOut $fanOut,
    ) {}

    public function handle(OutputInterface $output): int
    {
        if ($this->members === []) {
            $output->writeln(
                '<comment>No workspace members found. Check extra.packages in the root composer.json.</comment>',
            );

            return 0;
        }

        return $this->fanOut->execute(
            [$this->action, ...$this->forwarded, '--no-interaction'],
            $this->members,
            trim($this->action.' '.implode(' ', $this->forwarded)),
            $output,
        );
    }
}
