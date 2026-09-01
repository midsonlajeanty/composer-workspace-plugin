<?php

declare(strict_types=1);

use Mds\Workspace\CyclicDependencyException;
use Mds\Workspace\WorkspaceMember;
use Mds\Workspace\WorkspaceMemberSorter;

/**
 * @param  list<string>  $dependencies
 */
function sorter_member(string $name, array $dependencies = []): WorkspaceMember
{
    return new WorkspaceMember(
        name: $name,
        path: '/tmp/'.$name,
        relativePath: 'packages/'.$name,
        type: 'library',
        scripts: [],
        dependencies: $dependencies,
    );
}

/**
 * @param  list<WorkspaceMember>  $members
 * @return list<string>
 */
function sorter_names(array $members): array
{
    return array_map(static fn (WorkspaceMember $m): string => $m->name, $members);
}

it('formats the cycle into a readable message', function (): void {
    $exception = CyclicDependencyException::ForCycle(['acme/a', 'acme/b', 'acme/a']);

    expect($exception)->toBeInstanceOf(RuntimeException::class);
    expect($exception->cycle)->toBe(['acme/a', 'acme/b', 'acme/a']);
    expect($exception->getMessage())
        ->toBe('Cyclic workspace dependency detected: acme/a → acme/b → acme/a. Resolve it before running workspace commands.');
});

it('orders a linear chain dependencies first', function (): void {
    // c requires b, b requires a → expect a, b, c
    $members = [
        sorter_member('acme/c', ['acme/b']),
        sorter_member('acme/b', ['acme/a']),
        sorter_member('acme/a'),
    ];

    expect(sorter_names(WorkspaceMemberSorter::Sort($members)))
        ->toBe(['acme/a', 'acme/b', 'acme/c']);
});

it('orders a diamond with the root first and the join last', function (): void {
    // d requires b and c, both require a
    $members = [
        sorter_member('acme/d', ['acme/b', 'acme/c']),
        sorter_member('acme/b', ['acme/a']),
        sorter_member('acme/c', ['acme/a']),
        sorter_member('acme/a'),
    ];

    // Stable tie-break: root, then the arms in input order, join last.
    expect(sorter_names(WorkspaceMemberSorter::Sort($members)))
        ->toBe(['acme/a', 'acme/b', 'acme/c', 'acme/d']);
});

it('preserves input order for independent members', function (): void {
    $members = [
        sorter_member('acme/a'),
        sorter_member('acme/b'),
        sorter_member('acme/c'),
    ];

    expect(sorter_names(WorkspaceMemberSorter::Sort($members)))
        ->toBe(['acme/a', 'acme/b', 'acme/c']);
});

it('throws on a dependency cycle', function (): void {
    $members = [
        sorter_member('acme/a', ['acme/b']),
        sorter_member('acme/b', ['acme/a']),
    ];

    WorkspaceMemberSorter::Sort($members);
})->throws(CyclicDependencyException::class);

/**
 * @param  list<list<WorkspaceMember>>  $layers
 * @return list<list<string>>
 */
function sorter_layer_names(array $layers): array
{
    return array_map(sorter_names(...), $layers);
}

it('splits a linear chain into one layer per link', function (): void {
    // c requires b, b requires a → nothing can run alongside anything
    $members = [
        sorter_member('acme/c', ['acme/b']),
        sorter_member('acme/b', ['acme/a']),
        sorter_member('acme/a'),
    ];

    expect(sorter_layer_names(WorkspaceMemberSorter::Layers($members)))
        ->toBe([['acme/a'], ['acme/b'], ['acme/c']]);
});

it('groups the arms of a diamond into a single layer', function (): void {
    // b and c both only need a, so they belong in the same layer
    $members = [
        sorter_member('acme/d', ['acme/b', 'acme/c']),
        sorter_member('acme/b', ['acme/a']),
        sorter_member('acme/c', ['acme/a']),
        sorter_member('acme/a'),
    ];

    expect(sorter_layer_names(WorkspaceMemberSorter::Layers($members)))
        ->toBe([['acme/a'], ['acme/b', 'acme/c'], ['acme/d']]);
});

it('puts every independent member in one single layer', function (): void {
    $members = [
        sorter_member('acme/a'),
        sorter_member('acme/b'),
        sorter_member('acme/c'),
    ];

    expect(sorter_layer_names(WorkspaceMemberSorter::Layers($members)))
        ->toBe([['acme/a', 'acme/b', 'acme/c']]);
});

it('ignores dependencies that are not workspace members when layering', function (): void {
    // laravel/framework is external: it must not push acme/b down a layer
    $members = [
        sorter_member('acme/a', ['laravel/framework']),
        sorter_member('acme/b', ['vendor/unknown']),
    ];

    expect(sorter_layer_names(WorkspaceMemberSorter::Layers($members)))
        ->toBe([['acme/a', 'acme/b']]);
});

it('throws on a dependency cycle when layering', function (): void {
    $members = [
        sorter_member('acme/a', ['acme/b']),
        sorter_member('acme/b', ['acme/a']),
    ];

    WorkspaceMemberSorter::Layers($members);
})->throws(CyclicDependencyException::class);
