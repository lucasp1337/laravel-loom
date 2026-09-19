# UI configuration

Every key that controls the browser UI, with its default and environment variable. For what the UI does, see [Browse the UI](../guides/browse-the-ui.md).

Publish the file to change values that have no environment variable:

```bash
php artisan vendor:publish --tag=loom-config
```

## Keys

| Key | Env variable | Default | Meaning |
| --- | --- | --- | --- |
| `index_path` | `LOOM_INDEX_PATH` | `null` | Where `loom:scan` writes the index and where the CLI, MCP server and UI read it. `null` means `storage/loom/index.json`. |
| `ui.enabled` | `LOOM_UI_ENABLED` | `true` | Mounts the UI. When `false`, no routes, pages or gate exist. |
| `ui.path` | `LOOM_PATH` | `loom` | URI prefix. Slashes at either end are trimmed; an empty value falls back to `loom`. |
| `ui.domain` | `LOOM_DOMAIN` | `null` | Serve the UI only on this domain. `null` serves it on every domain. |
| `ui.middleware` | none | `['web']` | Middleware that runs before the `viewLoom` gate check. The gate check always runs. |
| `ui.index_path` | none | `null` | Overrides `index_path` for the UI only. Non-empty values win over the top-level key. |
| `ui.chain_depth` | none | `3` | Depth the chain view opens with. Clamped to 1-6; a non-integer falls back to 3. |

The keys are addressed as `loom.ui.enabled`, `loom.index_path` and so on. The `ui` keys live inside the `ui` array in the published file.

## The viewLoom gate

The gate is not a config key. By default it allows the `local` environment and denies everything else with a 403. Define `Gate::define('viewLoom', ...)` in your app to replace it. An example is in [Browse the UI](../guides/browse-the-ui.md#open-it-on-staging).

!!! warning "Route caching hides changes"
    `enabled`, `path`, `domain` and `middleware` are read when routes are registered. If you use `php artisan route:cache`, run `php artisan route:clear` after changing them.
