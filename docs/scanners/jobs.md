# JobsScanner

Discovers queueable and synchronous job classes and emits the `jobs[]` section of the index.

## What it detects

JobsScanner finds job classes via two discovery paths and merges them by FQCN:

1. **Filesystem walk of `app/Jobs/`.** Every `*.php` file under `app/Jobs/` (recursively) is parsed and any concrete class found becomes a job candidate. Abstract classes, interfaces, traits, and anonymous classes are skipped.

2. **Dispatch-site seeding.** Any class targeted by `Bus::dispatch(...)`, `dispatch(...)`, or the Dispatchable form `X::dispatch()` is located via the PSR-4 guess (leading `App\` → `app/`) and parsed. This lets jobs in DDD-style layouts like `app/Domain/Billing/Jobs/SettleInvoice.php` get picked up even though they live outside `app/Jobs/`. A target wrapped in a fluent chain is resolved through the chain to its FQCN: `dispatch((new ProcessOrder())->delay(60))` and `ProcessOrder::dispatch()->onQueue('high')` both seed `ProcessOrder` (only `new X` / `X::class` receivers resolve, not variable receivers). The dispatch-time modifiers on that chain are captured into the site's `overrides` object — see Cross-link behavior.

Entries are deduped by FQCN. A job discovered through both paths produces a single entry.

`queued: true` iff the class transitively implements `Illuminate\Contracts\Queue\ShouldQueue` — either declared on its own `implements` clause, or inherited via a parent class indexed under `app/`. Resolved via the cross-file [class hierarchy resolver](../support/class-hierarchy.md). Inheritance through vendor or framework classes that live outside `app/` is opaque to the resolver and won't surface as `queued: true`.

## Output

One entry per job FQCN, conforming to `$defs/job`:

```json
{
  "fqcn": "App\\Jobs\\ProcessOrder",
  "file": "app/Jobs/ProcessOrder.php",
  "line": 14,
  "queued": true,
  "queue_config": {
    "connection": "redis",
    "queue": "high",
    "delay": null,
    "tries": 3,
    "timeout": 60,
    "backoff": null
  },
  "dispatched_from": [],
  "dispatches": []
}
```

`queue_config` is `null` when `queued: false`. When `queued: true` it is an object with all six keys present — `connection`, `queue`, `delay`, `tries`, `timeout`, `backoff`. Each value is either the scalar literal declared as a class property, or `null` when the property is not declared on the class. `null` means "not declared" — at runtime Laravel applies its own defaults from `config/queue.php` and from the framework's job traits.

`dispatched_from` and `dispatches` are always emitted as empty arrays from the scanner. Both are populated by the cross-link pass from DispatchScanner's per-call-site data.

Entries are sorted by `fqcn` ascending.

## Cross-link behavior

- **`jobs[*].dispatched_from`** — for each dispatch site with finalized `kind === 'job'` whose `target` matches a job FQCN, a `$defs/dispatchSite` entry is appended. Sites carrying the pre-disambiguation `kind: ambiguous` do NOT contribute; they're finalized in cross-link Phase 2 before this join runs.

  When the dispatch site carries dispatch-time modifiers, the entry also carries an optional `overrides` object. For jobs both the inner argument-instance chain (`dispatch((new ProcessOrder)->onQueue('high')->delay(60))`) and the outer PendingDispatch chain (`ProcessOrder::dispatch($o)->onQueue('high')->onConnection('redis')->delay(60)`, `dispatch(new ProcessOrder)->afterCommit()`) are read. Source mapping: `->onQueue('high')` → `queue`, `->onConnection('redis')` → `connection`, `->delay(60)` → `delay` (integer seconds), `->afterCommit()` → `after_commit`, `->locale(...)` → `locale`, `->mailer(...)` → `mailer`. The key is omitted when no static modifier is present:

  ```json
  {
    "file": "app/Services/Checkout.php",
    "line": 91,
    "method": "App\\Services\\Checkout::finalize",
    "overrides": { "connection": "redis", "queue": "high", "delay": 60 }
  }
  ```

  `overrides` records what the call site changed; `queue_config` still reflects the job's class-default property declarations. The two are independent.

- **`jobs[*].dispatches`** — for each dispatch site whose enclosing class FQCN matches a job and whose enclosing method is `handle`, a `$defs/dispatch` entry is appended. Dispatches emitted from helper methods called by `handle()` are NOT attributed (see Known limitations).

## Known limitations

- **`ShouldQueue` inherited through vendor classes.** The class hierarchy resolver only indexes `app/`. A job that extends an abstract class from a vendor package (e.g. a Laravel framework class) which itself implements `ShouldQueue` will still report `queued: false`.
- **Helper methods called from `handle()`.** Dispatches inside a helper method (e.g. `processOrder()` called by `handle()`) are not attributed to the job's `dispatches[]`. Only sites whose enclosing method is literally `handle` are joined. Same constraint as listeners' non-`handle*` methods.
- **`Bus::chain([new A, new B])` and `Bus::batch([...])`.** Neither captured by DispatchScanner today. Jobs registered via chain or batch will not surface in `jobs[*].dispatched_from`. A future change to `DispatchSiteVisitor` could recognise array-literal chain/batch contents.
- **Method-form queue configs.** `backoff()` and `retryUntil()` methods are not parsed. Only class-level scalar property declarations (`public $backoff = 60;`) are extracted into `queue_config`.
- **`->delay()` with a non-integer-literal argument.** Captured `overrides.delay` is integer-second literals only. `->delay(now()->addMinutes(5))` and `->delay($seconds)` leave the `delay` key absent.
- **Modifiers set on a separate statement.** `$job->onQueue('high'); dispatch($job);` is out of static reach — `overrides` reads only the chain at the dispatch site itself.
- **Non-scalar property initializers.** `public $queue = config('queue.default');` (or any expression that isn't a scalar literal) leaves the field `null`.
- **`ShouldBeUnique`, `ShouldBeEncrypted`, `Batchable`, `InteractsWithQueue`.** These marker interfaces and traits are not surfaced as flags on the entry.
- **Job whose FQCN can't be located on disk.** Dropped. The schema requires `file` and `line`. This applies to dispatch-site-seeded jobs whose PSR-4 guess doesn't resolve.

## When something looks wrong

Triage checklist for missing jobs:

1. Is the class under `app/Jobs/` (any depth)? Yes → picked up by the filesystem walk.
2. Is it dispatched via `dispatch(new X)`, `Bus::dispatch(new X)`, or `X::dispatch()` somewhere under `app/`? Yes → picked up via dispatch-site seeding, assuming the PSR-4 guess locates the file.
3. Is it abstract, an interface, a trait, or an anonymous class? Skipped by design.
4. Is it only referenced inside `Bus::chain([...])` or `Bus::batch([...])`? Not currently captured.

For unexpected `queued: false` on a class that extends a queueable parent: confirm the parent lives under `app/` and that some class in the extends chain declares `implements ShouldQueue`. Vendor parents are opaque to the resolver.

For unexpected `null` values inside `queue_config`: confirm the property is declared at class level with a scalar literal initializer. `config(...)`, constants, and method-form configurations are not extracted.

For unexpected empty `dispatches[]` on a job that clearly fires events from inside its work: confirm the dispatch is in `handle()` itself, not in a helper method called from `handle()`.
