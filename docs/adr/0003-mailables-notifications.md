# ADR 0003 — MailableScanner + NotificationScanner: separate sections, shared dispatch-site machinery

**Status**: Accepted (2026-05-19)

**References**:
[`docs/scanners/mailables.md`](../scanners/mailables.md),
[`docs/scanners/notifications.md`](../scanners/notifications.md). This ADR
captures only the load-bearing decisions for issue #6 (v1.0 milestone).

## Context

Mailables (`Mail::send(new OrderShipped(...))`) and notifications
(`$user->notify(new InvoicePaid)`) are dispatch-shaped primitives the index
does not yet record. Both share Loom's existing job pattern: a class
declared somewhere under `app/`, optionally implementing `ShouldQueue`,
referenced by call sites scattered across the app.

The hard design questions:

1. One unified `messages[]` section with a `kind` discriminator, or two
   separate sections.
2. Discovery surfaces — which call shapes count as a dispatch and which
   filesystem locations seed class discovery.
3. How to extract notification `channels[]` given that `via()` is a
   method body, not a declarative annotation.
4. How dispatch-site detection wires into the existing
   `DispatchSiteVisitor` and the cross-link pass.
5. What happens to `Mail::raw(...)` and other closure-only forms with no
   FQCN.

## Decision

### 1. Two sections — `mailables[]` and `notifications[]`

Both are emitted as separate top-level arrays. They share *machinery*
(filesystem walk + dispatch-site seeding, `ShouldQueue` resolution via
the class hierarchy resolver, cross-link by FQCN) but they do not share
*shape*.

Per-entry shape diverges:

| Field           | Mailable             | Notification                 |
|-----------------|----------------------|------------------------------|
| `fqcn`          | yes                  | yes                          |
| `file`, `line`  | yes                  | yes                          |
| `queued`        | yes                  | yes                          |
| `queue_config`  | yes (mirrors jobs)   | yes (mirrors jobs)           |
| `sent_from[]`   | yes                  | —                            |
| `notified_from[]` | —                  | yes                          |
| `channels[]`    | —                    | yes                          |

