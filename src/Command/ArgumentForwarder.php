<?php

declare(strict_types=1);

namespace Mds\Workspace\Command;

/**
 * Tokens to forward to the proxied command: everything after the action, minus
 * the workspace command's own options and a leading `--`.
 */
final readonly class ArgumentForwarder
{
    /**
     * @param  list<string>  $tokens  argv tokens, binary excluded
     * @param  bool  $skipAction  False on the event path, where the command token itself is the action
     * @return list<string>|null null when $commandName is not present in $tokens
     */
    public static function forwarded(array $tokens, string $commandName, bool $skipAction = true): ?array
    {
        $start = array_search($commandName, $tokens, true);

        if ($start === false) {
            return null;
        }

        $forwarded = [];
        $sawAction = false;
        $passthrough = false;
        $count = count($tokens);

        for ($i = $start + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($passthrough) {
                $forwarded[] = $token;

                continue;
            }

            if ($token === '--') {
                $passthrough = true;

                continue;
            }

            if ($token === '--continue-on-error') {
                continue;
            }

            if ($token === '--concurrency') {
                $i++; // skip the option's value

                continue;
            }

            if (str_starts_with($token, '--concurrency=')) {
                continue;
            }

            if ($token === '--filter' || $token === '-f') {
                $i++; // skip the option's value

                continue;
            }

            if (str_starts_with($token, '--filter=')) {
                continue;
            }

            if (str_starts_with($token, '-f') && $token !== '-f') {
                continue;
            }

            if ($skipAction && ! $sawAction && ! str_starts_with($token, '-')) {
                $sawAction = true; // the action itself is not forwarded

                continue;
            }

            $forwarded[] = $token;
        }

        return $forwarded;
    }
}
