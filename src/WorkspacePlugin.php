<?php

declare(strict_types=1);

namespace Mds\Workspace;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Mds\Workspace\Command\ArgumentForwarder;
use Mds\Workspace\Command\MemberProcessRunner;
use Mds\Workspace\Command\WorkspaceCommandProvider;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Workspace Plugin
 *
 * @version 1.1.0
 *
 * @license MIT
 * @author Louis Midson LAJEANTY <midsonlajeanty@proton.me>
 */
final class WorkspacePlugin implements Capable, EventSubscriberInterface, PluginInterface
{
    /**
     * Version every workspace library is published under by the path repositories.
     * A member may require it explicitly or keep a plain "@dev" constraint.
     */
    public const string WORKSPACE_VERSION = 'dev-workspace';

    public const string OUTDATED_NOTICE =
        '<warning>workspace: extra.workspaces and extra.packages were removed - '
        .'move the globs to extra.workspace.members.</warning>';

    private ?string $command = null;

    private bool $propagated = false;

    public function activate(Composer $composer, IOInterface $io): void
    {
        try {
            $this->registerWorkspaceRepositories($composer, $io);
        } catch (Throwable $e) {
            $io->writeError(sprintf('<warning>workspace: skipped auto-registration (%s)</warning>', $e->getMessage()));
        }
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    /**
     * @return array<class-string, class-string>
     */
    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => WorkspaceCommandProvider::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::PRE_COMMAND_RUN => 'captureCommand',
            ScriptEvents::POST_INSTALL_CMD => 'propagateToMembers',
            ScriptEvents::POST_UPDATE_CMD => 'propagateToMembers',
        ];
    }

    public function captureCommand(PreCommandRunEvent $event): void
    {
        $this->command = $event->getCommand();
    }

    public function propagateToMembers(Event $event): void
    {
        $action = self::resolvePropagation(
            $this->command,
            WorkspaceConfig::propagatedActions($event->getComposer()->getPackage()->getExtra()),
            getenv(MemberProcessRunner::CHILD_ENV) !== false,
            $this->propagated,
        );

        if ($action === null) {
            return;
        }

        $cwd = getcwd();

        if ($cwd === false) {
            return;
        }

        $root = WorkspaceRoot::discover($cwd);

        if (! $root instanceof WorkspaceRoot) {
            return;
        }

        $this->propagated = true;

        $io = $event->getIO();
        $output = new ConsoleOutput(OutputInterface::VERBOSITY_NORMAL, $io->isDecorated());
        $propagator = new WorkspacePropagator(MemberProcessRunner::make($output));
        $argv = $_SERVER['argv'] ?? null;
        $argv = is_array($argv) ? array_values(array_filter($argv, is_string(...))) : null;

        $propagator->propagate(
            $root->dir,
            $root->globs,
            $action,
            self::forwardedFor($action, $argv),
            $output,
        );
    }

    /**
     * Map the real command to the member action, or null to skip. Keyed on the
     * command not the event, so a lockless `install` still propagates as an install.
     */
    public static function actionForCommand(?string $command): ?string
    {
        return match ($command) {
            'install', 'i' => 'install',
            'update', 'upgrade', 'u' => 'update',
            default => null,
        };
    }

    /**
     * The member action to fan out, or null to skip: guards recursion,
     * double-firing, non-propagating commands and the opt-in config.
     *
     * @param  list<string>  $enabledActions
     */
    public static function resolvePropagation(
        ?string $realCommand,
        array $enabledActions,
        bool $inChild,
        bool $alreadyPropagated,
    ): ?string {
        if ($inChild || $alreadyPropagated) {
            return null;
        }

        $action = self::actionForCommand($realCommand);

        if ($action === null) {
            return null;
        }

        return in_array($action, $enabledActions, true) ? $action : null;
    }

    /**
     * @param  list<string>|null  $argv
     * @return list<string>
     */
    public static function forwardedFor(string $action, ?array $argv): array
    {
        if (! is_array($argv)) {
            return [];
        }

        $tokens = array_values(array_filter($argv, is_string(...)));

        return ArgumentForwarder::forwarded(array_slice($tokens, 1), $action, false) ?? [];
    }

    private function registerWorkspaceRepositories(Composer $composer, IOInterface $io): void
    {
        $cwd = getcwd();

        if ($cwd === false) {
            return;
        }

        if (
            WorkspaceRoot::isOutdated($composer->getPackage()->getExtra())
            && getenv(MemberProcessRunner::CHILD_ENV) === false
        ) {
            $io->writeError(self::OUTDATED_NOTICE);
        }

        $root = WorkspaceRoot::discover($cwd);

        if (! $root instanceof WorkspaceRoot) {
            return;
        }

        $repositoryManager = $composer->getRepositoryManager();
        $self = realpath($cwd);
        $registered = [];

        foreach (WorkspaceMemberLocator::Locate($root->dir, $root->globs) as $member) {
            $memberPath = realpath($member->path);

            if ($member->isProject()) {
                continue;
            }

            if ($memberPath === false) {
                continue;
            }

            if ($memberPath === $self) {
                continue;
            }

            $repositoryManager->prependRepository($repositoryManager->createRepository('path', [
                'url' => $self === false ? $memberPath : $this->relativeUrl($self, $memberPath),
                'options' => [
                    'symlink' => true,
                    'versions' => [$member->name => self::WORKSPACE_VERSION],
                ],
            ]));

            $registered[] = $member->name;
        }

        if ($registered !== []) {
            $io->write(
                sprintf('<info>workspace</info> registered %d members from %s: %s', count($registered), $root->dir, implode(', ', $registered)),
                true,
                IOInterface::VERBOSE,
            );
        }
    }

    private function relativeUrl(string $from, string $to): string
    {
        $fromParts = explode('/', trim($from, '/'));
        $toParts = explode('/', trim($to, '/'));

        while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        $url = str_repeat('../', count($fromParts)).implode('/', $toParts);

        return $url === '' ? '.' : rtrim($url, '/');
    }
}
