# Class hierarchy resolver

`Lucasp\Loom\Support\ClassHierarchyResolver` answers transitive `extends`, `implements` and `use Trait` questions across the files under the scan paths. It is internal; scanners consume it and no JSON output references it. The decisions behind it are in [ADR 0001](adr/0001-class-hierarchy-resolver.md); the precedence and ordering rules are in the class docblock.

It is constructed once per `IndexBuilder::build()` with the app root, the shared `AstWalker` and the `ScanScope`, and is not shared across builds. The first query walks every PHP file in scope once through `ClassDeclarationVisitor` and indexes each class, interface and trait that has a resolved name (anonymous classes are skipped). Queries then resolve lazily and memoise per FQCN.

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

A name not declared under the scan paths is returned but never traversed, and callers match it by string. For `App\Jobs\SendInvoice extends App\Jobs\AbstractInvoiceJob implements ShouldQueue`, `implementsInterface()` is `true` and `knows('App\Jobs\AbstractInvoiceJob')` is `true`, while `knows('Illuminate\Contracts\Queue\ShouldQueue')` is `false`. A method that only a vendor parent defines is absent from `effectiveMethods()`.

## Consumers

- `ListenerScanner`: auto-discovery (public `handle*` or `__invoke` with a first parameter) and `queued`.
- `ObserverScanner`: observer hooks from `effectiveMethods()`.
- The class-based discovery specs: `queued` for jobs, mailables and notifications.

## Limits

No persistent cache, and no property or constant resolution (a parent's `$queue` is not read through it).
