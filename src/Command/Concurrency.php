<?php

declare(strict_types=1);

namespace Mds\Workspace\Command;

/**
 * Auto-detection is capped because Composer is I/O bound: past a handful of
 * members the extra processes contend instead of adding throughput.
 */
final class Concurrency
{
    /**
     * Ceiling for auto-detection only. An explicit --concurrency is honoured
     * verbatim - the user knows their machine better than this heuristic.
     */
    public const int CAP = 8;

    public const int FALLBACK = 4;

    /**
     * Actions whose members must not run side by side unless asked: scripts are
     * user code and routinely share a database, a port or a cache.
     */
    public const string SEQUENTIAL_ACTION = 'run';

    public static function detect(): int
    {
        return max(1, min(self::CAP, self::cores()));
    }

    public static function resolve(mixed $option): int
    {
        $value = is_int($option) ? (string) $option : $option;

        if (! is_string($value) || preg_match('/^-?\d+$/', $value) !== 1) {
            return self::detect();
        }

        return max(1, (int) $value);
    }

    /**
     * `run` stays sequential until the user opts in via `extra.workspace.parallel`
     * or --concurrency, which wins in both directions.
     */
    public static function forAction(string $action, mixed $option, bool $parallel = false): int
    {
        if ($action === self::SEQUENTIAL_ACTION && $option === null) {
            return $parallel ? self::detect() : 1;
        }

        return self::resolve($option);
    }

    private static function cores(): int
    {
        $windows = getenv('NUMBER_OF_PROCESSORS');

        if (is_string($windows) && ctype_digit($windows)) {
            return (int) $windows;
        }

        // Not nproc: shell_exec is routinely disabled, and spawning a shell to count CPUs is absurd.
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');

            if (is_string($cpuinfo)) {
                $count = preg_match_all('/^processor\s*:/m', $cpuinfo);

                if ($count > 0) {
                    return $count;
                }
            }
        }

        return self::FALLBACK;
    }
}
