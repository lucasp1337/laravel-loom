# Add a scanner

Scanners are internal ([ADR 0007](adr/0007-scanners-not-an-extension-point.md)): one class, one entry in `DefaultScanners`. Anything it emits must already exist in `schema/loom-index.schema.json`; a new section goes through the schema first.

1. **Pick the shape.** A class-based primitive (a convention directory plus classes reached from dispatch sites) is a `ClassSpec` in `src/Scanners/Discovery/` and a thin scanner around `ClassPrimitiveDiscovery`. Anything else implements `Contracts\Scanner` and walks files through the injected `ScanScope`.
2. **Write visitors** in `src/Scanners/Visitors/`. Extend `CollectingVisitor`, clear per-file state in `reset()`, read on `leaveNode`, and read call arguments through `Support\Ast`. A new dispatch form is a row in `DispatchRules` plus a case in `tests/Unit/Dispatch/DispatchRuleMatcherTest.php`, not a shape check in a visitor.
3. **Emit schema-shaped, sorted arrays** at the end of `scan()`. Inter-component data is a DTO from `src/Dto/`, not an associative array. Report what cannot be resolved as `unresolved_dispatches`, never silently.
4. **Register** the scanner in `Scanners\DefaultScanners`. That list feeds `loom:scan`, the Justfile and the benchmarks; `composer bench:assert` catches output that moves.
5. **Document in code.** The scanner and visitor docblocks are the behaviour spec: list the AST shapes matched and the ones skipped. Add a row to [What Loom detects](../reference/what-loom-detects.md) that points at the test pinning it.
6. **Test in three layers:** heredoc snippets per visitor in `tests/Unit/`, a fixture app under `tests/Fixtures/{name}-fixture-app/` for the scanner, and an `IndexBuilder` run that validates against the schema.
7. **Check** PHPStan level 8, Pint and Pest.

Fixtures are minimal trees: no `vendor/`, no autoloader, `namespace App\...` matching PSR-4 paths. Classes need not extend real Laravel bases, because visitors work on names.
