# ADR 0003 — MailableScanner and NotificationScanner: separate sections, shared dispatch-site machinery

**Status**: Accepted (2026-05-19)

What each scanner matches today is in the scanner, `*ClassSpec` and `DispatchRules` docblocks. This ADR keeps the decisions behind it.

## Context

Mailables and notifications are dispatch-shaped primitives the index did not record. Both follow the job pattern: a class somewhere under `app/`, optionally `ShouldQueue`, referenced from scattered call sites.

## Decision

1. **Two sections, `mailables[]` and `notifications[]`.** They share machinery but not shape: mailables carry `sent_from[]`, notifications carry `notified_from[]`, `channels[]` and `channels_dynamic`. A unified `messages[]` would need per-`kind` `oneOf` and a filter on every read, for no consumer benefit.
2. **Discovery mirrors jobs.** Walk `Mail/` or `Notifications/`, then seed from dispatch sites and locate the class through PSR-4, so classes in DDD-style layouts are found. Filesystem walk wins for `file` and `line`.
3. **Queue state reuses the class hierarchy resolver and `$defs/queueConfig`**, identical to jobs.
4. **Channels come from a literal `via()` only.** A body that is a single `return [...]` of strings and `Class::class` constants gives `channels` in source order (strings lowercased, classes as FQCN). Anything else gives `channels: []` with `channels_dynamic: true`; a class that declares no `via()` gives `[]` with `false`. Source order is kept because it is what the file says. No `null`, so arrays always iterate.
5. **`Mail::raw()` and other forms with no class are skipped.** No FQCN means no entry, as with closure listeners. A variable or container-resolved target is still an `unresolved_dispatches[]` entry.
6. **Dispatch sites are widened in the existing visitor, not re-walked per scanner.** New provisional kinds `mailable` and `notification` feed `mailables[].sent_from` and `notifications[].notified_from` through the cross-link pass, using the shared `$defs/dispatchSite`. Scanners emit empty arrays; one source of truth per field.
7. **Chain modifiers belong to the dispatch site.** Queue, connection, delay and locale on the call are `overrides` on the site, while `queue_config` stays the class-level declaration.
8. **The receiver is not type-resolved.** `$user->notify(new X)` matches on the method name and argument; the notification class is enough for `notified_from`. "Which models receive it" would need type tracking.

## Rejected

- Unified `messages[]`.
- Taking the union of channels across conditional `via()` branches; most real branches key off per-user preference and are not static.
- A `target: null` mailable for `Mail::raw()`.
- Each scanner walking `app/` for its own dispatch sites.

## Consequences

- Two scanners, two visitors and two sections cost lines but keep each entry tight.
- A dynamic `via()` reads as empty channels until the user finds `channels_dynamic`.
- Receiver classes stay unknown.

## Open questions

- Content fields (`toMail()` subject, markdown view paths) are out of scope: Loom reports control flow, not content.
- `#[OnConnection]` and `#[OnQueue]` attributes are not read for jobs either; fix jobs first.
