# ADR 0001 — ClassHierarchyResolver: opaque leaves, eager walk, class-graph only

**Status**: Accepted (2026-05-17)

**Reference**: [`docs/support/class-hierarchy.md`](../support/class-hierarchy.md)
for the public API and current behaviour. This ADR captures only the
load-bearing decisions.

## Context

Several documented gaps in Loom collapse onto the same missing primitive — the
inability to follow `extends` / `implements` / `use Trait` across files:

- Jobs that inherit `ShouldQueue` from an abstract parent are reported as
  unqueued (issue #14).
- Listeners that get `handle()` from a trait are missed by auto-discovery.
- Observers that inherit hooks from a parent class are reported with only the
  hooks declared directly.

We need a cross-file resolver. Three design questions had real forks worth
pinning before the implementation hardens:

1. How do we handle classes outside the indexed source (vendor, framework)?
2. How do we map FQCNs to source files?
3. What level of resolution do we support — class graph, method graph, or both?

## Decision

### 1. External classes are opaque leaves

When traversal hits an FQCN the resolver hasn't indexed (anything in `vendor/`,
framework contracts, traits provided by Laravel itself), it records the FQCN in
the relevant return list and stops traversing that branch. The resolver does
not parse `vendor/`, does not use reflection, and does not maintain an
allowlist of known Laravel interfaces.

Callers match unresolved FQCNs by string. `implementsInterface($fqcn,
'Illuminate\Contracts\Queue\ShouldQueue')` succeeds whenever that exact string
appears anywhere in the transitive set — the resolver makes no claim about
whether the interface actually exists.

### 2. Eager filesystem walk, not Composer/PSR-4 driven

On first use, the resolver walks `$appRoot/app/**/*.php` once, parses each
file, and records every class/interface/trait by its resolved `namespacedName`.
The resolver does not read `composer.json`, does not use Composer's autoload
maps, and does not depend on `Psr4ClassLocator`.

### 3. Class graph only — no method-level resolution in v1

`extendsChain`, `implementsAll`, `traitsAll`, `implementsInterface`,
`isSubclassOf`, `knows`. No `definesMethod`, no `methodOrigin`. The
listener-via-trait and observer-via-parent fixes need method resolution; they
land in a follow-up ADR that extends the index shape.

## Consequences

**Good:**

- No vendor parse cost, no reflection, no allowlist drift when Laravel adds
  interfaces. The resolver's input surface is "files in `app/`", which is also
  Loom's overall scope — the two stay aligned.
- Composer-independent. Loom works against Laravel apps with non-standard PSR-4
  maps, multiple namespace roots, or classmap entries, without inheriting
  Composer's complexity. The filesystem walk is honest about what we can see.
- Lazy graph traversal over an in-memory index is fast and memoisable. Each
  query is `O(depth)` with a per-FQCN cache.

**Costs:**

- Two truths exist at once: the resolver "knows" `App\Jobs\AbstractInvoiceJob`
  but does not "know" `Illuminate\Contracts\Queue\ShouldQueue`, even though
  both appear in return lists. Callers must use `knows()` to distinguish.
- A file outside `app/` (e.g. `database/factories/*` with class declarations)
  is invisible to the resolver. If a future scanner needs to resolve hierarchy
  across non-`app/` directories, the walk widens — but that's not a v1
  concern.
- The resolver duplicates filesystem iteration with the scanners. Acceptable
  overhead at current corpus sizes; revisit if profiling demands.

## Alternatives considered

1. **Parse `vendor/` for known framework interfaces.** Rejected: vendor
   churns, the parse cost is non-trivial, and the matching set (`ShouldQueue`,
   `ShouldBroadcast`, etc.) is small enough that string matching on opaque
   leaves is equivalent.
2. **Hardcode a Laravel-interface allowlist** (`ShouldQueue`,
   `ShouldBroadcast`, …). Rejected: every Laravel minor version risks
   invalidating the list; explicit allowlists drift silently.
3. **Use `Psr4ClassLocator` for on-demand FQCN → path resolution.** Rejected:
   the locator handles only the default `App\` → `app/` mapping. Real apps
   use multi-root PSR-4 maps and classmap entries that the locator does not
   model. A single eager walk is simpler and more honest.
4. **Include method-level resolution in v1.** Rejected for scope: doubles the
   index shape and pushes the PR over the size budget without a consumer
   ready to use it. Method resolution lands in a follow-up ADR alongside its
   first consumer.
