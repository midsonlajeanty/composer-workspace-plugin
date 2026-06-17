<?php

declare(strict_types=1);

namespace Mds\Workspace;

use RuntimeException;

final class CyclicDependencyException extends RuntimeException
{
    /**
     * @param  list<string>  $cycle  Member names forming the cycle, ending with the repeated entry
     */
    private function __construct(public readonly array $cycle, string $message)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $cycle
     */
    public static function ForCycle(array $cycle): self
    {
        return new self(
            $cycle,
            sprintf(
                'Cyclic workspace dependency detected: %s. Resolve it before running workspace commands.',
                implode(' → ', $cycle),
            ),
        );
    }
}
