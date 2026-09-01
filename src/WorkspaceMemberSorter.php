<?php

declare(strict_types=1);

namespace Mds\Workspace;

final class WorkspaceMemberSorter
{
    /**
     * Each member comes after its internal dependencies; independent ones keep input
     * order (callers pass an alphabetically sorted list).
     *
     * @param  list<WorkspaceMember>  $members
     * @return list<WorkspaceMember>
     *
     * @throws CyclicDependencyException
     */
    public static function Sort(array $members): array
    {
        /** @var array<string, WorkspaceMember> $byName */
        $byName = [];
        foreach ($members as $member) {
            $byName[$member->name] = $member;
        }

        /** @var list<WorkspaceMember> $sorted */
        $sorted = [];
        /** @var array<string, bool> $done */
        $done = [];
        /** @var array<string, bool> $onStack */
        $onStack = [];

        foreach ($members as $member) {
            self::Visit($member, $byName, $sorted, $done, $onStack, []);
        }

        return $sorted;
    }

    /**
     * A member only depends on earlier layers, so a whole layer runs concurrently.
     * Non-member dependencies carry no depth and never push a member down.
     *
     * @param  list<WorkspaceMember>  $members
     * @return list<list<WorkspaceMember>>
     *
     * @throws CyclicDependencyException
     */
    public static function Layers(array $members): array
    {
        /** @var array<string, int> $depth */
        $depth = [];
        /** @var array<int, list<WorkspaceMember>> $layers */
        $layers = [];

        // Sort() ordered dependencies first, so every depth is known on arrival.
        foreach (self::Sort($members) as $member) {
            $level = 0;

            foreach ($member->dependencies as $dependency) {
                if (isset($depth[$dependency])) {
                    $level = max($level, $depth[$dependency] + 1);
                }
            }

            $depth[$member->name] = $level;
            $layers[$level][] = $member;
        }

        ksort($layers);

        return array_values($layers);
    }

    /**
     * Depth-first visit. `$path` is the chain of member names currently being
     * resolved - used to render a readable cycle when one is found.
     *
     * @param  array<string, WorkspaceMember>  $byName
     * @param  list<WorkspaceMember>  $sorted
     * @param  array<string, bool>  $done
     * @param  array<string, bool>  $onStack
     * @param  list<string>  $path
     *
     * @throws CyclicDependencyException
     */
    private static function Visit(
        WorkspaceMember $member,
        array $byName,
        array &$sorted,
        array &$done,
        array &$onStack,
        array $path,
    ): void {
        if (isset($done[$member->name])) {
            return;
        }

        if (isset($onStack[$member->name])) {
            $cycleStart = array_search($member->name, $path, true);
            $cycle = $cycleStart === false ? [$member->name] : array_slice($path, (int) $cycleStart);
            $cycle[] = $member->name;

            throw CyclicDependencyException::ForCycle($cycle);
        }

        $onStack[$member->name] = true;
        $path[] = $member->name;

        foreach ($member->dependencies as $dependency) {
            if (isset($byName[$dependency])) {
                self::Visit($byName[$dependency], $byName, $sorted, $done, $onStack, $path);
            }
        }

        unset($onStack[$member->name]);
        $done[$member->name] = true;
        $sorted[] = $member;
    }
}
