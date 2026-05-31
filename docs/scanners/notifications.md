# NotificationScanner

Discovers Laravel notification classes and emits the `notifications[]`
section of the index.

See [ADR 0003](../adr/0003-mailables-notifications.md) for the
load-bearing design decisions (one section vs two, channel extraction,
cross-link wiring).

## What it detects

NotificationScanner finds notification classes via two discovery paths
and merges them by FQCN:

1. **Filesystem walk of `app/Notifications/`.** Every `*.php` file
   (recursively) is parsed and any concrete class becomes a
   candidate. Abstract classes, interfaces, traits, and anonymous
   classes are skipped.

2. **Dispatch-site seeding.** Any class targeted by a recognised
   notification-dispatch shape is located via the PSR-4 guess
   (leading `App\` → `app/`) and parsed. This lets notifications in
   non-standard layouts like
   `app/Domain/Accounts/Notifications/InvitedNotification.php` be
   picked up.

   Recognised call shapes:

   - `$any->notify(new Y(...))` / `$any->notify(Y::class)` — the
     receiver is any expression (variable, property access, method
     call result). The visitor matches on the method name and the
     argument shape; it does not attempt to type-resolve the
     receiver. The `Notifiable` trait is Laravel's canonical
     receiver and `notify` is unique enough that false positives
     are negligible.
   - `$any->notifyNow(new Y(...))` — synchronous variant.
   - `Notification::send($recipients, new Y(...))`,
     `Notification::sendNow($recipients, new Y(...))` — the
     notification class lives at argument index 1.
   - `Notification::route('mail', '...')->notify(new Y(...))` and
     longer route chains
     (`Notification::route(...)->route(...)->notify(...)`).

   The notification *argument* may itself be wrapped in a fluent chain
   and still resolves to its target FQCN:
   `$user->notify((new InvoicePaid)->locale('es'))` and
   `Notification::send($users, (new InvoicePaid)->onQueue('emails'))`
   both seed `InvoicePaid`. Only `new Y` / `Y::class` argument
   receivers resolve through the chain — a variable receiver does not.
   The chain modifier values are captured into the dispatch site's
   `overrides` object — see Cross-link behavior. Notifications read the
   **inner argument-instance chain only**; modifiers on the Notification
   facade *before* `send` (`Notification::locale('es')->send($users, $n)`)
   are not detected (see Known limitations).

Entries are deduped by FQCN. A notification discovered through both
paths produces a single entry; the filesystem walk wins for
`file`/`line`.

`queued: true` iff the class transitively implements
`Illuminate\Contracts\Queue\ShouldQueue`. Resolved via the cross-file
[class hierarchy resolver](../support/class-hierarchy.md). Inheritance
through vendor / framework classes is opaque.

`channels[]` is extracted from the notification's `via($notifiable)`
method body — see below.

## Output

One entry per notification FQCN, conforming to `$defs/notification`:

```json
{
  "fqcn": "App\\Notifications\\InvoicePaid",
  "file": "app/Notifications/InvoicePaid.php",
  "line": 22,
  "queued": true,
  "queue_config": {
    "connection": null,
    "queue": "notifications",
    "delay": null,
    "tries": null,
    "timeout": null,
    "backoff": null
  },
  "channels": ["mail", "database", "slack"],
  "channels_dynamic": false,
  "notified_from": []
}
```

Field semantics:

- **`queued`, `queue_config`** — identical to `$defs/mailable` and
  `$defs/job`. `queue_config` is `null` when `queued: false`.
- **`channels[]`** — extracted from a `via()` method whose body is a
  single `return [...];` of literal strings and/or `Class::class`
  constants. Strings (`'mail'`, `'database'`, `'slack'`,
  `'broadcast'`, `'vonage'`) are stored verbatim, lowercased.
  Class-constant channels are stored as FQCN
  (`Illuminate\Notifications\Channels\SlackChannel`). Emitted in
  source order from the `via()` literal — **not** sorted. This matches
  Laravel's runtime channel-dispatch order and preserves the intent
  recorded in the file. Empty `[]` when the `via()` body is not
  statically resolvable, and also empty `[]` when no `via()` method is
  declared.
- **`channels_dynamic`** — `true` only when `via()` exists *and* its
  body is not the recognised single-return-literal-array shape
  (conditional logic, property/method access, variable indirection,
  keyed entries, non-literal items). `false` when the body is
  statically resolved, *and* `false` when the class declares no
  `via()` method at all. The distinction matters: absence of `via()`
  means "no channels declared" (an intentional zero); `via()` with an
  unresolvable body means "channels exist at runtime but static
  analysis can't see them". Consumers wanting "Slack notifications"
  filter on `"slack" in channels`; consumers triaging notifications
  whose channels Loom can't see filter on `channels_dynamic: true`.
- **`notified_from`** — always emitted as an empty array from the
  scanner. Populated by the cross-link pass.

Entries are sorted by `fqcn` ascending.

`stats.notifications` is added to the top-level stats block as the
count of entries.

## Cross-link behavior

- **`notifications[*].notified_from`** — for each dispatch site with
  finalized `kind === 'notification'` whose `target` matches a
  notification FQCN, a `$defs/dispatchSite` entry is appended.
  Identical shape to `events[*].dispatched_from`. Sorted by
  `(file, line)`.

  When the dispatch site carries dispatch-time modifiers on the inner
  argument-instance chain
  (`$user->notify((new InvoicePaid)->onQueue('emails')->delay(60))`),
  the entry also carries an optional `overrides` object. Source
  mapping: `->onQueue('emails')` → `queue`, `->onConnection('redis')` →
  `connection`, `->delay(60)` → `delay` (integer seconds),
  `->afterCommit()` → `after_commit`, `->locale('es')` → `locale`,
  `->mailer(...)` → `mailer`. The key is omitted when no static
  modifier is present:

  ```json
  {
    "file": "app/Services/Billing.php",
    "line": 51,
    "method": "App\\Services\\Billing::charge",
    "overrides": { "queue": "emails" }
  }
  ```

  Notifications capture the inner argument-instance chain only. The
  Notification facade-receiver form
  (`Notification::locale('es')->send($users, $n)`) is not detected.
  `overrides` records what the call site changed; `queue_config` still
  reflects the notification's class-default property declarations.

The new `provisionalKind: 'notification'` is emitted by
`DispatchSiteVisitor` for every recognised notification-dispatch
shape and joined in cross-link phase 5 against
`notifications[*].fqcn`.

Notifications do not participate in the disambiguation phase
(phase 2): the recognised call shapes (`$x->notify(...)`,
`Notification::send(...)`) are unambiguous.

## Expected behavior

- **Standard notification with static `via()`**: `public function
  via($notifiable) { return ['mail', 'database']; }` —
  `channels: ["mail", "database"]`, `channels_dynamic: false`. Source
  order is preserved.
- **`via()` returning class constants**: `return [SlackChannel::class,
  'database'];` — `channels: ["Illuminate\\Notifications\\Channels\\SlackChannel",
  "database"]`, in source order.
- **`via()` with conditional logic**: `return $notifiable->prefers
  ? ['mail'] : ['database'];` — `channels: []`,
  `channels_dynamic: true`.
- **`via()` referencing a property**: `return $this->channels;` —
  `channels: []`, `channels_dynamic: true`.
- **No `via()` declared**: `channels: []`, `channels_dynamic: false`.
  The class declared no channels; this is an intentional zero, not an
  unknown. (Would throw at runtime, but the scanner doesn't crash on
  it.)
- **Notification dispatched via `$user->notify(new X)` where `$user`
  is `Notification::route(...)` chained**: same target extraction;
  one `notified_from` entry per call site.
- **Same notification dispatched many times**: one entry; every
  dispatch site contributes to `notified_from[]`.
- **Dispatch site inside a closure or arrow function**: skipped,
  same rule as DispatchScanner.

## Known limitations

- **Conditional `via()` bodies**: no union over branches.
  `channels_dynamic: true` is the signal. Mirrors the
  `unresolved_dispatches`-style honesty: surface the gap rather than
  fabricate a value.
- **`via()` declared on a parent class or trait**: the visitor reads
  the method body on the class itself, not the resolved inheritance
  chain. Method-level hierarchy resolution is reserved for a
  follow-up ADR (per ADR 0001 §3). Inheriting `via()` from a parent
  yields `channels: []`, `channels_dynamic: false` — the scanner
  cannot distinguish this from "class genuinely has no `via()`".
- **`shouldSend($notifiable, $channel)`** runtime gating: not
  considered. Channels listed by `via()` are emitted even if
  `shouldSend()` would suppress one.
- **`toMail()`, `toSlack()`, `toDatabase()` content**: not parsed.
  Mail subject lines, Slack block content, etc. are message content,
  not control flow.
- **`$user->notify($variable)`** where the notification class is
  dynamic: appears in `unresolved_dispatches[]` per the standard
  contract.
- **Custom channel classes outside `app/`**: stored as their FQCN
  verbatim (`Illuminate\Notifications\Channels\SlackChannel`,
  `Vendor\Telegram\Channel`). No file/line lookup; consumers
  cross-reference by FQCN.
- **`ShouldQueue` inherited through vendor classes**: same opaque-leaf
  caveat as jobs and mailables.
- **Method-form queue configs** (`backoff()`, `retryUntil()`): not
  parsed.
- **Notification facade-receiver modifiers**: only the inner
  argument-instance chain is read. Modifiers set on the `Notification`
  facade before `send`
  (`Notification::locale('es')->send($users, $n)`) are not captured —
  unlike Mail, whose facade-receiver chain
  (`Mail::to($u)->locale('fr')->send($m)`) *is* read.
- **`->delay()` with a non-integer-literal argument**: captured
  `overrides.delay` is integer-second literals only. `->delay($seconds)`
  and `->delay(now()->addMinutes(5))` leave the `delay` key absent.
- **Modifiers set on a separate statement**:
  `$notification->locale('es'); $u->notify($notification);` is out of
  static reach — `overrides` reads only the chain at the dispatch site
  itself.
- **Receiver-class blindness**: `$user->notify(new X)` doesn't tell
  Loom what kind of receiver `$user` is. "Which models receive
  InvoicePaid" is not answerable from the index today. Documented
  follow-up.
- **Multiple top-level classes per file** (PSR-4 violation): each
  recorded independently.
- **Notification whose FQCN can't be located on disk**: dropped, per
  the schema's `file` / `line` requirement.

## When something looks wrong

Triage checklist for missing notifications:

1. Is the class under `app/Notifications/` (any depth)? Yes → picked
   up by the filesystem walk.
2. Is it dispatched via `$any->notify(...)`,
   `Notification::send(...)`, or `Notification::route(...)->notify
   (...)`? Yes → dispatch-site seeded.
3. Is it abstract, interface, trait, or anonymous? Skipped by design.
4. Is the only dispatch `->notify($variable)`? Check
   `unresolved_dispatches[]`.

Triage for unexpected `channels: []`:

1. If `channels_dynamic: true`: `via()` exists but its body isn't the
   recognised single-return-literal-array shape. Confirm it's
   declared directly on the class (not inherited) and that the body
   is a single `return [...]` with literal strings and/or
   `Class::class` constants. Anything else — conditional, property
   access, variable, keyed entries — flips the dynamic flag.
2. If `channels_dynamic: false`: no `via()` method was found on the
   class. Either the class genuinely declares no channels, or
   `via()` is inherited from a parent / trait (method-level
   hierarchy resolution is out of scope per ADR 0001 §3).

Triage for unexpected `queued: false`: confirm transitive `ShouldQueue`
via parents indexed under `app/`. Vendor parents are opaque.

Triage for unexpected empty `notified_from[]`: confirm the dispatch
site isn't inside a closure (skipped by design) and that the
dispatch call shape matches one of the recognised forms.
