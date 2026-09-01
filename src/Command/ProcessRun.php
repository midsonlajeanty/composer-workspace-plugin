<?php

declare(strict_types=1);

namespace Mds\Workspace\Command;

use Mds\Workspace\Command\Contracts\MemberRun;
use Symfony\Component\Process\Process;

/**
 * isRunning() drains the process pipes, so polling is what keeps a chatty
 * member from blocking on a full one.
 */
final readonly class ProcessRun implements MemberRun
{
    public function __construct(private Process $process) {}

    public function finished(): bool
    {
        return ! $this->process->isRunning();
    }

    public function exitCode(): int
    {
        return $this->process->getExitCode() ?? 1;
    }

    public function output(): string
    {
        return $this->process->getOutput().$this->process->getErrorOutput();
    }
}
