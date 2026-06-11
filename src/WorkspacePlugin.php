<?php

declare(strict_types=1);

namespace Mds\Workspace;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Mds\Workspace\Command\WorkspaceCommandProvider;
use Throwable;

/**
 * Workspace Plugin
 *
 * @version 1.0.0-dev
 *
 * @license MIT
 * @author Louis Midson LAJEANTY <midsonlajeanty@proton.me>
 */
final class WorkspacePlugin implements Capable, PluginInterface
{
    /**
     * Version every workspace library is published under by the auto-registered
     * path repositories. Members can require it explicitly ("acme/support":
     * "dev-workspace") or keep a plain "@dev" constraint — both resolve here.
     */
    public const string WORKSPACE_VERSION = 'dev-workspace';

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
     * Make every workspace library resolvable without a `repositories` block:
     * discover the workspace root, then prepend a symlinked path repository
     * for each library member, published as dev-workspace.
     */
    private function registerWorkspaceRepositories(Composer $composer, IOInterface $io): void
    {
        $cwd = getcwd();

        if ($cwd === false) {
            return;
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
                // A relative URL yields a relative vendor symlink, which keeps
                // working when the monorepo is bind-mounted elsewhere (Docker).
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
