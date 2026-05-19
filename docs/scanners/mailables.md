# MailableScanner

Discovers Laravel mailable classes and emits the `mailables[]` section of
the index.

See [ADR 0003](../adr/0003-mailables-notifications.md) for the
load-bearing design decisions (one section vs two, channel extraction,
cross-link wiring).

## What it detects

MailableScanner finds mailable classes via two discovery paths and
merges them by FQCN:

1. **Filesystem walk of `app/Mail/`.** Every `*.php` file under
   `app/Mail/` (recursively) is parsed and any concrete class found
   becomes a mailable candidate. Abstract classes, interfaces, traits,
   and anonymous classes are skipped.

2. **Dispatch-site seeding.** Any class targeted by a recognised
   mail-dispatch call shape is located via the PSR-4 guess (leading
   `App\` → `app/`) and parsed. This lets mailables in DDD-style
   layouts like `app/Domain/Billing/Mail/InvoiceMail.php` get picked
   up even though they live outside `app/Mail/`.

   Recognised call shapes (resolved via NameResolver):

   - `Mail::send(new X(...))` / `Mail::send(X::class)`
   - `Mail::queue(new X(...))` / `Mail::queue(X::class)`
   - `Mail::later($delay, new X(...))` / `Mail::later($delay, X::class)`
   - `Mail::to(...)->send(new X(...))` and the same chained off
     `->cc(...)`, `->bcc(...)`, `->locale(...)`, `->mailer(...)`
     (the visitor walks to the innermost `Mail::to/cc/bcc/...`
     receiver and accepts the chain regardless of the intermediate
     links).
   - `Mail::to(...)->queue(new X(...))`,
     `Mail::to(...)->later($delay, new X(...))`.

Entries are deduped by FQCN. A mailable discovered through both paths
produces a single entry; the filesystem walk wins for `file`/`line`.

`queued: true` iff the class transitively implements
`Illuminate\Contracts\Queue\ShouldQueue` — either declared on its own
`implements` clause, or inherited via a parent class indexed under
`app/`. Resolved via the cross-file
[class hierarchy resolver](../support/class-hierarchy.md). Inheritance
through vendor / framework classes that live outside `app/` is opaque
to the resolver and won't surface as `queued: true`.

## Output

One entry per mailable FQCN, conforming to `$defs/mailable`:

```json
{
  "fqcn": "App\\Mail\\OrderShipped",
  "file": "app/Mail/OrderShipped.php",
  "line": 18,
  "queued": true,
  "queue_config": {
    "connection": "redis",
    "queue": "mail",
    "delay": null,
    "tries": 3,
    "timeout": null,
    "backoff": null
  },
  "sent_from": []
}
```

`queue_config` is `null` when `queued: false`. When `queued: true` it
is an object with all six keys present — same shape as `$defs/job`'s
`queue_config`. Each value is either the scalar literal declared as a
class property, or `null` when the property is not declared. `null`
means "not declared"; Laravel applies its own defaults at runtime.

`sent_from` is always emitted as an empty array from the scanner.
It is populated by the cross-link pass from DispatchScanner's
per-call-site data.

Entries are sorted by `fqcn` ascending.

`stats.mailables` is added to the top-level stats block as the count
of entries.

## Cross-link behavior

- **`mailables[*].sent_from`** — for each dispatch site with finalized
  `kind === 'mailable'` whose `target` matches a mailable FQCN, an
  entry `{file, line, method}` (per `$defs/dispatchSite`) is appended.
  Identical shape to `events[*].dispatched_from` and
  `jobs[*].dispatched_from`. Sorted by `(file, line)`.

The new `provisionalKind: 'mailable'` is emitted by
`DispatchSiteVisitor` for every recognised mail-dispatch shape and
joined in cross-link phase 5 against `mailables[*].fqcn`.

Mailables do not participate in the disambiguation phase (phase 2):
no recognised call shape is ambiguous with another primitive type.
`Mail::send(new X)` cannot be mistaken for a job dispatch.

## Expected behavior

- **Standard mailable**: `class OrderShipped extends Mailable implements
  ShouldQueue { use Queueable; }` — `queued: true`, `queue_config`
  populated from class-level scalar properties.
- **Mailable outside `app/Mail/`** dispatched via `Mail::send(new X)`:
  picked up via dispatch-site seeding, file/line from the class
  declaration.
- **Mailable in `app/Mail/` never dispatched anywhere**: picked up via
  filesystem walk, `sent_from: []`.
- **Same mailable dispatched many times**: one entry; every dispatch
  site contributes to `sent_from[]`.
- **`Mail::to($user)->cc($admins)->bcc($audit)->send(new X)`**: chain
  walked; one dispatch site emitted with `target: X`.
- **Dispatch site inside a closure or arrow function**: skipped, same
  rule as DispatchScanner (closures of every kind are out).
- **Parse error in a file**: `AstWalker` swallows it; no visitor hits
  for that file.

## Known limitations

- **`Mail::raw($body, $closure)`**: skipped. No FQCN, no class to
  index. There is no `unresolved_mailables[]` mirror.
- **`Mail::send($variable)` / `Mail::send($container->make(...))`**:
  the dispatch site appears in `unresolved_dispatches[]` per the
  standard unresolved-dispatch contract. The mailable itself isn't
  added to `mailables[]` — there's no static target to index.
- **`ShouldQueue` inherited through vendor classes**: the class
  hierarchy resolver only indexes `app/`. A mailable extending an
  abstract vendor parent that itself implements `ShouldQueue` will
  still report `queued: false`. Mirrors the documented gap in jobs.
- **Method-form queue configs**: `backoff()` / `retryUntil()` methods
  on a mailable are not parsed. Only class-level scalar properties
  populate `queue_config`.
- **Dispatch-site chaining**: `->onQueue('mail')`,
  `->onConnection('sqs')`, `->delay($when)` chained at the dispatch
  site are not parsed into the entry. `queue_config` reflects
  class-default declarations only.
- **`Mail::to(...)->html(...)->text(...)`** raw-content chains:
  treated the same as `Mail::raw` — no FQCN target, skipped.
- **Non-scalar property initializers**: `public $queue =
  config('mail.queue');` leaves the field `null`.
- **Multiple top-level classes per file** (PSR-4 violation but legal
  PHP): each is recorded independently.
- **`ShouldBeUnique`, `ShouldBeEncrypted`, attribute-based
  `#[OnQueue]`**: not surfaced as flags. Same gap as jobs.
- **Mailable whose FQCN can't be located on disk**: dropped. The
  schema requires `file` and `line`. Applies to dispatch-site-seeded
  mailables whose PSR-4 guess doesn't resolve.

## When something looks wrong

Triage checklist for missing mailables:

1. Is the class under `app/Mail/` (any depth)? Yes → picked up by the
   filesystem walk.
2. Is it dispatched via one of the recognised call shapes? Yes →
   picked up via dispatch-site seeding, assuming the PSR-4 guess
   locates the file.
3. Is it abstract, an interface, a trait, or anonymous? Skipped by
   design.
4. Is the only dispatch shape `Mail::send($variable)` / `Mail::raw`?
   Check `unresolved_dispatches[]` for the dispatch site; the class
   itself won't appear in `mailables[]`.

For unexpected `queued: false`: confirm the class transitively
implements `ShouldQueue` via parents indexed under `app/`. Vendor
parents are opaque.

For unexpected `null` values inside `queue_config`: confirm the
property is declared at class level with a scalar literal
initializer. `config(...)`, constants, method-form configurations are
not extracted.

For unexpected empty `sent_from[]` on a mailable you know is
dispatched: confirm the dispatch site isn't inside a closure (skipped
by design) and that the dispatch call shape matches one of the
recognised forms above.
