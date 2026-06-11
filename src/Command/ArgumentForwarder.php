<?php

declare(strict_types=1);

namespace Mds\Workspace\Command;

/**
 * Extracts the tokens to forward to the proxied Composer command from raw
 * argv: everything after the action, minus the workspace command's own
 * options. A first `--` separator is dropped (Composer-style) and everything
 * after it is forwarded verbatim, so flags can be passed naturally:
 * `composer ws update --with-all-dependencies` needs no `--`.
 */
final readonly class ArgumentForwarder
{
    /**
     * @param  list<string>  $tokens  argv tokens, binary excluded
     * @return list<string>|null null when $commandName is not present in $tokens
     */
    public static function forwarded(array $tokens, string $commandName): ?array
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

            if (! $sawAction && ! str_starts_with($token, '-')) {
                $sawAction = true; // the action itself is not forwarded

                continue;
            }

            $forwarded[] = $token;
        }

        return $forwarded;
    }
}
