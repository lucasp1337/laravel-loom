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

If a CLI validator is installed (e.g. `vendor/bin/json-schema-validate`), use it:

```bash
vendor/bin/json-schema-validate schema/loom-index.schema.json $ARGUMENTS
```

If not, run a small PHP script:

```bash
php -r "
require 'vendor/autoload.php';
\$validator = new \JsonSchema\Validator();
\$data = json_decode(file_get_contents('$ARGUMENTS'));
\$schema = json_decode(file_get_contents('schema/loom-index.schema.json'));
\$validator->validate(\$data, \$schema);
if (\$validator->isValid()) {
    echo 'VALID' . PHP_EOL;
    exit(0);
}
foreach (\$validator->getErrors() as \$error) {
    printf('[%s] %s' . PHP_EOL, \$error['property'], \$error['message']);
}
exit(1);
"
```

## Reporting

On pass: brief summary of section counts (events, listeners, observers, model_events, unresolved_dispatches).

On fail: every violation with:
- The JSON pointer path (e.g. `events.3.dispatched_from.0.line`)
- The schema rule that failed (e.g. "expected integer, got string")
- A suggestion if the cause is obvious (e.g. "scanner emitted line as string — cast to int")

Hand off to `schema-guardian` for any non-trivial diagnosis.
