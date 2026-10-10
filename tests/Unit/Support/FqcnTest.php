<?php

declare(strict_types=1);

use Lucasp\Loom\Support\Fqcn;

it('normalizes only leading backslashes', function (string $in, string $out): void {
    expect(Fqcn::normalize($in))->toBe($out);
})->with([
    'empty' => ['', ''],
    'plain' => ['App\\Foo', 'App\\Foo'],
    'one leading' => ['\\App\\Foo', 'App\\Foo'],
    'many leading' => ['\\\\App\\Foo', 'App\\Foo'],
    'only backslash' => ['\\', ''],
    'trailing kept' => ['App\\Foo\\', 'App\\Foo\\'],
    'whitespace kept' => [' \\App\\Foo', ' \\App\\Foo'],
    'case kept' => ['\\app\\FOO', 'app\\FOO'],
]);

it('returns the short name', function (string $in, string $out): void {
    expect(Fqcn::short($in))->toBe($out);
})->with([
    'empty' => ['', ''],
    'no namespace' => ['Foo', 'Foo'],
    'namespaced' => ['App\\Events\\OrderPlaced', 'OrderPlaced'],
    'leading backslash' => ['\\App\\Foo', 'Foo'],
    'trailing backslash' => ['App\\', ''],
]);

it('returns the namespace', function (string $in, string $out): void {
    expect(Fqcn::namespaceOf($in))->toBe($out);
})->with([
    'empty' => ['', ''],
    'no namespace' => ['Foo', ''],
    'one level' => ['App\\Foo', 'App'],
    'nested' => ['App\\Domain\\Billing\\Invoice', 'App\\Domain\\Billing'],
    'leading backslash kept' => ['\\Foo', ''],
    'leading backslash nested' => ['\\App\\Foo', '\\App'],
]);

it('compares case-sensitively while ignoring leading backslashes', function (): void {
    expect(Fqcn::same('\\App\\Foo', 'App\\Foo'))->toBeTrue()
        ->and(Fqcn::same('App\\Foo', '\\\\App\\Foo'))->toBeTrue()
        ->and(Fqcn::same('', ''))->toBeTrue()
        ->and(Fqcn::same('App\\Foo', 'app\\foo'))->toBeFalse()
        ->and(Fqcn::same('App\\Foo', 'App\\Foo '))->toBeFalse()
        ->and(Fqcn::same('\\Closure', 'Closure'))->toBeTrue()
        ->and(Fqcn::same('Foo', 'App\\Foo'))->toBeFalse();
});

it('round-trips slugs', function (): void {
    expect(Fqcn::toSlug('App\\Events\\OrderPlaced'))->toBe('App.Events.OrderPlaced')
        ->and(Fqcn::fromSlug('App.Events.OrderPlaced'))->toBe('App\\Events\\OrderPlaced')
        ->and(Fqcn::toSlug('Foo'))->toBe('Foo');
});

it('splitMember splits at the last :: then the last @', function (string $in, ?array $out): void {
    expect(Fqcn::splitMember($in))->toBe($out);
})->with([
    'empty' => ['', null],
    'no separator' => ['App\\Foo', null],
    'static' => ['App\\Foo::bar', ['App\\Foo', 'bar']],
    'at' => ['App\\Foo@bar', ['App\\Foo', 'bar']],
    'static wins over at' => ['App\\Foo@x::bar', ['App\\Foo@x', 'bar']],
    'last static' => ['A::b::c', ['A::b', 'c']],
    'last at' => ['A@b@c', ['A@b', 'c']],
    'empty method static' => ['App\\Foo::', ['App\\Foo', '']],
    'empty method at' => ['App\\Foo@', ['App\\Foo', '']],
    'empty class static' => ['::bar', ['', 'bar']],
    'empty class at' => ['@bar', ['', 'bar']],
    'leading backslash kept' => ['\\App\\Foo@bar', ['\\App\\Foo', 'bar']],
    'whitespace kept' => ['App\\Foo :: bar ', ['App\\Foo ', ' bar ']],
]);

it('splitStaticMember splits only at the last ::', function (string $in, ?array $out): void {
    expect(Fqcn::splitStaticMember($in))->toBe($out);
})->with([
    'empty' => ['', null],
    'at is not split' => ['App\\Foo@bar', null],
    'static' => ['App\\Foo::bar', ['App\\Foo', 'bar']],
    'last static' => ['A::b::c', ['A::b', 'c']],
    'empty method' => ['App\\Foo::', ['App\\Foo', '']],
    'empty class' => ['::bar', ['', 'bar']],
    'at before static' => ['A@b::c', ['A@b', 'c']],
]);

it('splitAtMember splits only at the first @', function (string $in, ?array $out): void {
    expect(Fqcn::splitAtMember($in))->toBe($out);
})->with([
    'empty' => ['', null],
    'no at' => ['App\\Foo', null],
    'static is not split' => ['App\\Foo::bar', null],
    'at' => ['App\\Foo@bar', ['App\\Foo', 'bar']],
    'first at wins' => ['A@b@c', ['A', 'b@c']],
    'empty method' => ['App\\Foo@', ['App\\Foo', '']],
    'empty class' => ['@bar', ['', 'bar']],
    'class is verbatim' => ['\\App\\Foo@bar', ['\\App\\Foo', 'bar']],
    'static after at stays in method' => ['A@b::c', ['A', 'b::c']],
]);

/*
 * The three splitters disagree on purpose: each pins the rule one consumer
 * relied on before they were unified, so output stays identical.
 */
it('keeps the split rules distinct per consumer', function (): void {
    // Chain walker (index refs): last `::` first, then last `@`.
    expect(Fqcn::splitMember('A@b::c'))->toBe(['A@b', 'c'])
        // Chain graph (UI refs): `::` only, an `@` ref is a bare class.
        ->and(Fqcn::splitStaticMember('A@b'))->toBeNull()
        // Route / schedule / eloquent strings: first `@`, rest stays in the method.
        ->and(Fqcn::splitAtMember('A@b::c'))->toBe(['A', 'b::c']);
});
