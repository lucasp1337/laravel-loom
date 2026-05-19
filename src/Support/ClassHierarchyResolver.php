<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Lucasp\Loom\Scanners\Visitors\ClassDeclarationVisitor;

/**
 * Cross-file extends/implements/use-trait resolver. Lazy index under
 * `$appRoot/app/`; vendor classes are opaque leaves.
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
     * Immediate-to-root parents. An unknown FQCN is included as an opaque
     * leaf, then traversal stops.
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

            if (! isset($this->index[$parent])) {
                break;
            }

            $current = $parent;
        }

        return $this->extendsChainCache[$fqcn] = $chain;
    }

    /**
     * Transitive interfaces; depth-first, first occurrence wins on dedup.
     * On an interface FQCN, returns the interface-extends closure.
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
            $this->expandClosure($fqcn, 'interface', 'parents', $result, $seen);
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
                foreach ($decl['interfaces'] as $iface) {
                    $this->expandClosure($this->normalize($iface), 'interface', 'parents', $result, $seen);
                }
            }
        }

        return $this->implementsAllCache[$fqcn] = $result;
    }

    /**
     * Transitive traits; same ordering as implementsAll.
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

    public function knows(string $fqcn): bool
    {
        $fqcn = $this->normalize($fqcn);
        $this->ensureIndexed();

        return isset($this->index[$fqcn]);
    }

    /**
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
            return;
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
            // walk()===null skips beforeTraverse; visitor would leak prior state.
            if ($this->walker->walk($absolute, [$visitor]) === null) {
                continue;
            }

            $relative = $this->relativePath($this->appRoot, $absolute);
            foreach ($visitor->getDeclarations() as $decl) {
                $this->index[$decl->fqcn] = [
                    'fqcn' => $decl->fqcn,
                    'kind' => $decl->kind,
                    'parent' => $decl->parent !== null ? $this->normalize($decl->parent) : null,
                    'parents' => array_map([$this, 'normalize'], $decl->parents),
                    'interfaces' => array_map([$this, 'normalize'], $decl->interfaces),
                    'traits' => array_map([$this, 'normalize'], $decl->traits),
                    'file' => $relative,
                    'line' => $decl->line,
                ];
            }
        }
    }

    private function normalize(string $fqcn): string
    {
        return ltrim($fqcn, '\\');
    }
}
