<?php

declare(strict_types=1);

namespace Mds\Workspace;

final class WorkspaceConfig
{
    /**
     * Commands enabled by `"propagate": true`. Also the whitelist for the
     * explicit-list form - any other command name is ignored.
     */
    public const array DEFAULT_ACTIONS = ['install', 'update'];

    /**
     * @param  array<array-key, mixed>  $extra
     * @return list<string>
     */
    public static function propagatedActions(array $extra): array
    {
        $workspace = $extra['workspace'] ?? null;

        if (! is_array($workspace)) {
            return [];
        }

        $propagate = $workspace['propagate'] ?? false;

        if ($propagate === true) {
            return self::DEFAULT_ACTIONS;
        }

        if (! is_array($propagate)) {
            return [];
        }

        return array_values(array_filter(
            self::DEFAULT_ACTIONS,
            static fn (string $action): bool => in_array($action, $propagate, true),
        ));
    }

    /**
     * Off by default: scripts are user code and routinely share a database,
     * a port or a cache.
     *
     * @param  array<array-key, mixed>  $extra
     */
    public static function isParallelScript(array $extra, string $script): bool
    {
        $workspace = $extra['workspace'] ?? null;

        if (! is_array($workspace)) {
            return false;
        }

        $parallel = $workspace['parallel'] ?? false;

        if ($parallel === true) {
            return true;
        }

        return is_array($parallel) && in_array($script, $parallel, true);
    }
}
