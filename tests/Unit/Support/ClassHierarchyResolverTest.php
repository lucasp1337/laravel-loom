<?php

declare(strict_types=1);

use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ClassHierarchyResolver;

/**
 * Absolute path to a class-hierarchy fixture root.
 */
function classHierarchyFixturePath(string $name): string
{
    return dirname(__DIR__, 2).'/Fixtures/ClassHierarchy/'.$name;
}

/**
 * Build a fresh resolver bound to the named fixture root.
 */
function makeClassHierarchyResolver(string $fixture): ClassHierarchyResolver
{
    return new ClassHierarchyResolver(classHierarchyFixturePath($fixture), new AstWalker);
}

it('returns the extends chain in parent-to-root order for linear extends', function () {
    $resolver = makeClassHierarchyResolver('LinearExtends');

    expect($resolver->extendsChain('App\\A'))->toBe(['App\\B', 'App\\C']);
    expect($resolver->extendsChain('App\\B'))->toBe(['App\\C']);
    expect($resolver->extendsChain('App\\C'))->toBe([]);

    expect($resolver->isSubclassOf('App\\A', 'App\\C'))->toBeTrue();
    expect($resolver->isSubclassOf('App\\C', 'App\\A'))->toBeFalse();

    expect($resolver->knows('App\\A'))->toBeTrue();
    expect($resolver->knows('App\\Nope'))->toBeFalse();
});

it('detects an interface implemented by an ancestor class', function () {
    $resolver = makeClassHierarchyResolver('IndirectInterface');

    expect($resolver->extendsChain('App\\Concrete'))->toBe(['App\\AbstractJob']);

    expect($resolver->implementsAll('App\\Concrete'))
        ->toBe(['Illuminate\\Contracts\\Queue\\ShouldQueue']);

    expect($resolver->implementsInterface('App\\Concrete', 'Illuminate\\Contracts\\Queue\\ShouldQueue'))
        ->toBeTrue();

    // ShouldQueue is opaque (no declaration in fixture).
    expect($resolver->knows('Illuminate\\Contracts\\Queue\\ShouldQueue'))->toBeFalse();
    expect($resolver->knows('App\\AbstractJob'))->toBeTrue();
});

it('expands interface extends transitively from a class implements', function () {
    $resolver = makeClassHierarchyResolver('InterfaceExtends');

    // Direct interface first, then interface-extends in declaration order
    // (IA extends IB, IC; IB extends ID).
    expect($resolver->implementsAll('App\\Impl'))
        ->toBe(['App\\IA', 'App\\IB', 'App\\ID', 'App\\IC']);

    expect($resolver->implementsInterface('App\\Impl', 'App\\ID'))->toBeTrue();
    expect($resolver->implementsInterface('App\\Impl', 'App\\IC'))->toBeTrue();
    expect($resolver->implementsInterface('App\\Impl', 'App\\IA'))->toBeTrue();

    // implementsAll on an interface returns the interface-extends closure,
    // including the interface itself (closure includes self — consistent with
    // implementsInterface(IA, IA) being trivially true).
    expect($resolver->implementsAll('App\\IA'))->toBe(['App\\IA', 'App\\IB', 'App\\ID', 'App\\IC']);
});

it('includes traits used by traits in traitsAll', function () {
    $resolver = makeClassHierarchyResolver('TraitOfTrait');

    expect($resolver->traitsAll('App\\X'))->toBe(['App\\T1', 'App\\T2']);

    // traitsAll on a trait FQCN returns the trait-use closure, including the
    // trait itself.
    expect($resolver->traitsAll('App\\T1'))->toBe(['App\\T1', 'App\\T2']);
    expect($resolver->traitsAll('App\\T2'))->toBe(['App\\T2']);
});

it('gathers traits from parent classes', function () {
    $resolver = makeClassHierarchyResolver('TraitOnParent');

    expect($resolver->traitsAll('App\\Child'))->toBe(['App\\SharedTrait']);
    expect($resolver->traitsAll('App\\Parental'))->toBe(['App\\SharedTrait']);
    expect($resolver->extendsChain('App\\Child'))->toBe(['App\\Parental']);
});

it('indexes every class declared in a multi-class file', function () {
    $resolver = makeClassHierarchyResolver('MultiClassFile');

    expect($resolver->knows('App\\First'))->toBeTrue();
    expect($resolver->knows('App\\Second'))->toBeTrue();

    expect($resolver->extendsChain('App\\Second'))->toBe(['App\\First']);
    expect($resolver->extendsChain('App\\First'))->toBe([]);
});

