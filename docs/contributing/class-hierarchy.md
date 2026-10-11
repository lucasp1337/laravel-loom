# Class hierarchy resolver

`Lucasp\Loom\Support\ClassHierarchyResolver` answers transitive `extends`, `implements` and `use Trait` questions across the files under the scan paths. It is internal; scanners consume it and no JSON output references it. The decisions behind it are in [ADR 0001](adr/0001-class-hierarchy-resolver.md); the precedence and ordering rules are in the class docblock.

There is no shared instance. Each consumer builds its own from the app root and the shared `AstWalker`, plus the `ScanScope` that limits the walk to the scan paths: `ListenerScanner` and `ObserverScanner` one per scan, and `ClassPrimitiveDiscovery` one per `discover()` call, so events, jobs, mailables and notifications each get one. A resolver's first query walks every PHP file in scope once through `ClassDeclarationVisitor` and indexes each class, interface and trait that has a resolved name (anonymous classes are skipped). Queries then resolve lazily and memoise per FQCN, per instance.

## API

| Method | Returns |
| --- | --- |
| `extendsChain($fqcn)` | Parents, nearest first; stops at the first unknown class. |
| `implementsAll($fqcn)` | Every interface implemented transitively, through parents and interface inheritance. |
| `traitsAll($fqcn)` | Every trait used, through parents and traits that use traits. |
| `implementsInterface($fqcn, $interface)` | Whether that exact name appears anywhere in the transitive set. |
| `isSubclassOf($fqcn, $ancestor)` | Extends-chain membership. |
| `effectiveMethods($fqcn)` | `array<string, ResolvedMethod>` after trait composition and inheritance, keyed by lower-cased name. |
| `isInstantiable($fqcn)` | A known class that is not abstract. |
| `firstParameterClasses($method)` | Class names in a method's first parameter type, with `self` and `parent` resolved. |
| `knows($fqcn)` | Whether a declaration was indexed. |

## Opaque leaves

A name not declared under the scan paths is returned but never traversed, and callers match it by string. Given:

```php
class SendInvoice extends AbstractInvoiceJob implements ShouldQueue
```

where `SendInvoice` and `AbstractInvoiceJob` are in `App\Jobs` under the scan paths and `ShouldQueue` is Laravel's, the resolver answers:

| Call | Result |
| --- | --- |
| `extendsChain('App\Jobs\SendInvoice')` | `['App\Jobs\AbstractInvoiceJob']` |
| `implementsInterface($fqcn, 'Illuminate\Contracts\Queue\ShouldQueue')` | `true`, matched by string |
| `knows('App\Jobs\AbstractInvoiceJob')` | `true` |
| `knows('Illuminate\Contracts\Queue\ShouldQueue')` | `false` |

A method that only a vendor parent defines is absent from `effectiveMethods()`. Inheritance cycles terminate: with `App\A extends App\B` and `App\B extends App\A`, `extendsChain('App\A')` is `['App\B', 'App\A']`.

## Consumers

- `ListenerScanner`: auto-discovery (public `handle*` or `__invoke` with a first parameter) and `queued`.
- `ObserverScanner`: observer hooks from `effectiveMethods()`.
- `JobClassSpec`, `MailableClassSpec` and `NotificationClassSpec`: `queued`, through `ClassPrimitiveDiscovery`.

## Limits

No persistent cache, and no property or constant resolution (a parent's `$queue` is not read through it).

`ObserverScanner` currently builds its resolver without the `ScanScope`, so with a custom `scan.paths` its hook resolution walks the default location.
