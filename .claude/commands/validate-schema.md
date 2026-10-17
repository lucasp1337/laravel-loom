---
description: Validate a JSON file against the canonical Loom schema
argument-hint: <path-to-json-file>
---

Validate the JSON file at `$ARGUMENTS` against `schema/loom-index.schema.json`.

## Method

Invoke `schema-guardian` with:

> Validate `$ARGUMENTS` against `schema/loom-index.schema.json`. Report:
> - Pass/fail
> - If fail: every violation with the JSON pointer path and the schema rule that failed
> - If pass: a brief summary of what the file contained (counts per section)

## Implementation

Use the repo's own validator, `IndexBuilder::validate()`, in Docker (no local php):

```bash
docker run --rm -v "$(pwd):/app" -v "$(dirname $ARGUMENTS):/in:ro" laravel-loom-dev:latest php -r "
require '/app/vendor/autoload.php';
\$errors = (new Lucasp\\Loom\\Index\\IndexBuilder())->validate(json_decode(file_get_contents('/in/$(basename $ARGUMENTS)'), true));
echo \$errors === [] ? 'VALID' : json_encode(\$errors, JSON_PRETTY_PRINT), PHP_EOL;
exit(\$errors === [] ? 0 : 1);
"
```

## Reporting

On pass: brief summary of section counts (events, listeners, observers, model_events, unresolved_dispatches).

On fail: every violation with:
- The JSON pointer path (e.g. `events.3.dispatched_from.0.line`)
- The schema rule that failed (e.g. "expected integer, got string")
- A suggestion if the cause is obvious (e.g. "scanner emitted line as string — cast to int")

Hand off to `schema-guardian` for any non-trivial diagnosis.