it('terminates traversal when the extends graph contains a cycle', function () {
    $resolver = makeClassHierarchyResolver('Cycle');

    // Cycle protection: walk pushes B then A and short-circuits on the second
    // sight of the start. Each cycle member appears once; no infinite loop.
    expect($resolver->extendsChain('App\\A'))->toBe(['App\\B', 'App\\A']);
    expect($resolver->extendsChain('App\\B'))->toBe(['App\\A', 'App\\B']);

    expect($resolver->isSubclassOf('App\\A', 'App\\B'))->toBeTrue();
    expect($resolver->isSubclassOf('App\\B', 'App\\A'))->toBeTrue();
});

it('stops the extends chain at an unknown parent FQCN', function () {
    $resolver = makeClassHierarchyResolver('UnknownParent');

    expect($resolver->extendsChain('App\\X'))->toBe(['Vendor\\Y']);
    expect($resolver->knows('App\\X'))->toBeTrue();
    expect($resolver->knows('Vendor\\Y'))->toBeFalse();

    // Convenience predicates work against the opaque leaf by string.
    expect($resolver->isSubclassOf('App\\X', 'Vendor\\Y'))->toBeTrue();
});

it('skips anonymous classes at index time', function () {
    $resolver = makeClassHierarchyResolver('AnonymousClass');

    expect($resolver->knows('App\\Bar'))->toBeTrue();
    expect($resolver->extendsChain('App\\Bar'))->toBe([]);

    // The anonymous class has no namespacedName; it must not appear in any
    // form in the index.
    expect($resolver->knows('App\\class@anonymous'))->toBeFalse();
    expect($resolver->knows('class@anonymous'))->toBeFalse();
});

it('returns an empty extends chain for an unknown FQCN', function () {
    $resolver = makeClassHierarchyResolver('LinearExtends');

    expect($resolver->extendsChain('App\\DoesNotExist'))->toBe([]);
    expect($resolver->implementsAll('App\\DoesNotExist'))->toBe([]);
    expect($resolver->traitsAll('App\\DoesNotExist'))->toBe([]);
    expect($resolver->knows('App\\DoesNotExist'))->toBeFalse();
});

it('strips leading backslashes from input FQCNs', function () {
    $resolver = makeClassHierarchyResolver('LinearExtends');

    expect($resolver->extendsChain('\\App\\A'))->toBe(['App\\B', 'App\\C']);
    expect($resolver->knows('\\App\\A'))->toBeTrue();
});

it('layers own methods over trait methods over inherited ones', function () {
    $resolver = makeClassHierarchyResolver('Methods');

    $parented = $resolver->effectiveMethods('App\\Parented');
    expect($parented['shared']->definedIn)->toBe('App\\Loud');
    expect($parented['shared']->declaredIn)->toBe('App\\Parented');
    expect($parented['musthave']->definedIn)->toBe('App\\Parented');
    expect($parented['musthave']->isAbstract)->toBeFalse();

    expect($resolver->effectiveMethods('App\\Overriding')['shared']->definedIn)->toBe('App\\Overriding');
    expect($resolver->effectiveMethods('App\\Grand')['shared']->definedIn)->toBe('App\\Grand');
});

it('does not inherit private parent methods', function () {
    $resolver = makeClassHierarchyResolver('Methods');

    expect($resolver->effectiveMethods('App\\Grand'))->toHaveKey('secret');
    expect($resolver->effectiveMethods('App\\Parented'))->not->toHaveKey('secret');
});

it('applies trait visibility changes and aliases', function () {
    $resolver = makeClassHierarchyResolver('Methods');
    $methods = $resolver->effectiveMethods('App\\Hidden');

    expect($methods['shared']->isPublic())->toBeFalse();
    expect($methods['viatrait']->isPublic())->toBeTrue();
    expect($methods['renamed']->name)->toBe('renamed');
    expect($methods['renamed']->isPublic())->toBeTrue();
});

it('resolves self in a parameter type against the declaring class', function () {
    $resolver = makeClassHierarchyResolver('Methods');

    $fromParent = $resolver->effectiveMethods('App\\Parented')['viaparent'];
    $fromTrait = $resolver->effectiveMethods('App\\Parented')['viatrait'];

    expect($resolver->firstParameterClasses($fromParent))->toBe(['App\\Grand']);
    expect($resolver->firstParameterClasses($fromTrait))->toBe(['App\\Parented']);
});

it('reports only concrete known classes as instantiable', function () {
    $resolver = makeClassHierarchyResolver('Methods');

    expect($resolver->isInstantiable('App\\Parented'))->toBeTrue();
    expect($resolver->isInstantiable('App\\Loud'))->toBeFalse();
    expect($resolver->isInstantiable('Vendor\\Unknown'))->toBeFalse();
});

it('terminates on an inheritance cycle', function () {
    $resolver = makeClassHierarchyResolver('Methods');

    expect($resolver->effectiveMethods('App\\CycleA'))->toBe([]);
});
