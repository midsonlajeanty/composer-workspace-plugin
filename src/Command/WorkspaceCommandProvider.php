<?php

declare(strict_types=1);

namespace Mds\Workspace\Command;

use Composer\Command\BaseCommand;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

final class WorkspaceCommandProvider implements CommandProviderCapability
{
    /**
     * @return array<int, BaseCommand>
     */
    public function getCommands(): array
    {
        return [
            new WorkspaceCommand,
        ];
    }
}
