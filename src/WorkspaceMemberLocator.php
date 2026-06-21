<?php

declare(strict_types=1);

namespace Mds\Workspace;

final class WorkspaceMemberLocator
{
    /**
     * Discover workspace members from the root composer.json `extra.packages`
     * globs. Each glob points at a directory whose immediate children that
     * contain a composer.json are treated as workspace members.
     *
     * @param  list<string>  $globs
     * @return list<WorkspaceMember>
     */
    public static function Locate(string $rootDir, array $globs): array
    {
        $rootDir = rtrim($rootDir, '/');

        /** @var array<string, array{member: array{name: string, path: string, type: string, scripts: list<string>}, requires: list<string>}> $collected */
        $collected = [];

        foreach ($globs as $glob) {
            $normalized = self::Normalize($rootDir, $glob);

            foreach (glob($normalized.'/*/composer.json') ?: [] as $manifest) {
                $path = dirname($manifest);

                if (isset($collected[$path])) {
                    continue;
                }

                $data = json_decode((string) file_get_contents($manifest), true);

                if (! is_array($data)) {
                    continue;
                }

                $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : basename($path);
                $type = isset($data['type']) && is_string($data['type']) ? $data['type'] : 'library';
                $scripts = isset($data['scripts']) && is_array($data['scripts']) ? array_keys($data['scripts']) : [];

                $collected[$path] = [
                    'member' => [
                        'name' => $name,
                        'path' => $path,
                        'type' => $type,
                        'scripts' => array_map(strval(...), $scripts),
                    ],
                    'requires' => self::RequireKeys($data),
                ];
            }
        }

        ksort($collected);

        $names = [];
        foreach ($collected as $entry) {
            $names[$entry['member']['name']] = true;
        }

        $members = [];
        foreach ($collected as $path => $entry) {
            $dependencies = [];
            foreach ($entry['requires'] as $require) {
                if (isset($names[$require]) && $require !== $entry['member']['name']) {
                    $dependencies[] = $require;
                }
            }

            $members[] = new WorkspaceMember(
                name: $entry['member']['name'],
                path: $entry['member']['path'],
                relativePath: self::Relative($rootDir, $path),
                type: $entry['member']['type'],
                scripts: $entry['member']['scripts'],
                dependencies: $dependencies,
            );
        }

        return $members;
    }

    /**
     * Merge the package names from `require` and `require-dev`, preserving the
     * order they appear (require first, then require-dev) and dropping
     * duplicates.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private static function RequireKeys(array $data): array
    {
        $keys = [];

        foreach (['require', 'require-dev'] as $section) {
            if (! isset($data[$section])) {
                continue;
            }
            if (! is_array($data[$section])) {
                continue;
            }
            foreach (array_keys($data[$section]) as $package) {
                if (is_string($package) && ! in_array($package, $keys, true)) {
                    $keys[] = $package;
                }
            }
        }

        return $keys;
    }

    private static function Normalize(string $rootDir, string $glob): string
    {
        $glob = rtrim($glob, '/');

        if (str_starts_with($glob, '/')) {
            return $glob;
        }

        $glob = preg_replace('#^\./#', '', $glob) ?? $glob;

        return $rootDir.'/'.$glob;
    }

    private static function Relative(string $rootDir, string $path): string
    {
        if (str_starts_with($path, $rootDir.'/')) {
            return substr($path, strlen($rootDir) + 1);
        }

        return $path;
    }
}
