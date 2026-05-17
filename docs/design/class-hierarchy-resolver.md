# ClassHierarchyResolver — design

Internal design note for issue #13. Audience: `ast-specialist` (implementer),
`test-engineer` (test plan). Not user-facing.

## Why

Multiple scanners need transitive `extends` / `implements` / `use Trait`
information but currently inspect only direct declarations on the class node.
Documented gaps that all collapse onto the same missing primitive:

- `JobClassVisitor` flags `queued` only when `ShouldQueue` is in the direct
  `implements` list. Abstract-parent indirection is invisible (#14).
- `ListenerScanner` auto-discovery on `handle()` misses listeners that inherit
  `handle()` from a trait or parent.
- `ObserverScanner` reports only hooks declared directly on the observer class.

The resolver is a **shared support service** under `src/Support/`. It is not a
scanner, emits no schema sections, and produces no JSON. Scanners call it.

## Placement & wiring

- Class: `Lucasp\Loom\Support\ClassHierarchyResolver`
- File: `src/Support/ClassHierarchyResolver.php`
- Constructed once per `IndexBuilder::build()` call, bound to a single
  `$appRoot`. Pass it into scanners that need it via constructor injection
  (mirrors the existing `Psr4ClassLocator` / `AstWalker` pattern).
- `IndexBuilder` is responsible for instantiation. In this PR, instantiate and
  inject only — no scanner consumes it yet (see "Consumer migration" below).

The resolver is stateful (it caches the index) but bound to one `$appRoot`,
so it has the same lifecycle as the build. Do not make it a singleton across
builds.

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

Return-array rules:

- All FQCNs returned without leading `\`.
- Order: deterministic — depth-first, parent before its own parents,
  declaration order preserved within a level (so `implementsAll` returns
  direct interfaces first, then interfaces inherited from the parent class,
  then interfaces extended by interfaces, in encounter order).
- Duplicates removed; first occurrence wins.
- `extendsChain` on a class with no parent returns `[]`. On an unknown FQCN
  returns `[]`. Callers distinguish "unknown" via `knows()`.

Method-level resolution (`definesMethod`, `methodOrigin`) is explicitly **not**
in this PR — see "Out of scope".

## Indexing strategy

Two-phase, as the issue specifies.

**Phase 1 — declaration index (eager, single walk).**

On first use (lazy init in any public method), walk `$appRoot/app/**/*.php`
via `RecursiveDirectoryIterator`, parse each file through `AstWalker` with a
single `ClassDeclarationVisitor`, and build:

```
array<string fqcn, ClassDeclaration{
    fqcn:       string,
    kind:       'class' | 'interface' | 'trait',
    parent:     ?string,            // single FQCN; null for interfaces, traits, root classes
    parents:    list<string>,       // interface-extends only (interfaces can extend many)
    interfaces: list<string>,       // implements list (classes only)
    traits:     list<string>,       // use-trait list (classes + traits)
    file:       string,
    line:       int,
}>
```

The visitor reads `Stmt\Class_`, `Stmt\Interface_`, and `Stmt\Trait_` on
`leaveNode` (NameResolver convention) and uses `$node->namespacedName`.
Anonymous classes (no `namespacedName`) are skipped. Multiple declarations in
one file are all captured.

The index is lazy-built once and memoised on the instance. Subsequent calls
hit the in-memory map.

**Phase 2 — resolution (lazy, memoised per query).**

Graph traversal over the index. Each public method memoises its own result
keyed by FQCN to keep repeated calls cheap (scanners will hit the same FQCNs
many times during a build).

Cycle protection: maintain a visited-set in every traversal so a malformed
`A extends B extends A` (legal-ish via separate files; PHP itself would
fatal at runtime) does not loop forever. On cycle, stop and return what we
have.

**Why eager-index, not on-demand per-FQCN.** `Psr4ClassLocator` only handles
the `App\` -> `app/` default. Real apps use `composer.json` PSR-4 maps,
non-App roots, multiple roots, and `classmap` entries. We do not want the
resolver to depend on Composer's autoload. A single filesystem walk that
records `namespacedName -> file` is more honest about what Loom can actually
see, and removes the FQCN-to-path guessing entirely. Cost is one extra parse
of every file in `app/`, which Loom already does across its scanners — see
"Walk sharing".

**Walk sharing (deferred).** In principle, scanners and the resolver could
share a single file walk. They do not today, and threading a shared walk
through every scanner is invasive. Out of scope for this PR. The resolver
performs its own walk; this is acceptable overhead and we can collapse it
later if profiling demands.

## External / vendor classes

**Treat unknown FQCNs as opaque leaves.** When traversal encounters an FQCN
not in the index (vendor classes, framework contracts, anything outside
`app/`), the resolver:

1. Records the FQCN in the relevant return list (so the caller sees it).
2. Stops traversing further up that branch.

So for a job `App\Jobs\SendInvoice` extending `App\Jobs\AbstractInvoiceJob`
which implements `Illuminate\Contracts\Queue\ShouldQueue`:

- `extendsChain('App\Jobs\SendInvoice')` returns
  `['App\Jobs\AbstractInvoiceJob']`. Stops there — the parent has no known
  parent in the index.
- `implementsAll('App\Jobs\SendInvoice')` returns
  `['Illuminate\Contracts\Queue\ShouldQueue']` (gathered from the abstract
  parent's `implements`).
- `implementsInterface('App\Jobs\SendInvoice', 'Illuminate\Contracts\Queue\ShouldQueue')`
  returns `true`.

This is the cleanest of the three options laid out in the brief: callers
match by string (which they already do — see `JobClassVisitor::SHOULD_QUEUE`)
and the resolver does not need a Laravel-interface allowlist. No vendor
scanning, no reflection, no special cases.

Implication for `implementsInterface`: matches succeed when the interface
appears anywhere in the transitive set — even if the resolver has no
declaration for the interface itself. We do **not** try to verify the
interface exists. The caller's string is authoritative.

## Resolution semantics

Three relations, all transitive:

- **`extends` (classes).** Single parent. Walk parent -> grandparent -> root
  until null or unknown.
- **`extends` (interfaces).** Multiple parents (`interface A extends B, C`).
  Walk breadth-first; flatten.
- **`implements` (classes).** Multiple direct interfaces. Each direct
  interface is then expanded via interface-extends. Plus: every interface
  on every class in `extendsChain`, similarly expanded.
- **`use Trait` (classes and traits).** Multiple. Each trait may itself
  `use` other traits. Plus: every trait on every class in `extendsChain`.

`implementsAll` collects: direct interfaces of `$fqcn` + interfaces of every
class in `extendsChain($fqcn)` + interface-extends closure over all of those.

Edge case — interfaces don't `use` traits and traits don't `extends`. The
implementation should ignore those impossible edges (and PHP-Parser won't
produce them anyway).

Edge case — `implementsAll` on an interface FQCN returns the interface-extends
closure. `traitsAll` on a trait FQCN returns the trait-use closure. This is
useful and free; document it.

## Out of scope for this PR

Be explicit; these are tempting and they all blow the scope:

- **Method-level resolution.** No `definesMethod`, no `methodOrigin`. The
  listener-auto-discovery-via-trait fix needs this, but it can land as a
  follow-up that adds a `definesMethod(string $fqcn, string $method): bool`
  built on top of the same declaration index (extend `ClassDeclaration` with
  a `methods: list<string>` and a `traitMethods` map at index time).
- **Vendor / framework scanning.** No parsing of `vendor/`. Unknown FQCNs
  stay opaque.
- **Anonymous classes.** Skipped at index time.
- **Composer PSR-4 / classmap parsing.** Filesystem walk only.
- **Non-`app/` roots.** v1 walks `app/`. If real consumers need
  `routes/`, `database/`, etc., we widen later — but scanners that need
  hierarchy resolution (`JobsScanner`, `ListenerScanner`, `ObserverScanner`)
  all already scan `app/`.
- **Caching across builds.** Index is rebuilt each `IndexBuilder::build()`.
  Persistent caching is a separate concern.

## Consumer migration plan

**This PR:**

1. Add `ClassHierarchyResolver` + `ClassDeclarationVisitor`.
2. Unit tests over fixtures covering: extends chain, interface extends,
   trait-of-trait, multi-class file, cycle, unknown leaf, anonymous-class
   skip.
3. Wire into `IndexBuilder` construction (instantiate, pass into existing
   scanner constructors as a nullable param OR via a setter — implementer's
   call, but do NOT change scanner behavior in this PR).
4. No schema changes. No output changes.

**Follow-up PRs (one per consumer, each trivial once the resolver exists):**

- **#14 — `JobClassVisitor`.** Replace the direct-implements loop with
  `$resolver->implementsInterface($fqcn, self::SHOULD_QUEUE)`. The visitor
  becomes stateful on the resolver; alternatively, `JobsScanner` enriches
  each visitor result post-walk so the visitor stays pure. Prefer the latter
  — keeps visitors single-concern.
- **Listener auto-discovery via trait `handle()`.** Needs the
  `definesMethod` follow-up; do not start until that lands.
- **Observer hooks via parent class.** Same — needs `definesMethod`.

Each follow-up cites this design doc and updates the relevant scanner doc's
"Known limitations" section.

## Test plan outline (for `test-engineer`)

Fixtures under `tests/Fixtures/ClassHierarchy/`:

1. `LinearExtends/` — `A extends B extends C`. Assert chain order.
2. `IndirectInterface/` — `Concrete extends Abstract implements ShouldQueue`.
   Assert `implementsInterface(Concrete, ShouldQueue)` is `true`.
3. `InterfaceExtends/` — `interface A extends B, C; interface B extends D`.
   Assert `implementsAll` reachable from a class implementing `A`.
4. `TraitOfTrait/` — `class X use T1; trait T1 use T2;`. Assert `traitsAll`
   includes both.
5. `TraitOnParent/` — parent class uses trait; child does not. Assert
   `traitsAll(child)` includes the trait.
6. `MultiClassFile/` — two classes in one file. Both indexed.
7. `Cycle/` — `A extends B`, `B extends A` (two files). Assert no infinite
   loop; both appear in each other's `extendsChain` once.
8. `UnknownParent/` — `class X extends \Vendor\Y`. Assert
   `extendsChain(X) === ['Vendor\\Y']` and traversal stops.
9. `AnonymousClass/` — `new class extends Foo {}` inside a function.
   Assert the anonymous class is not in the index but `Foo` is.

Unit tests live in `tests/Unit/Support/ClassHierarchyResolverTest.php`.
No feature-level tests in this PR — no scanner consumes the resolver yet.

## Open items

- **Single-class-per-build memoisation of `implementsInterface` etc.** Worth
  it given scanners hit the same FQCN many times. Implementer choice on
  exact memoisation granularity; do not over-engineer (a flat
  `array<string, array<string, bool>>` keyed by `[fqcn][interface]` is
  enough).
- **Whether the resolver should expose the raw `ClassDeclaration` records.**
  Pro: future scanners may want `kind` / `file` / `line`. Con: leaks the
  internal shape. Recommendation: keep private for v1; promote to public if
  a real consumer needs it. The named methods cover today's needs.
