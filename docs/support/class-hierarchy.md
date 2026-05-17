# Class hierarchy resolver

`Lucasp\Loom\Support\ClassHierarchyResolver` answers transitive `extends` /
`implements` / `use Trait` questions across files in `app/`. It is an internal
support service; scanners consume it, no JSON output references it.

For the rationale behind the load-bearing choices (opaque leaves, eager walk,
class-graph only), see [ADR 0001](../adr/0001-class-hierarchy-resolver.md).
This page documents *what the resolver does today*.

## Placement

- Class: `Lucasp\Loom\Support\ClassHierarchyResolver`
- File: `src/Support/ClassHierarchyResolver.php`
- Visitor: `src/Scanners/Visitors/ClassDeclarationVisitor.php`
- Constructed once per `IndexBuilder::build()` call against the scanned
  `$appRoot`, alongside `AstWalker` and `Psr4ClassLocator`. Inject into scanner
  constructors that need it; do not share across builds.

## Public API

```php
final class ClassHierarchyResolver
{
    public function __construct(string $appRoot, AstWalker $walker);

    /** Immediate-to-root parents. Stops at first unknown FQCN. */
    public function extendsChain(string $fqcn): array;          // list<string>

    /** Every interface implemented transitively (own + parents' + via interface extends). */
    public function implementsAll(string $fqcn): array;         // list<string>

    /** Every trait used transitively (own + parents' + traits used by traits). */
    public function traitsAll(string $fqcn): array;             // list<string>

    /** Convenience predicate: walks chain + interface graph + traits' implements. */
    public function implementsInterface(string $fqcn, string $interface): bool;

    /** Convenience predicate: extends-chain membership. */
    public function isSubclassOf(string $fqcn, string $ancestor): bool;

    /** True when the resolver has indexed a declaration for $fqcn. */
    public function knows(string $fqcn): bool;
}
```

## Return semantics

- All FQCNs are returned without leading `\`. Input FQCNs may carry a leading
  `\` and are normalised internally.
- Order is deterministic: depth-first, parent before its own parents,
  declaration order preserved within a level. `implementsAll` returns the
  direct interfaces first, then interfaces inherited from the parent class,
  then interfaces extended by interfaces, in encounter order.
- Duplicates are removed; the first occurrence wins.
- `extendsChain` on a class with no parent returns `[]`. On an unknown FQCN
  it also returns `[]` — use `knows()` to distinguish.
- `implementsAll($interfaceFqcn)` returns the interface-extends closure
  (including the interface itself). `traitsAll($traitFqcn)` returns the
  trait-use closure (including the trait itself).
- Cycles in the inheritance graph are handled: `A extends B, B extends A`
  yields `extendsChain(A) === ['B', 'A']` and terminates. PHP would fatal at
  runtime, but Loom must not loop.

## Indexing

Two phases:

1. **Declaration index (eager, single walk).** On first use, walk
   `$appRoot/app/**/*.php` once, parse each file via the shared `AstWalker` +
   `ClassDeclarationVisitor`, and store every `Stmt\Class_` /
   `Stmt\Interface_` / `Stmt\Trait_` with a `namespacedName`. Anonymous
   classes are skipped. Multiple declarations in one file are all captured.
2. **Resolution (lazy, memoised per query).** Each public method memoises its
   result per FQCN. Repeat calls hit the cache.

## External / vendor classes

Unknown FQCNs (anything not declared in `app/`) are *opaque leaves*: they
appear in the return list, but traversal stops at them. Callers match by
string. See [ADR 0001](../adr/0001-class-hierarchy-resolver.md) for the
rationale.

Example: a job `App\Jobs\SendInvoice extends App\Jobs\AbstractInvoiceJob
implements \Illuminate\Contracts\Queue\ShouldQueue`:

- `extendsChain('App\Jobs\SendInvoice')` → `['App\Jobs\AbstractInvoiceJob']`
- `implementsAll('App\Jobs\SendInvoice')` →
  `['Illuminate\Contracts\Queue\ShouldQueue']`
- `implementsInterface('App\Jobs\SendInvoice', '…\ShouldQueue')` → `true`
- `knows('App\Jobs\AbstractInvoiceJob')` → `true`
- `knows('Illuminate\Contracts\Queue\ShouldQueue')` → `false`

## Consumers

No scanner consumes the resolver as of this writing. Planned migrations:

- **#14 — `JobClassVisitor`** swaps its direct-implements loop for
  `$resolver->implementsInterface($fqcn, self::SHOULD_QUEUE)`. Preferred shape:
  `JobsScanner` enriches the visitor's per-class result post-walk so the
  visitor stays pure.
- **Listener auto-discovery via trait `handle()`** — needs method-level
  resolution; blocked on a follow-up that extends the index shape.
- **Observer hooks via parent class** — same blocker.

## Known limitations

- **No method-level resolution.** Currently only the class graph is indexed,
  so trait-provided `handle()` and parent-class observer hooks are still
  invisible. Tracked alongside their follow-ups.
- **`app/` only.** Class declarations under `routes/`, `database/`, or other
  Laravel directories are not indexed. No current consumer needs them.
- **No persistent cache.** The index is rebuilt each `IndexBuilder::build()`.
- **`ClassDeclaration` records are not exposed publicly.** Future consumers
  that need `kind` / `file` / `line` will require a small API addition.
