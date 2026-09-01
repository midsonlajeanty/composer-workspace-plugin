<?php

declare(strict_types=1);

use Mds\Workspace\VcsMirrors;

it('finds no mirror when the repositories block is absent or unusable', function (): void {
    expect(VcsMirrors::From(null))->toBe([]);
    expect(VcsMirrors::From([]))->toBe([]);
    expect(VcsMirrors::From('nonsense'))->toBe([]);
});

it('finds the mirror of a vcs repository', function (): void {
    expect(VcsMirrors::From([
        ['type' => 'vcs', 'url' => 'https://github.com/acme/lib.git'],
    ]))->toBe(['github.com/acme/lib']);
});

it('reads the object form of the repositories block', function (): void {
    expect(VcsMirrors::From([
        'acme-lib' => ['type' => 'git', 'url' => 'https://example.com/acme/lib.git'],
    ]))->toBe(['example.com/acme/lib']);
});

it('recognises every repository type that clones into the shared cache', function (): void {
    foreach (VcsMirrors::TYPES as $type) {
        expect(VcsMirrors::From([['type' => $type, 'url' => 'https://example.com/acme/lib']]))
            ->toBe(['example.com/acme/lib'], "type {$type} should be treated as a mirror");
    }
});

it('ignores repository types that never clone', function (): void {
    expect(VcsMirrors::From([
        ['type' => 'path', 'url' => '../lib'],
        ['type' => 'composer', 'url' => 'https://repo.packagist.com/acme/'],
        ['type' => 'artifact', 'url' => './artifacts'],
    ]))->toBe([]);
});

it('treats an ssh and an https clone url as the same mirror', function (): void {
    expect(VcsMirrors::From([
        ['type' => 'vcs', 'url' => 'git@github.com:Acme/Lib.git'],
        ['type' => 'vcs', 'url' => 'https://github.com/acme/lib'],
    ]))->toBe(['github.com/acme/lib']);
});

it('strips credentials from the mirror key', function (): void {
    expect(VcsMirrors::From([
        ['type' => 'vcs', 'url' => 'https://user:token@example.com/acme/lib.git'],
    ]))->toBe(['example.com/acme/lib']);
});

it('ignores entries without a usable url', function (): void {
    expect(VcsMirrors::From([
        ['type' => 'vcs'],
        ['type' => 'vcs', 'url' => 42],
        'not-an-entry',
    ]))->toBe([]);
});

it('treats --prefer-source as one mirror shared by every member', function (): void {
    expect(VcsMirrors::SharedBy(['update', '--prefer-source']))->toBeTrue();
    expect(VcsMirrors::SharedBy(['update', '--with-all-dependencies']))->toBeFalse();
    expect(VcsMirrors::SharedBy([]))->toBeFalse();
});

it('treats a source-preferring member as touching every mirror', function (): void {
    expect(VcsMirrors::ForManifest([
        'config' => ['preferred-install' => 'source'],
    ]))->toBe([VcsMirrors::ANY]);
});

it('treats a per-pattern source preference as touching every mirror', function (): void {
    expect(VcsMirrors::ForManifest([
        'config' => ['preferred-install' => ['acme/*' => 'source', '*' => 'dist']],
    ]))->toBe([VcsMirrors::ANY]);
});

it('reads the repositories block when nothing prefers source', function (): void {
    $repositories = [['type' => 'vcs', 'url' => 'https://github.com/acme/lib.git']];

    expect(VcsMirrors::ForManifest([
        'config' => ['preferred-install' => 'dist'],
        'repositories' => $repositories,
    ]))->toBe(['github.com/acme/lib']);

    expect(VcsMirrors::ForManifest([
        'config' => ['preferred-install' => ['*' => 'auto']],
        'repositories' => $repositories,
    ]))->toBe(['github.com/acme/lib']);

    expect(VcsMirrors::ForManifest(['repositories' => $repositories]))
        ->toBe(['github.com/acme/lib']);
});

it('finds no mirror in a manifest that declares neither', function (): void {
    expect(VcsMirrors::ForManifest([]))->toBe([]);
    expect(VcsMirrors::ForManifest(['config' => 'nonsense']))->toBe([]);
});

it('finds the mirror behind an inline package source', function (): void {
    expect(VcsMirrors::From([
        [
            'type' => 'package',
            'package' => [
                'name' => 'acme/legacy',
                'version' => '1.0.0',
                'source' => [
                    'type' => 'git',
                    'url' => 'https://github.com/acme/legacy.git',
                    'reference' => 'main',
                ],
            ],
        ],
    ]))->toBe(['github.com/acme/legacy']);
});

it('reads every definition of a package repository that inlines a list', function (): void {
    expect(VcsMirrors::From([
        [
            'type' => 'package',
            'package' => [
                ['name' => 'acme/one', 'source' => ['type' => 'git', 'url' => 'https://example.com/one']],
                ['name' => 'acme/two', 'source' => ['type' => 'hg', 'url' => 'https://example.com/two']],
            ],
        ],
    ]))->toBe(['example.com/one', 'example.com/two']);
});

it('ignores a package repository that only ships a dist', function (): void {
    expect(VcsMirrors::From([
        [
            'type' => 'package',
            'package' => [
                'name' => 'acme/zip',
                'version' => '1.0.0',
                'dist' => ['type' => 'zip', 'url' => 'https://example.com/acme.zip'],
            ],
        ],
    ]))->toBe([]);
});

it('ignores an inline source without a usable type or url', function (): void {
    expect(VcsMirrors::From([
        ['type' => 'package', 'package' => ['name' => 'acme/a', 'source' => ['type' => 'zip', 'url' => 'https://x/a']]],
        ['type' => 'package', 'package' => ['name' => 'acme/b', 'source' => ['type' => 'git']]],
        ['type' => 'package', 'package' => 'nonsense'],
        ['type' => 'package'],
    ]))->toBe([]);
});

it('collapses a vcs repository and an inline source onto one mirror', function (): void {
    expect(VcsMirrors::From([
        ['type' => 'vcs', 'url' => 'git@github.com:acme/lib.git'],
        [
            'type' => 'package',
            'package' => ['name' => 'acme/lib-fork', 'source' => ['type' => 'git', 'url' => 'https://github.com/acme/lib']],
        ],
    ]))->toBe(['github.com/acme/lib']);
});
