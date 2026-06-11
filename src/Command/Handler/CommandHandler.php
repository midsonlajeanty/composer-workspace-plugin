<?php

declare(strict_types=1);

namespace Mds\Workspace\Command\Handler;

use Symfony\Component\Console\Output\OutputInterface;

interface CommandHandler
{
    /**
     * @return int Command exit code
     */
    public function handle(OutputInterface $output): int;
}
