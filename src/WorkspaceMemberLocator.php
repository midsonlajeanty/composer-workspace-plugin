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
        $members = [];

        foreach ($globs as $glob) {
            $normalized = self::Normalize($rootDir, $glob);

            foreach (glob($normalized.'/*/composer.json') ?: [] as $manifest) {
                $path = dirname($manifest);

                if (isset($members[$path])) {
                    continue;
                }

                $data = json_decode((string) file_get_contents($manifest), true);

                if (! is_array($data)) {
                    continue;
                }

                $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : basename($path);
                $type = isset($data['type']) && is_string($data['type']) ? $data['type'] : 'library';
                $scripts = isset($data['scripts']) && is_array($data['scripts']) ? array_keys($data['scripts']) : [];

                $members[$path] = new WorkspaceMember(
                    name: $name,
                    path: $path,
                    relativePath: self::Relative($rootDir, $path),
                    type: $type,
                    scripts: array_map(strval(...), $scripts),
                );
            }
        }

        ksort($members);

        return array_values($members);
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
