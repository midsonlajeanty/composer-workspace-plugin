<?php

declare(strict_types=1);

namespace Mds\Workspace\Command;

use Closure;
use Composer\Command\BaseCommand;
use Composer\Factory;
use Mds\Workspace\Command\Handler\CommandHandler;
use Mds\Workspace\Command\Handler\ListMembersHandler;
use Mds\Workspace\Command\Handler\ProxyHandler;
use Mds\Workspace\Command\Handler\RunScriptHandler;
use Mds\Workspace\WorkspaceMember;
use Mds\Workspace\WorkspaceMemberLocator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Thin dispatcher: resolves the members and the action, then hands off to a
 * dedicated handler. Composer commands are proxied to every member in an
 * isolated subprocess, flags forwarded verbatim.
 */
final class WorkspaceCommand extends BaseCommand
{
    private const array ACTION_ALIASES = [
        'i' => 'install',
        'upgrade' => 'update',
        'dumpautoload' => 'dump-autoload',
    ];

    protected function configure(): void
    {
        // Unknown options belong to the proxied command and are forwarded
        // verbatim — `composer ws update --with-all-dependencies` just works.
        $this->ignoreValidationErrors();

        $this->setName('workspace')
            ->setAliases(['ws'])
            ->setDescription(
                'Run Composer scripts and commands across every workspace member (bun-style).',
            )
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'list, run, or any Composer command to proxy (install, update, require, audit, …)',
            )
            ->addArgument(
                'args',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                "Script name (with 'run'), package names, or extra flags — forwarded verbatim",
            )
            ->addOption(
                'filter',
                'f',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Only members whose name/path matches this glob (repeatable)',
            )
            ->addOption(
                'continue-on-error',
                null,
                InputOption::VALUE_NONE,
                'Keep going after a member fails instead of stopping',
            )
            ->setHelp(
                <<<'HELP'
                Discover workspace members from the root composer.json <info>extra.packages</info>
                globs and fan a script or a Composer command out to every member.

                Scripts:
                  <info>composer ws list</info>                       List members and their scripts
                  <info>composer ws run test</info>                   Run "test" in every member that has it
                  <info>composer ws run lint --filter=audit</info>    Only members matching "audit"

                Any other action is proxied to Composer in every member, flags included:
                  <info>composer ws install</info>
                  <info>composer ws update --with-all-dependencies</info>
                  <info>composer ws update phpstan/phpstan</info>     Update one package everywhere
                  <info>composer ws require spatie/laravel-data</info>
                  <info>composer ws require --dev rector/rector --filter=packages/*</info>
                  <info>composer ws remove laravel/pao --dev</info>
                  <info>composer ws dump-autoload --optimize</info>
                  <info>composer ws outdated</info>
                  <info>composer ws audit</info>
                HELP
                ,
            );
    }

    /**
     * Like GlobalCommand: the real work happens in the proxied per-member
     * runs, so the application skips its own startup checks for this command.
     */
    #[\Override]
    public function isProxyCommand(): bool
    {
        return true;
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $argument = $input->getArgument('action');
        $rawAction = is_string($argument) ? $argument : '';

        if ($rawAction === '') {
            $output->writeln(
                '<error>Missing action. Usage: composer ws <action> — e.g. composer ws run test</error>',
            );

            return self::FAILURE;
        }

        $action = self::ACTION_ALIASES[$rawAction] ?? $rawAction;
        $members = $this->members($input);
        $forwarded = $this->forwardedArguments($input);
        $fanOut = new FanOut(
            $this->proxyRunner($output),
            (bool) $input->getOption('continue-on-error'),
        );

        return $this->handler($action, $members, $forwarded, $fanOut)->handle($output);
    }

    /**
     * @param  list<WorkspaceMember>  $members
     * @param  list<string>  $forwarded
     */
    private function handler(
        string $action,
        array $members,
        array $forwarded,
        FanOut $fanOut,
    ): CommandHandler {
        return match ($action) {
            'list' => new ListMembersHandler($members),
            'run' => new RunScriptHandler($members, $forwarded, $fanOut),
            default => new ProxyHandler($action, $members, $forwarded, $fanOut),
        };
    }

    /**
     * Run Composer in a subprocess per member. In-process proxying is not
     * viable for a fan-out: PHP scripts triggered by members (e.g. Laravel's
     * postAutoloadDump) require_once their vendor/autoload.php into the
     * running process, and PHP can never unload a class — the next member
     * declaring the same autoloader class name fatals with "Cannot redeclare"
     * (GlobalCommand only gets away with in-process proxying because it
     * proxies exactly once per process). A subprocess gives every member a
     * pristine process and releases all of its memory on exit.
     *
     * @return Closure(list<string>, WorkspaceMember): int
     */
    private function proxyRunner(OutputInterface $output): Closure
    {
        $binary = $this->composerBinary();
        $decorated = $output->isDecorated();

        return static function (array $command, WorkspaceMember $member) use ($binary, $decorated, $output): int {
            /** @var list<string> $command */
            if ($decorated) {
                $command[] = '--ansi';
            }

            $process = new Process([$binary, ...$command], $member->path, null, null, null);

            return $process->run(static function (
                string $type,
                string $buffer,
            ) use ($output): void {
                $output->write($buffer);
            });
        };
    }

    private function composerBinary(): string
    {
        $binary = getenv('COMPOSER_BINARY');

        return $binary !== false && $binary !== '' ? $binary : 'composer';
    }

    /**
     * Everything the user typed after the action — unknown flags included —
     * minus this command's own options.
     *
     * @return list<string>
     */
    private function forwardedArguments(InputInterface $input): array
    {
        $argv = $_SERVER['argv'] ?? null;
        $commandName = $input->getFirstArgument();

        if (is_array($argv) && is_string($commandName)) {
            $tokens = array_values(array_filter($argv, is_string(...)));
            $forwarded = ArgumentForwarder::forwarded(
                array_slice($tokens, 1),
                $commandName,
            );

            if ($forwarded !== null) {
                return $forwarded;
            }
        }

        // Programmatic invocations (ArrayInput) have no argv: fall back to
        // the parsed positional arguments; unknown options are lost there.
        $args = $input->getArgument('args');

        return array_values(array_filter(is_array($args) ? $args : [], is_string(...)));
    }

    /**
     * @return list<WorkspaceMember>
     */
    private function members(InputInterface $input): array
    {
        $extra = $this->requireComposer()->getPackage()->getExtra();
        $globs =
            isset($extra['packages']) && is_array($extra['packages'])
                ? $extra['packages']
                : [];

        $rootDir = dirname((string) realpath(Factory::getComposerFile()));
        $members = WorkspaceMemberLocator::Locate(
            $rootDir,
            array_values(array_filter($globs, is_string(...))),
        );

        /** @var list<string> $filters */
        $filters = (array) $input->getOption('filter');

        if ($filters === []) {
            return $members;
        }

        return array_values(
            array_filter($members, static function (WorkspaceMember $member) use (
                $filters,
            ): bool {
                foreach ($filters as $filter) {
                    if ($member->matches($filter)) {
                        return true;
                    }
                }

                return false;
            }),
        );
    }
}
