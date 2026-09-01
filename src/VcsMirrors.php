<?php

declare(strict_types=1);

namespace Mds\Workspace;

/**
 * The shared VCS mirrors a member touches. Composer takes no lock on them, so
 * two members cloning one repository at once wipe each other's clone.
 */
final class VcsMirrors
{
    /** No real URL normalises to this, so it can stand for "any mirror". */
    public const string ANY = '*';

    /** Inlines its definitions, but GitDownloader still clones their `source`. */
    public const string PACKAGE = 'package';

    /** Types that clone into `cache-vcs-dir`; `path`/`composer`/`artifact` never do. */
    public const array TYPES = [
        'vcs',
        'git',
        'github',
        'gitlab',
        'bitbucket',
        'git-bitbucket',
        'hg',
        'fossil',
        'svn',
        'perforce',
    ];

    /**
     * Asking for source installs routes every package through a shared mirror, so
     * the member conflicts with all the others.
     *
     * @param  array<array-key, mixed>  $manifest
     * @return list<string>
     */
    public static function ForManifest(array $manifest): array
    {
        $config = $manifest['config'] ?? null;
        $preferred = is_array($config) ? $config['preferred-install'] ?? null : null;

        if (self::prefersSource($preferred)) {
            return [self::ANY];
        }

        return self::From($manifest['repositories'] ?? null);
    }

    /**
     * Composer accepts `repositories` as either a list or a keyed object.
     *
     * @return list<string>
     */
    public static function From(mixed $repositories): array
    {
        if (! is_array($repositories)) {
            return [];
        }

        $keys = [];

        foreach ($repositories as $repository) {
            $found = is_array($repository) ? self::mirrors($repository) : [];

            foreach ($found as $key) {
                if (! in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * --prefer-source routes any package through a mirror, not just declared
     * ones, so the whole fan-out has to serialise.
     *
     * @param  list<string>  $arguments
     */
    public static function SharedBy(array $arguments): bool
    {
        return in_array('--prefer-source', $arguments, true);
    }

    /** Either a single mode or a map of pattern to mode; one `source` is enough. */
    private static function prefersSource(mixed $preferred): bool
    {
        if (is_string($preferred)) {
            return $preferred === 'source';
        }

        if (! is_array($preferred)) {
            return false;
        }

        return in_array('source', $preferred, true);
    }

    /**
     * @param  array<array-key, mixed>  $repository
     * @return list<string>
     */
    private static function mirrors(array $repository): array
    {
        $type = $repository['type'] ?? null;

        if (! is_string($type)) {
            return [];
        }

        if ($type === self::PACKAGE) {
            return self::inlineSources($repository['package'] ?? null);
        }

        if (! in_array($type, self::TYPES, true)) {
            return [];
        }

        $key = self::key($repository['url'] ?? null);

        return $key === null ? [] : [$key];
    }

    /**
     * A `package` repository inlines one definition or a list of them.
     *
     * @return list<string>
     */
    private static function inlineSources(mixed $packages): array
    {
        if (! is_array($packages)) {
            return [];
        }

        $definitions = array_is_list($packages) ? $packages : [$packages];
        $keys = [];

        foreach ($definitions as $definition) {
            $key = is_array($definition) ? self::sourceKey($definition['source'] ?? null) : null;

            if ($key !== null && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private static function sourceKey(mixed $source): ?string
    {
        if (! is_array($source)) {
            return null;
        }

        $type = $source['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            return null;
        }

        return self::key($source['url'] ?? null);
    }

    private static function key(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $url = strtolower(trim($url));
        $url = preg_replace('#^[a-z0-9+.-]+://#', '', $url) ?? $url;
        $url = preg_replace('#^[^/@]*@#', '', $url) ?? $url;
        $url = preg_replace('#^([^/:]+):(?!\d)#', '$1/', $url) ?? $url;
        $url = preg_replace('#\.git$#', '', rtrim($url, '/')) ?? $url;
        $url = rtrim($url, '/');

        return $url === '' ? null : $url;
    }
}
