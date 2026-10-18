# Why was my code missed?

When an entry is missing or a link is empty, first look for its file in the unresolved lists.

```bash
php artisan loom:scan
jq '.unresolved_dispatches[], .unresolved_routes[] | select(.file == "app/Http/Controllers/OrderController.php")' storage/loom/index.json
```

**1. Read the reason.** Loom lists a call it recognised but could not resolve, with its file, line and `reason`. Find the reason in the [reason table](../reference/detection/index.md#unresolved-reasons); it links to the example that produces it, next to the form Loom does read.

**2. Find your code shape on the primitive's page.** Some forms leave no trace. Compare your code with the examples for [listeners](../reference/detection/listeners.md), [jobs](../reference/detection/jobs.md), [routes](../reference/detection/routes.md) and the rest under [What Loom detects](../reference/detection/index.md). A line marked `✗ not recorded` is a form Loom skips.

**3. Check that the file was scanned.** `php artisan loom:scan -v` lists files skipped for syntax errors. Code outside [`scan.paths`](../reference/scan-config.md), or under `scan.exclude`, is never read.

If your code matches a form the docs say is read and still doesn't show up, [open an issue](https://github.com/lucasp1337/laravel-loom/issues) with the snippet.
