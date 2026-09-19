# Upgrading

Regenerate the index after every upgrade: `php artisan loom:scan`. An index written by an older release may fail validation against the current schema.

## From 0.2 to 0.3 and later

### New hard requirements

`livewire/livewire` (^3.8) and `laravel/mcp` (^1.0) are now installed with Loom. Composer pulls them in; if your app pins conflicting versions, resolve that first. Apps pinning `laravel/mcp` 0.x must upgrade to ^1.0.

### Index shape changes

Old index files are no longer valid. Tools that read `index.json` need to handle:

- `closure_listeners[]` entries now require `end_line`.
- `scheduled[]` entries now require `name` (`null` when unnamed) and `even_in_maintenance_mode`.
- New optional `scheduled[]` fields: `arguments`, `queue`, `connection`, `without_overlapping_expires_at`. Sub-minute schedules use `frequency: {unit, every}` with `cron: null`.
- New optional `overrides` on dispatch sites and `channels` on notification dispatch sites.
- `closure_listeners[].dispatches` is now populated.
- New top-level sections: `routes[]`, `mailables[]` and `notifications[]`. `loom_version` in the file is `0.3.0` for `routes[]`.

See [Schema](reference/schema.md) for the full shape.

### Routes

`routes[]` lists HTTP routes parsed from the files under `routes/`, with the events and jobs dispatched by each controller method. Nothing to configure. Consumers that iterate every top-level section will see it as new.

### The browser UI and the `viewLoom` gate

The read-only UI at `/loom` is on by default. It is allowed only in the `local` environment. To open it elsewhere, define the `viewLoom` gate yourself, or turn the UI off with `LOOM_UI_ENABLED=false`. See [Commands](reference/commands.md#configuration) for the settings.
