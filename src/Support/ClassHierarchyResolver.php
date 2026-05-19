<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Lucasp\Loom\Scanners\Visitors\ClassDeclarationVisitor;

/**
 * Cross-file `extends` / `implements` / `use Trait` resolver.
 *
 * Shared support service for scanners that need transitive class-hierarchy
 * information (e.g. flagging a job as queued when `ShouldQueue` is declared
 * on an abstract parent). The resolver is constructed once per
 * `IndexBuilder::build()` call, bound to a single `$appRoot`.
 *
 * Indexing strategy: a single filesystem walk under `$appRoot/app/` (lazy,
 * triggered on first public call, memoised) parses every `.php` file via
 * the shared `AstWalker` and a `ClassDeclarationVisitor`, producing a flat
 * map of FQCN -> declaration record. Resolution methods walk this map.
 *
 * External / vendor classes are opaque leaves: when traversal encounters an
 * FQCN not in the index, the resolver records it in the return list and
 * stops traversing further up that branch. See
 * `docs/support/class-hierarchy.md` for the public contract and
 * `docs/adr/0001-class-hierarchy-resolver.md` for the rationale.
 *
 * All FQCNs are returned without a leading backslash.
 */
final class ClassHierarchyResolver
{
    use ScannerFilesystem;

    private string $appRoot;

    private AstWalker $walker;

    private bool $indexed = false;

    /**
     * @var array<string, array{
     *     fqcn: string,
     *     kind: 'class'|'interface'|'trait',
     *     parent: ?string,
     *     parents: list<string>,
     *     interfaces: list<string>,
     *     traits: list<string>,
     *     file: string,
     *     line: int,
     * }>
     */
    private array $index = [];

    /** @var array<string, list<string>> */
    private array $extendsChainCache = [];

    /** @var array<string, list<string>> */
    private array $implementsAllCache = [];

    /** @var array<string, list<string>> */
    private array $traitsAllCache = [];

    /** @var array<string, array<string, bool>> */
    private array $implementsInterfaceCache = [];

    /** @var array<string, array<string, bool>> */
    private array $isSubclassOfCache = [];

    public function __construct(string $appRoot, AstWalker $walker)
    {
        $this->appRoot = $appRoot;
        $this->walker = $walker;
    }

    /**
     * Immediate-to-root parents. Stops at first unknown FQCN (which is
     * still included in the returned list as an opaque leaf).
     *
     * @return list<string>
     */
    public function extendsChain(string $fqcn): array
    {
        $fqcn = $this->normalize($fqcn);
        if (array_key_exists($fqcn, $this->extendsChainCache)) {
            return $this->extendsChainCache[$fqcn];
        }

        $this->ensureIndexed();

        $chain = [];
        $visited = [];
        $current = $fqcn;

        while (true) {
            if (! isset($this->index[$current])) {
                // Either the starting FQCN is unknown (return []) or we hit
                // an opaque leaf during traversal — we only reach the latter
                // by following a parent edge, which has already been pushed.
                break;
            }

            $decl = $this->index[$current];
            if ($decl['kind'] !== 'class') {
                break;
            }

            $parent = $decl['parent'];
            if ($parent === null) {
                break;
            }
            $parent = $this->normalize($parent);

            if (isset($visited[$parent])) {
                break; // cycle
            }
            $visited[$parent] = true;

            $chain[] = $parent;

            // Opaque leaf — record but stop.
            if (! isset($this->index[$parent])) {
                break;
            }

            $current = $parent;
        }

        return $this->extendsChainCache[$fqcn] = $chain;
    }

    /**
     * Every interface implemented transitively: own interfaces + parents'
     * interfaces + interface-extends closure. Order is deterministic:
     * depth-first, parent before grandparent, declaration order preserved
     * within a level, first occurrence wins on dedup.
     *
     * Also useful on an interface FQCN: returns the interface-extends
     * closure.
     *
     * @return list<string>
     */
    public function implementsAll(string $fqcn): array
    {
        $fqcn = $this->normalize($fqcn);
        if (array_key_exists($fqcn, $this->implementsAllCache)) {
            return $this->implementsAllCache[$fqcn];
        }

        $this->ensureIndexed();

        /** @var list<string> $result */
        $result = [];
        /** @var array<string, bool> $seen */
        $seen = [];

        if (isset($this->index[$fqcn]) && $this->index[$fqcn]['kind'] === 'interface') {
            // Interface input: closure over its `extends` parents.
            $this->expandClosure($fqcn, 'interface', 'parents', $result, $seen);
        } else {
            // Class input (known or unknown): direct interfaces + parents' interfaces.
            $chain = [$fqcn];
            foreach ($this->extendsChain($fqcn) as $ancestor) {
                $chain[] = $ancestor;
            }

            foreach ($chain as $classFqcn) {
                if (! isset($this->index[$classFqcn])) {
                    continue;
                }
                $decl = $this->index[$classFqcn];
                if ($decl['kind'] !== 'class') {
                    continue;
                }
                foreach ($decl['interfaces'] as $iface) {
                    $this->expandClosure($this->normalize($iface), 'interface', 'parents', $result, $seen);
                }
            }
        }

        return $this->implementsAllCache[$fqcn] = $result;
    }

