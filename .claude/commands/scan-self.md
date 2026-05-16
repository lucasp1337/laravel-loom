---
description: Run Loom on a fixture app and review the output
argument-hint: <fixture-name> (defaults to basic-app)
---

Run Loom against a fixture app and review the output. Useful for regression checks and for refreshing sample output in README.

Fixture: `$ARGUMENTS` (defaults to `basic-app` if empty).

## Steps

### 1. Confirm fixture exists

```bash
ls tests/Fixtures/${ARGUMENTS:-basic-app}/
```

If missing, list available fixtures under `tests/Fixtures/` and stop.

### 2. Run the scan

The scanner reads source directly — no need to fully boot the fixture as a Laravel app:

```bash
php -r "
require 'vendor/autoload.php';
\$builder = new \Multitude\Loom\Index\IndexBuilder();
\$index = \$builder->build(__DIR__ . '/tests/Fixtures/${ARGUMENTS:-basic-app}');
echo json_encode(\$index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
" > /tmp/loom-scan-output.json
```

### 3. Validate against schema

Run `/validate-schema /tmp/loom-scan-output.json`. Stop if validation fails.

### 4. Review output

Read `/tmp/loom-scan-output.json` and report:

- Section counts (matches expected for this fixture?)
- Any `unresolved_dispatches` entries (expected for `unresolved-dispatches` fixture, suspicious elsewhere)
- Spot-check 2-3 entries against the fixture source — do file/line numbers point to the right places?
- Anything missing — entries that should be there based on the fixture's source but aren't

### 5. Diff against last known good (if exists)

If `tests/Fixtures/${ARGUMENTS:-basic-app}/expected-output.json` exists:

```bash
diff <(jq -S . /tmp/loom-scan-output.json) <(jq -S . tests/Fixtures/${ARGUMENTS:-basic-app}/expected-output.json)
```

Report the diff. Unexpected differences are likely regressions.

### 6. Offer to update samples

If output is correct and differs from README sample, offer to invoke `doc-writer` to refresh README. Do not auto-update.

## When to use

- After modifying any scanner
- Before commit when scanner output might have changed
- When refreshing README sample
- As a quick smoke test during development
