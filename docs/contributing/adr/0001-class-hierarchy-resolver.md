# ADR 0001 — ClassHierarchyResolver: opaque leaves, eager walk, class-graph only

**Status**: Accepted (2026-05-17)

The API and current behaviour are in [class hierarchy](../class-hierarchy.md). This ADR keeps the decisions behind it.

## Context

Several gaps came from one missing primitive, following `extends` / `implements` / `use Trait` across files: jobs that inherit `ShouldQueue` from an abstract parent read as unqueued (#14), listeners that get `handle()` from a trait were missed, and observers that inherit hooks reported only their own.

## Decision

1. **External classes are opaque leaves.** A name the resolver has not indexed (anything in `vendor/`, framework contracts) is recorded in the result and traversal of that branch stops. No `vendor/` parsing, no reflection, no allowlist of Laravel interfaces. Callers match by string: `implementsInterface($fqcn, 'Illuminate\Contracts\Queue\ShouldQueue')` succeeds when that string appears anywhere in the transitive set.
2. **One eager walk of the scan paths, not Composer.** On first use the resolver parses every PHP file under the scan paths once and records each class, interface and trait by its resolved name. It does not read `composer.json` or Composer's autoload maps.
3. **Class graph first.** The original API was `extendsChain`, `implementsAll`, `traitsAll`, `implementsInterface`, `isSubclassOf` and `knows`. Method-level resolution came later with its first consumers, in the same class.

## Rejected

- Parsing `vendor/`: churn and cost, for a small set of names that string matching handles.
- A hardcoded Laravel interface allowlist, which drifts silently each minor version.
- Using `Psr4ClassLocator`: it models only the default mapping, not multi-root maps or classmaps.

## Consequences

- No vendor parse cost and no list to maintain. Its input is the files Loom already scans.
- The resolver "knows" `App\Jobs\AbstractInvoiceJob` but not `ShouldQueue`, though both appear in results; callers use `knows()` to tell them apart.
- A class outside the scan paths is invisible to it.
- It duplicates the filesystem walk the scanners do; acceptable at current sizes.
