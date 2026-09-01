<?php

declare(strict_types=1);

namespace Mds\Workspace;

use Closure;
use Mds\Workspace\Command\Concurrency;
use Mds\Workspace\Command\Contracts\MemberRun;
use Mds\Workspace\Command\FanOut;
use Mds\Workspace\Command\Handler\ProxyHandler;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Event-side counterpart of the `ws` proxy command. The runner is injected so
 * the real one carries the recursion sentinel (see MemberProcessRunner).
 */
final readonly class WorkspacePropagator
{
    /**
     * @param  Closure(list<string>, WorkspaceMember): MemberRun  $runner
     */
    public function __construct(
        private Closure $runner,
        private ?int $concurrency = null,
    ) {}

    /**
     * @param  list<string>  $globs
     * @param  list<string>  $forwarded
     */
    public function propagate(
        string $rootDir,
        array $globs,
        string $action,
        array $forwarded,
        OutputInterface $output,
    ): int {
        $members = WorkspaceMemberLocator::Locate($rootDir, $globs);
        $fanOut = new FanOut(
            $this->runner,
            false,
            $this->concurrency ?? Concurrency::detect(),
            VcsMirrors::SharedBy($forwarded),
        );

        return new ProxyHandler($action, $members, $forwarded, $fanOut)->handle($output);
    }
}
