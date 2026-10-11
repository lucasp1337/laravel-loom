# ADR 0001 — ClassHierarchyResolver: opaque leaves, eager walk, class-graph only

**Status**: Accepted (2026-05-17)

The API and current behaviour are in [class hierarchy](../class-hierarchy.md).

## Context

Several gaps came from one missing primitive, following `extends` / `implements` / `use Trait` across files: jobs that inherit `ShouldQueue` from an abstract parent read as unqueued, listeners that get `handle()` from a trait were missed, and observers that inherit hooks reported only their own.

## Decision

1. **External classes are opaque leaves.** A name the resolver has not indexed (anything in `vendor/`, framework contracts) is recorded in the result and traversal of that branch stops. No `vendor/` parsing, no reflection, no allowlist of framework interfaces. Callers match by string: `implementsInterface($fqcn, 'Illuminate\Contracts\Queue\ShouldQueue')` succeeds when that string appears anywhere in the transitive set.
2. **One eager filesystem walk, not Composer or PSR-4 driven.** On first use the resolver walks `$appRoot/app/**/*.php` once, parses each file and records every class, interface and trait by its resolved `namespacedName`. It does not read `composer.json`, does not use Composer's autoload maps and does not depend on `Psr4ClassLocator`.

    *Amended 2026-10-11: the walk now covers the configured scan paths (`ScanScope`) instead of a fixed `app/`. The rest of the decision stands.*
3. **Class graph only, no method-level resolution in v1.** `extendsChain`, `implementsAll`, `traitsAll`, `implementsInterface`, `isSubclassOf` and `knows`; no `definesMethod`, no `methodOrigin`. The listener-via-trait and observer-via-parent fixes need method resolution, which lands in a follow-up ADR that extends the index shape.

    *Amended 2026-10-11: method-level resolution (`effectiveMethods()`) was added later in the same class, with the listener and observer scanners as its first consumers. No separate ADR was written.*

## Rejected

- Parsing `vendor/`: churn and cost, for a small set of names that string matching handles.
- A hardcoded framework interface allowlist, which drifts silently.
- Using `Psr4ClassLocator`: it models only the default mapping, not multi-root maps or classmaps.

## Consequences

- No vendor parse cost and no list to maintain. Its input is the files Loom already scans.
- The resolver "knows" `App\Jobs\AbstractInvoiceJob` but not `ShouldQueue`, though both appear in results; callers use `knows()` to tell them apart.
- A class outside the scan paths is invisible to it.
- It duplicates the filesystem walk the scanners do; acceptable at current sizes.
