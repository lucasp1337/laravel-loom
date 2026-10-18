# Add a scanner

Scanners are internal ([ADR 0007](adr/0007-scanners-not-an-extension-point.md)): one class and one entry in `DefaultScanners`. Anything a scanner emits must already exist in `schema/loom-index.schema.json`, so a new section goes through the schema first. It also needs a `Sections` case and a `SectionRegistry` entry; see [Adding a top-level section](architecture.md#sections-and-fields), and check the places that enumerate sections (cross-link, `loom:diff`, MCP, the UI).

If you work with an agent, the `/add-scanner` command runs these steps as a chain: architect, schema review, visitors, tests, quality checks, docs. For the three concerns every scanner keeps apart (discovery, parsing, emission), see [Scanner contract](architecture.md#scanner-contract).

## Steps

1. **Pick the shape.** A class-based primitive (a convention directory plus classes reached from dispatch sites) is a `ClassSpec` in `src/Scanners/Discovery/` and a thin scanner around `ClassPrimitiveDiscovery`. Anything else implements `Contracts\Scanner` and walks files through the injected `ScanScope`.
2. **Write visitors** in `src/Scanners/Visitors/`, following the [visitor conventions](architecture.md#visitor-conventions):
    - Extend `CollectingVisitor`; clear per-file state in `reset()`.
    - Read on `leaveNode`, so inner names are already resolved.
    - Read call arguments through `Support\Ast`, never `PhpParser\Node\Arg`.
    - Expose collected records through a getter.
    - A new dispatch form is a row in `DispatchRules` plus a case in `tests/Unit/Dispatch/DispatchRuleMatcherTest.php`, not a shape check in a visitor.
3. **Emit schema-shaped, sorted arrays** at the end of `scan()`. Inter-component data is a DTO from `src/Dto/`, not an associative array. Report what cannot be resolved as `unresolved_dispatches`, never silently.
4. **Register** the scanner in `Scanners\DefaultScanners`. That list feeds `loom:scan`, the Justfile and the benchmarks; `just bench --assert` catches output that moves, and `just bench-baseline` rewrites the baseline when the move is intended.
5. **Document in code.** The scanner and visitor docblocks are the behaviour spec: list the AST shapes matched and the ones skipped. Add a case for each construct, read and not read, under `docs/examples/<primitive>/`; `tests/Docs/ExamplesTest.php` documents the format.
6. **Test in three layers:** heredoc snippets per visitor in `tests/Unit/`, a fixture app under `tests/Fixtures/{name}-fixture-app/` for the scanner, and an `IndexBuilder` run that validates against the schema.
7. **Run the checks** from [CONTRIBUTING](https://github.com/lucasp1337/laravel-loom/blob/main/CONTRIBUTING.md): PHPStan, Pint and Pest.

## Fixtures

Fixtures are minimal trees: no `vendor/`, no autoloader, `namespace App\...` matching the PSR-4 paths. Classes need not extend real Laravel bases, because visitors work on names.

## Checklist

- [ ] Schema section and fields, plus `Sections`, `Field` and `SectionRegistry` entries (`FieldSchemaParityTest` fails when the enum and schema diverge)
- [ ] `ClassSpec` or `Scanner` class, visitors and DTOs
- [ ] `DefaultScanners` registration and a refreshed benchmark baseline
- [ ] Docblocks listing matched and skipped shapes
- [ ] Example cases in `docs/examples/<primitive>/`
- [ ] Unit tests, a fixture app and an `IndexBuilder` test
- [ ] PHPStan, Pint and Pest green