    /**
     * Every trait used transitively: own traits + parents' traits + traits
     * used by traits. Same ordering rules as `implementsAll`. Also useful
     * on a trait FQCN: returns the trait-use closure.
     *
     * @return list<string>
     */
    public function traitsAll(string $fqcn): array
    {
        $fqcn = $this->normalize($fqcn);
        if (array_key_exists($fqcn, $this->traitsAllCache)) {
            return $this->traitsAllCache[$fqcn];
        }

        $this->ensureIndexed();

        /** @var list<string> $result */
        $result = [];
        /** @var array<string, bool> $seen */
        $seen = [];

        if (isset($this->index[$fqcn]) && $this->index[$fqcn]['kind'] === 'trait') {
            // Trait input: closure over its own used traits.
            $this->expandClosure($fqcn, 'trait', 'traits', $result, $seen);
        } else {
            $chain = [$fqcn];
            foreach ($this->extendsChain($fqcn) as $ancestor) {
                $chain[] = $ancestor;
            }

            foreach ($chain as $classFqcn) {
                if (! isset($this->index[$classFqcn])) {
                    continue;
                }
                $decl = $this->index[$classFqcn];
                if ($decl['kind'] !== 'class') {
                    continue;
                }
                foreach ($decl['traits'] as $trait) {
                    $this->expandClosure($this->normalize($trait), 'trait', 'traits', $result, $seen);
                }
            }
        }

        return $this->traitsAllCache[$fqcn] = $result;
    }

    /**
     * Convenience predicate: walks chain + interface graph + traits'
     * implements. Matches succeed when the interface appears anywhere in
     * the transitive set, even if the resolver has no declaration for the
     * interface itself — the caller's string is authoritative.
     */
    public function implementsInterface(string $fqcn, string $interface): bool
    {
        $fqcn = $this->normalize($fqcn);
        $interface = $this->normalize($interface);

        if (isset($this->implementsInterfaceCache[$fqcn][$interface])) {
            return $this->implementsInterfaceCache[$fqcn][$interface];
        }

        $found = in_array($interface, $this->implementsAll($fqcn), true);

        return $this->implementsInterfaceCache[$fqcn][$interface] = $found;
    }

    /**
     * Convenience predicate: extends-chain membership.
     */
    public function isSubclassOf(string $fqcn, string $ancestor): bool
    {
        $fqcn = $this->normalize($fqcn);
        $ancestor = $this->normalize($ancestor);

        if (isset($this->isSubclassOfCache[$fqcn][$ancestor])) {
            return $this->isSubclassOfCache[$fqcn][$ancestor];
        }

        $found = in_array($ancestor, $this->extendsChain($fqcn), true);

        return $this->isSubclassOfCache[$fqcn][$ancestor] = $found;
    }

    /**
     * True when the resolver has indexed a declaration for $fqcn.
     */
    public function knows(string $fqcn): bool
    {
        $fqcn = $this->normalize($fqcn);
        $this->ensureIndexed();

        return isset($this->index[$fqcn]);
    }

    /**
     * Depth-first closure over a single edge type ('parents' for interfaces,
     * 'traits' for traits). $seen doubles as cycle guard and dedup map.
     *
     * @param  'interface'|'trait'  $expectKind
     * @param  'parents'|'traits'  $edgeField
     * @param  list<string>  $result
     * @param  array<string, bool>  $seen
     */
    private function expandClosure(string $fqcn, string $expectKind, string $edgeField, array &$result, array &$seen): void
    {
        if (isset($seen[$fqcn])) {
            return;
        }
        $seen[$fqcn] = true;
        $result[] = $fqcn;

        if (! isset($this->index[$fqcn])) {
            return; // opaque leaf
        }

        $decl = $this->index[$fqcn];
        if ($decl['kind'] !== $expectKind) {
            return;
        }

        foreach ($decl[$edgeField] as $neighbour) {
            $this->expandClosure($this->normalize($neighbour), $expectKind, $edgeField, $result, $seen);
        }
    }

    private function ensureIndexed(): void
    {
        if ($this->indexed) {
            return;
        }
        $this->indexed = true;

        $appDir = $this->appRoot.DIRECTORY_SEPARATOR.'app';
        if (! is_dir($appDir)) {
            return;
        }

        $visitor = new ClassDeclarationVisitor;

        foreach ($this->iteratePhpFiles($appDir) as $file) {
            $absolute = $file->getPathname();
            if ($this->walker->walk($absolute, [$visitor]) === null) {
                // File could not be read or parsed; the visitor's
                // `beforeTraverse` was never invoked, so its state still
                // reflects the previous file. Skip — do not mis-attribute
                // the previous file's declarations to this path.
                continue;
            }

            $relative = $this->relativePath($this->appRoot, $absolute);
            foreach ($visitor->getDeclarations() as $decl) {
                $this->index[$decl['fqcn']] = [
                    'fqcn' => $decl['fqcn'],
                    'kind' => $decl['kind'],
                    'parent' => $decl['parent'] !== null ? $this->normalize($decl['parent']) : null,
                    'parents' => array_map([$this, 'normalize'], $decl['parents']),
                    'interfaces' => array_map([$this, 'normalize'], $decl['interfaces']),
                    'traits' => array_map([$this, 'normalize'], $decl['traits']),
                    'file' => $relative,
                    'line' => $decl['line'],
                ];
            }
        }
    }

    private function normalize(string $fqcn): string
    {
        return ltrim($fqcn, '\\');
    }
}
