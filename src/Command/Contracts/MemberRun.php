<?php

declare(strict_types=1);

namespace Mds\Workspace\Command\Contracts;

interface MemberRun
{
    /**
     * Non-blocking and cheap - the fan-out calls it in a polling loop.
     */
    public function finished(): bool;

    /**
     * Only meaningful once finished() returned true.
     */
    public function exitCode(): int;

    /**
     * Everything the member wrote, standard and error output combined.
     */
    public function output(): string;
}
