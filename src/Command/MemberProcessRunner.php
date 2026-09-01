<?php

declare(strict_types=1);

namespace Mds\Workspace\Command;

use Closure;
use Mds\Workspace\Command\Contracts\MemberRun;
use Mds\Workspace\WorkspaceMember;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Isolated subprocess per member: PHP never unloads a class, so a second
 * member's autoloader would fatal with "Cannot redeclare".
 */
final class MemberProcessRunner
{
    public const string CHILD_ENV = 'COMPOSER_WORKSPACE_CHILD';

    /**
     * @param  list<string>  $command
     */
    public static function process(array $command, WorkspaceMember $member, bool $decorated): Process
    {
        if ($decorated) {
            $command[] = '--ansi';
        }

        return new Process(
            [self::binary(), ...$command],
            $member->path,
            [self::CHILD_ENV => '1'],
            null,
            null,
        );
    }

    /**
     * @return Closure(list<string>, WorkspaceMember): MemberRun
     */
    public static function make(OutputInterface $output): Closure
    {
        $decorated = $output->isDecorated();

        return static function (array $command, WorkspaceMember $member) use ($decorated): MemberRun {
            /** @var list<string> $command */
            $process = self::process($command, $member, $decorated);
            $process->start();

            return new ProcessRun($process);
        };
    }

    public static function binary(): string
    {
        $binary = getenv('COMPOSER_BINARY');

        return $binary !== false && $binary !== '' ? $binary : 'composer';
    }
}
