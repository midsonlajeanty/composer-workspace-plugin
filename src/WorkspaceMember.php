<?php

declare(strict_types=1);

namespace Mds\Workspace;

final readonly class WorkspaceMember
{
    /**
     * @param  list<string>  $scripts
     * @param  list<string>  $dependencies
     * @param  list<string>  $vcsMirrors  Shared VCS mirrors this member clones into
     */
    public function __construct(
        public string $name,
        public string $path,
        public string $relativePath,
        public string $type,
        public array $scripts,
        public array $dependencies = [],
        public array $vcsMirrors = [],
    ) {}

    public function hasScript(string $script): bool
    {
        return in_array($script, $this->scripts, true);
    }

    public function isProject(): bool
    {
        return $this->type === 'project';
    }

    public function matches(string $filter): bool
    {
        return fnmatch($filter, $this->name)
            || fnmatch($filter, basename($this->path))
            || fnmatch($filter, $this->relativePath)
            || str_contains($this->name, $filter)
            || str_contains($this->relativePath, $filter);
    }
}
