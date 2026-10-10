<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\Visitors\CollectingVisitor;
use PhpParser\NodeVisitor;

/**
 * Visitors under src/ that may extend something other than CollectingVisitor,
 * keyed by FQCN. Every entry needs a reason and must still be a visitor.
 *
 * @var array<class-string, string>
 */
const COLLECTING_VISITOR_ALLOWLIST = [];

/** @return list<class-string<NodeVisitor>> concrete NodeVisitor implementations under src/ */
function srcVisitorClasses(): array
{
    $root = realpath(__DIR__.'/../../../src');
    $visitors = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! str_ends_with((string) $file, '.php')) {
            continue;
        }

        $relative = substr((string) $file, strlen($root) + 1, -4);
        $fqcn = 'Lucasp\\Loom\\'.str_replace('/', '\\', $relative);
        $class = new ReflectionClass($fqcn);

        if ($class->isInterface() || $class->isTrait() || $class->isAbstract()) {
            continue;
        }
        if ($class->implementsInterface(NodeVisitor::class)) {
            $visitors[] = $fqcn;
        }
    }

    return $visitors;
}

it('has visitors under src to check', function (): void {
    expect(srcVisitorClasses())->not->toBeEmpty();
});

it('makes every visitor under src extend CollectingVisitor', function (): void {
    foreach (srcVisitorClasses() as $fqcn) {
        if (array_key_exists($fqcn, COLLECTING_VISITOR_ALLOWLIST)) {
            continue;
        }

        expect(is_subclass_of($fqcn, CollectingVisitor::class))
            ->toBeTrue("{$fqcn} must extend CollectingVisitor so its state is reset before each traversal");
    }
});

it('keeps the CollectingVisitor allowlist free of stale entries', function (): void {
    $visitors = srcVisitorClasses();
    $stale = collect(COLLECTING_VISITOR_ALLOWLIST)
        ->filter(fn (string $reason, string $fqcn): bool => $reason === ''
            || ! in_array($fqcn, $visitors, true)
            || is_subclass_of($fqcn, CollectingVisitor::class))
        ->keys()
        ->all();

    expect($stale)->toBe([]);
});

it('does not let a visitor override beforeTraverse', function (): void {
    expect((new ReflectionMethod(CollectingVisitor::class, 'beforeTraverse'))->isFinal())->toBeTrue();
});