A unified section would force every consumer to filter on `kind` before
reading the channel-bearing entries, and would either bloat mailables
with a null `channels` or split the per-kind required-fields list via
`oneOf`. Neither buys anything: downstream consumer questions ("which
classes send mail to users", "which notifications hit Slack") naturally
divide along the same line the schema does.

The shared machinery is reused at the *code* level (a small
`MessageClassVisitor` base, or duplicated visitor methods — left to
`ast-specialist` to pick). The schema and the docs stay separate.

Rejected: unified `messages[]` with `kind: "mailable" | "notification"`.
Per-entry polymorphism via `oneOf` is exactly the kind of schema surface
ADR 0001 warned about — bigger surface, no consumer benefit.

### 2. Discovery — filesystem walk + dispatch-site seeding (mirror jobs)

Each scanner uses the two-path discovery already proven by
`JobsScanner`:

**MailableScanner**:

- Filesystem walk of `app/Mail/**/*.php`. Every concrete class (skip
  abstract, interfaces, traits, anonymous) is a mailable candidate.
- Dispatch-site seeding from these call shapes (resolved via
  NameResolver):
  - `Mail::send(new X(...))`, `Mail::send(X::class)`
  - `Mail::queue(new X(...))`, `Mail::queue(X::class)`
  - `Mail::later($delay, new X(...))`
  - `Mail::to(...)->send(new X(...))`, `->queue(new X(...))`,
    `->later($delay, new X(...))`. Also chained off `->cc(...)`,
    `->bcc(...)`, `->locale(...)`, `->mailer(...)` — the visitor walks
    to the innermost `Mail::to/cc/bcc/locale/mailer` receiver and
    accepts the chain regardless of which methods sit in between.
  - `Mailable::raw(...)` is **out** (see §5 below).

**NotificationScanner**:

- Filesystem walk of `app/Notifications/**/*.php`.
- Dispatch-site seeding from:
  - `$any->notify(new Y(...))` and `$any->notify(Y::class)` — receiver
    is opaque (any expression). The visitor accepts because Laravel's
    `Notifiable` trait is the canonical receiver and matching the
    method name is sufficient for static analysis (see §6).
  - `$any->notifyNow(new Y(...))` — synchronous variant.
  - `Notification::send($recipients, new Y(...))`,
    `Notification::sendNow($recipients, new Y(...))` — argument index
    1 carries the notification.
  - `Notification::route('mail', '...')->notify(new Y(...))` — same
    chain-walk pattern as `Mail::to(...)->send(...)`.

Both scanners locate dispatch-site-seeded targets via
`Psr4ClassLocator::locate()` (the same helper `JobsScanner` uses) so
mailables in `app/Domain/Billing/Mail/InvoiceMailable.php` and
notifications in `app/Domain/Accounts/Notifications/InvitedNotification.php`
are picked up.

Entries are deduped by FQCN. Filesystem walk wins for `file`/`line`.

### 3. Queue detection — reuse the class hierarchy resolver

`queued: true` iff the class transitively implements
`Illuminate\Contracts\Queue\ShouldQueue` via
`ClassHierarchyResolver::implementsInterface()`. Identical mechanism
to `JobsScanner`. Vendor-parent opacity carries the same caveat
(documented per-scanner).

`queue_config` is the same six-field block as jobs (`connection`,
`queue`, `delay`, `tries`, `timeout`, `backoff`) — both mailables
(via `Queueable` trait usage) and notifications (via `Queueable`)
honour those properties at runtime. We reuse `$defs/queueConfig`
verbatim.

### 4. Notification channels — static `via()` only, with documented fallback

The `via($notifiable)` method returns the channel array at runtime. We
statically analyse only the simplest shape: a method body whose single
top-level statement is `return [...];` where the array is a literal
list of strings and / or `Class::class` constants.

- String channels (`'mail'`, `'database'`, `'slack'`, `'broadcast'`,
  `'vonage'`) are stored verbatim, lowercased.
- Class-constant channels (`SlackChannel::class`, `TelegramChannel::class`)
  are stored as the FQCN.

Channels are emitted in **source order** from the `via()` literal — not
sorted. This matches Laravel's runtime dispatch order over the channel
array and preserves user intent (`['database', 'mail']` versus
`['mail', 'database']` is meaningful to a reader auditing the file).

`channels_dynamic` distinguishes two cases:

- `via()` exists but its body is not the recognised
  single-return-literal-array shape (conditional branches, property
  access, variable interpolation, non-literal items, keyed entries):
  `channels: []`, `channels_dynamic: true`. The channels exist at
  runtime; static analysis cannot pin them down.
- `via()` is not declared on the class at all: `channels: []`,
  `channels_dynamic: false`. The class declares no channels — an
  intentional zero, not unknown. (At runtime this would throw, but the
  scanner does not crash on it.)

Consumers who want "send-to-Slack notifications" filter on `"slack" in
channels`; consumers who want to triage dynamic notifications filter on
`channels_dynamic: true`. We do **not** emit `null` — the schema would
have to allow it everywhere, and an empty array iterates naturally.

Rejected: parsing conditional branches and emitting the union of
possible channels. Too lossy in practice (most conditional `via()`
bodies key off `$notifiable->prefersMail()`-style logic that's
runtime-only); the dynamic flag is honest about the gap.

### 5. `Mail::raw($body, $closure)` — skipped

No FQCN, no class to record. Mirrors the closure-listener precedent:
when there is no FQCN, the primitive does not appear in the index.
Documented as a known limitation. There is no `unresolved_mailables[]`
mirror — the unresolved framing only fits the dispatch-target shape
where static resolution has a well-defined fail mode (the four reason
codes in `$defs/unresolvedDispatch`). A closure body is not the same
kind of gap.

If `Mail::send($variable)` / `Mail::send($container->make(...))` appears
in source code, the dispatch site is captured by DispatchScanner as a
standard `unresolved_dispatches[]` entry (see §6). The mailable itself
just doesn't get a `mailables[]` row.

### 6. Cross-link — extend `DispatchSiteVisitor`, populate from `_dispatch_sites[]`

The dispatch-site machinery already in `DispatchSiteVisitor` is widened
to recognise the new shapes, emitting them into `_dispatch_sites[]`
with new `provisionalKind` values:

- `provisionalKind: "mailable"` for `Mail::send`, `Mail::queue`,
  `Mail::later`, and the `Mail::to(...)->send(...)` chain.
- `provisionalKind: "notification"` for `$x->notify`, `$x->notifyNow`,
  `Notification::send`, `Notification::sendNow`, and the
  `Notification::route(...)->notify(...)` chain.

These join the existing `event`, `job`, and `ambiguous` provisional
kinds. Cross-link phase 5 in `IndexBuilder::crossLink()` is widened:

- `mailables[*].sent_from` is populated from sites where finalized
  `kind === 'mailable'` and `target` matches a mailable entry.
- `notifications[*].notified_from` is populated from sites where
  finalized `kind === 'notification'` and `target` matches a
  notification entry.

Both use the existing `$defs/dispatchSite` shape (`{file, line, method}`),
identical to `events[*].dispatched_from` and `jobs[*].dispatched_from`.

This preserves Loom's single-source-of-truth invariant
([AGENTS.md](https://github.com/lucasp1337/laravel-loom/blob/main/AGENTS.md): "one source of truth per output field").
The scanners emit `sent_from: []` / `notified_from: []`; cross-link
fills them.

Unresolved sites (`Mail::send($variable)`, `$user->notify($x)`) flow
into `unresolved_dispatches[]` via the same four reason codes already
in the schema. No new reasons.

Rejected: each scanner independently walking `app/` looking for its
own dispatch sites. Duplicates work, drifts from the existing
DispatchScanner-as-single-source pattern. The cost of widening
`DispatchSiteVisitor` is one new shape per dispatch form — much
smaller than two new walks.

### 7. Dispatch-site chaining — class-level config only

Chained `->onQueue('foo')`, `->onConnection('bar')`, `->delay($when)`,
`->cc(...)`, `->bcc(...)`, `->locale(...)` are **not** parsed into the
mailable / notification entry. `queue_config` reflects class-level
property declarations only. Same constraint as jobs (documented in
`jobs.md`); we mirror it rather than diverge.

The chain methods are walked only insofar as needed to locate the
target argument (the `new X` somewhere along the chain).

### 8. `Notifiable` receiver inspection — out of scope

A dispatch site `$user->notify(new InvoicePaid)` does not carry the
receiver's class. We do not attempt to type-resolve `$user` (would
require a function-level type inference pass that no other scanner
needs). The target FQCN — the notification class — is sufficient
to populate `notifications[*].notified_from`.

Receiver-class information ("which models receive InvoicePaid") is a
plausible future enhancement, but it requires either an explicit
type-tracking pass or merge with a future routes/controllers
scanner that knows the user is `App\Models\User`. Out of scope here.

### 9. Schema additions

Three new schema sites:

1. Two new top-level required properties: `mailables`, `notifications`.
2. Two new `$defs` entries: `$defs/mailable`, `$defs/notification`.
3. `stats` gains two new required integer properties: `stats.mailables`,
   `stats.notifications`.

`$defs/mailable`:

```json
{
  "type": "object",
  "required": ["fqcn", "file", "line", "queued", "queue_config", "sent_from"],
  "additionalProperties": false,
  "properties": {
    "fqcn": { "type": "string" },
    "file": { "type": "string" },
    "line": { "type": "integer", "minimum": 1 },
    "queued": { "type": "boolean" },
    "queue_config": {
      "oneOf": [{ "type": "null" }, { "$ref": "#/$defs/queueConfig" }]
    },
    "sent_from": {
      "type": "array",
      "items": { "$ref": "#/$defs/dispatchSite" }
    }
  }
}
```

`$defs/notification`: adds `channels[]` (array of strings) and
`channels_dynamic` (boolean) over the same base; replaces `sent_from`
with `notified_from`.

`Index.php` and `IndexBuilder.php` both need updating — the schedule
scanner taught us that adding a new top-level section requires
touching both the value object and the orchestrator's stats / strip
logic. `schema-guardian` reviews the schema delta; `test-engineer`
adds fixture coverage.

## Consequences

**Good:**

- Two sections keep per-entry shapes tight. Consumers can target
  "mail" or "notifications" workloads without an intermediate filter.
- All the heavy machinery (dispatch-site detection, queue resolution,
  PSR-4 location) is reused, not reinvented. One change to
  `DispatchSiteVisitor` covers both new primitives.
- The cross-link pass scales by adding a single conditional branch
  per new `provisionalKind`. The existing phase-5 join shape is
  identical (`$defs/dispatchSite`); no new join shape.
- `channels_dynamic` is honest about the static-analysis ceiling
  without forcing nullable arrays through the schema.

**Costs:**

- Two scanners means two scanner files, two visitor classes (or one
  shared visitor base — `ast-specialist`'s call), two doc pages.
  ~2x lines vs. a unified scanner. The clarity win is worth it.
- `DispatchSiteVisitor` grows two new `provisionalKind` values. The
  cross-link disambiguation table grows. Reviewer load on that
  visitor goes up; mitigated by the existing per-form helper
  methods, which the new shapes plug into.
- Channel extraction's `channels_dynamic: true` shortcut may
  surprise users on first contact ("why is `channels` empty?"). Doc
  triage step covers it.
- Receiver-class blindness (`$user->notify(...)` with unknown `$user`
  type) means we can't answer "which models receive which
  notifications" without a follow-up. Documented as a known
  limitation; not a v1.0 blocker.

## Alternatives considered

1. **Unified `messages[]` section.** Rejected: forces per-entry
   polymorphism (`oneOf` per `kind`), bloats consumers with a filter
   they wouldn't otherwise need. The "send messages to users"
   consumer interest argument is real but solved cheaply with a
   client-side concat.
2. **Resolve `via()` channels across conditional branches.**
   Rejected: most real `via()` bodies branch on per-user preference,
   which is not statically determinable. Emit the dynamic flag and
   move on.
3. **Independent dispatch-site walks per scanner.** Rejected:
   duplicates `DispatchSiteVisitor`'s class+method stack and closure
   handling. Centralising in DispatchScanner keeps one source of
   truth for dispatch-site detection.
4. **Add `mailables[*].sent_from` and `notifications[*].notified_from`
   in the scanners directly.** Rejected: violates the cross-link
   single-source rule. Same reason `jobs[*].dispatched_from` is
   cross-link-only.
5. **Include `Mail::raw(...)` as a `target: null` mailable entry
   keyed by file:line.** Rejected: no FQCN, no class — mirrors the
   closure-listener decision. Schema does not currently permit
   `target: null` on mailables, and widening it for one rare case
   is not worth it.
6. **Receiver-class resolution via lightweight type tracking.**
   Rejected for v1 scope. Plausible follow-up alongside the
   routes/controllers scanner.

## Open questions

1. **`MailMessage` returned from `via('mail')` handlers.** A
   notification's `toMail()` method returns a `MailMessage`. Should
   the notification entry surface a `mail_subject` / `mail_view`
   field? Probably no — that's content, not control flow, and
   Loom's scope is control flow. Flag for review.
2. **`#[OnConnection]` / `#[OnQueue]` PHP attributes** on mailables.
   Laravel supports these on jobs in some recent versions. If used
   on mailables/notifications, should we lift them into
   `queue_config`? Probably yes, parity with jobs; but that's a
   parity gap for jobs first, separate issue.
3. **Markdown-mailable view paths** (`->markdown('emails.invoice')`).
   Worth lifting into the entry? Same control-flow-vs-content
   question as (1). Lean: no.
