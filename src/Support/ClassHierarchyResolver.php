<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Lucasp\Loom\Dto\MethodDeclaration;
use Lucasp\Loom\Dto\MethodVisibility;
use Lucasp\Loom\Dto\ResolvedMethod;
use Lucasp\Loom\Dto\TraitAdaptation;
use Lucasp\Loom\Scanners\Visitors\ClassDeclarationVisitor;

/**
 * Cross-file extends/implements/use-trait resolver. Lazy index under
 * the scan directories; vendor classes are opaque leaves.
 *
 * @internal
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
     *     isAbstract: bool,
     *     methods: list<MethodDeclaration>,
     *     adaptations: list<TraitAdaptation>,
     * }>
     */
    private array $index = [];

    /** @var array<string, array<string, ResolvedMethod>> */
    private array $methodsCache = [];

    /** @var array<string, true> classes whose methods are being resolved (cycle guard) */
    private array $resolving = [];

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

    public function __construct(string $appRoot, AstWalker $walker, ?ScanScope $scope = null)
    {
        $this->appRoot = $appRoot;
        $this->walker = $walker;
        $this->scope = $scope;
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

    /**
     * Methods a class exposes once traits and parents are folded in, keyed by
     * lower-cased name (PHP method names are case-insensitive). Precedence is
     * PHP's: own methods, then trait methods (honouring `as` / `insteadof`),
     * then inherited ones. A vendor parent is opaque, so its methods are absent.
     * Private methods of a parent are not inherited. Unknown FQCN gives `[]`.
     *
     * @return array<string, ResolvedMethod>
     */
    public function effectiveMethods(string $fqcn): array
    {
        $fqcn = $this->normalize($fqcn);
        if (isset($this->methodsCache[$fqcn])) {
            return $this->methodsCache[$fqcn];
        }

        $this->ensureIndexed();

        $decl = $this->index[$fqcn] ?? null;
        if ($decl === null || $decl['kind'] === 'interface' || isset($this->resolving[$fqcn])) {
            return [];
        }

        $this->resolving[$fqcn] = true;

        $inherited = [];
        if ($decl['kind'] === 'class' && $decl['parent'] !== null) {
            foreach ($this->effectiveMethods($decl['parent']) as $key => $method) {
                if ($method->visibility !== MethodVisibility::PRIVATE) {
                    $inherited[$key] = $method;
                }
            }
        }

        $methods = $inherited;
        foreach ($this->composedMethods($fqcn, $decl) as $key => $method) {
            // An abstract trait method never replaces a concrete inherited one.
            if ($method->isAbstract && isset($methods[$key]) && ! $methods[$key]->isAbstract) {
                continue;
            }
            $methods[$key] = $method;
        }

        unset($this->resolving[$fqcn]);

        return $this->methodsCache[$fqcn] = $methods;
    }

    /**
     * Whether `new $fqcn` could succeed as far as the source shows: a known
     * class that is not abstract. Interfaces, traits and unknown classes are not.
     */
    public function isInstantiable(string $fqcn): bool
    {
        $fqcn = $this->normalize($fqcn);
        $this->ensureIndexed();

        $decl = $this->index[$fqcn] ?? null;

        return $decl !== null && $decl['kind'] === 'class' && ! $decl['isAbstract'];
    }

    /**
     * Class names the method's first parameter accepts, with `self` and
     * `parent` resolved against the declaring class (as reflection does).
     *
     * @return list<string>
     */
    public function firstParameterClasses(ResolvedMethod $method): array
    {
        $classes = [];
        foreach ($method->firstParameterClasses as $name) {
            $name = $this->normalize($name);
            $resolved = match (strtolower($name)) {
                'self' => $method->declaredIn,
                'parent' => $this->index[$method->declaredIn]['parent'] ?? null,
                default => $name,
            };

            if ($resolved !== null && ! in_array($resolved, $classes, true)) {
                $classes[] = $resolved;
            }
        }

        return $classes;
    }

    public function knows(string $fqcn): bool
    {
        $fqcn = $this->normalize($fqcn);
        $this->ensureIndexed();

        return isset($this->index[$fqcn]);
    }

    /**
     * Trait methods folded in under the declaration's own methods.
     *
     * @param  array{kind: string, traits: list<string>, methods: list<MethodDeclaration>, adaptations: list<TraitAdaptation>}  $decl
     * @return array<string, ResolvedMethod>
     */
    private function composedMethods(string $fqcn, array $decl): array
    {
        /** @var array<string, array<string, ResolvedMethod>> $tables */
        $tables = [];
        foreach ($decl['traits'] as $trait) {
            $trait = $this->normalize($trait);
            $tables[$trait] = [];
            foreach ($this->effectiveMethods($trait) as $key => $method) {
                $tables[$trait][$key] = $method->declaredInto($fqcn);
            }
        }

        /** @var array<string, array<string, true>> $excluded */
        $excluded = [];
        foreach ($decl['adaptations'] as $adaptation) {
            foreach ($adaptation->insteadof as $loser) {
                $excluded[$loser][strtolower($adaptation->method)] = true;
            }
        }

        $methods = [];
        foreach ($tables as $trait => $table) {
            foreach ($table as $key => $method) {
                if (! isset($excluded[$trait][$key])) {
                    $methods[$key] = $method;
                }
            }
        }

        foreach ($decl['adaptations'] as $adaptation) {
            if ($adaptation->insteadof !== []) {
                continue;
            }

            $source = $this->adaptationSource($tables, $adaptation);
            if ($source === null) {
                continue;
            }

            $visibility = $adaptation->visibility ?? $source->visibility;
            if ($adaptation->alias !== null) {
                $methods[strtolower($adaptation->alias)] = $source->with($adaptation->alias, $visibility);
            } else {
                $methods[strtolower($source->name)] = $source->with($source->name, $visibility);
            }
        }

        foreach ($decl['methods'] as $own) {
            $methods[strtolower($own->name)] = new ResolvedMethod(
                $own->name,
                $own->visibility,
                $own->isAbstract,
                $own->hasParameters,
                $own->firstParameterClasses,
                $fqcn,
                $fqcn,
            );
        }

        return $methods;
    }

    /**
     * @param  array<string, array<string, ResolvedMethod>>  $tables
     */
    private function adaptationSource(array $tables, TraitAdaptation $adaptation): ?ResolvedMethod
    {
        $key = strtolower($adaptation->method);

        if ($adaptation->trait !== null) {
            return $tables[$this->normalize($adaptation->trait)][$key] ?? null;
        }

        foreach ($tables as $table) {
            if (isset($table[$key])) {
                return $table[$key];
            }
        }

        return null;
    }

    private function normalizeAdaptation(TraitAdaptation $adaptation): TraitAdaptation
    {
        return new TraitAdaptation(
            $adaptation->trait !== null ? $this->normalize($adaptation->trait) : null,
            $adaptation->method,
            $adaptation->alias,
            $adaptation->visibility,
            array_map($this->normalize(...), $adaptation->insteadof),
        );
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

        $visitor = new ClassDeclarationVisitor;

        foreach ($this->scanFiles($this->appRoot) as $file) {
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
                    'isAbstract' => $decl->isAbstract,
                    'methods' => $decl->methods,
                    'adaptations' => array_map($this->normalizeAdaptation(...), $decl->adaptations),
                ];
            }
        }
    }

    private function normalize(string $fqcn): string
    {
        return ltrim($fqcn, '\\');
    }
}
