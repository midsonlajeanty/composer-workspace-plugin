<?php

declare(strict_types=1);

namespace Mds\Workspace;

final readonly class WorkspaceRoot
{
    /**
     * @param  list<string>  $globs
     */
    private function __construct(
        public string $dir,
        public array $globs,
    ) {}

    /**
     * Locate the workspace root. The COMPOSER_WORKSPACE_ROOT environment
     * variable wins (useful in containers where the monorepo layout is not
     * preserved); otherwise walk up from $cwd until a composer.json declaring
     * `extra.packages` is found, the same way git discovers its repository.
     */
    public static function discover(string $cwd): ?self
    {
        $explicit = getenv('COMPOSER_WORKSPACE_ROOT');

        if (is_string($explicit) && $explicit !== '') {
            return self::fromDir(rtrim($explicit, '/'));
        }

        $dir = realpath($cwd);

        if ($dir === false) {
            return null;
        }

        while (true) {
            $root = self::fromDir($dir);

            if ($root instanceof self) {
                return $root;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                return null;
            }

            $dir = $parent;
        }
    }

    private static function fromDir(string $dir): ?self
    {
        $manifest = $dir.'/composer.json';

        if (! is_file($manifest)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($manifest), true);

        if (! is_array($data)) {
            return null;
        }

        $extra = $data['extra'] ?? null;

        if (! is_array($extra) || ! isset($extra['packages']) || ! is_array($extra['packages'])) {
            return null;
        }

        return new self($dir, array_values(array_filter($extra['packages'], is_string(...))));
    }
}
