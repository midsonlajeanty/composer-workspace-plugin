<?php

declare(strict_types=1);

namespace Mds\Workspace;

final readonly class WorkspaceRoot
{
    public const string KEY = 'workspace';

    public const string MEMBERS = 'members';

    /** Keys that held the globs before they moved under `workspace`. */
    public const array REMOVED_KEYS = ['workspaces', 'packages'];

    /**
     * @param  list<string>  $globs
     */
    private function __construct(
        public string $dir,
        public array $globs,
    ) {}

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

    /**
     * @param  array<array-key, mixed>  $extra
     * @return list<string>|null
     */
    public static function readGlobs(array $extra): ?array
    {
        $workspace = $extra[self::KEY] ?? null;

        if (! is_array($workspace)) {
            return null;
        }

        $members = $workspace[self::MEMBERS] ?? null;

        if (! is_array($members)) {
            return null;
        }

        return array_values(array_filter($members, is_string(...)));
    }

    /**
     * A manifest still carrying the globs where they used to live would go
     * silently unread, so callers warn instead.
     *
     * @param  array<array-key, mixed>  $extra
     */
    public static function isOutdated(array $extra): bool
    {
        if (self::readGlobs($extra) !== null) {
            return false;
        }

        foreach (self::REMOVED_KEYS as $key) {
            if (isset($extra[$key]) && is_array($extra[$key])) {
                return true;
            }
        }

        return false;
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

        if (! is_array($extra)) {
            return null;
        }

        $globs = self::readGlobs($extra);

        if ($globs === null) {
            return null;
        }

        return new self($dir, $globs);
    }
}
