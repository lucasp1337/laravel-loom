# Why was my code missed?

Loom reads your source without booting the app. When an entry is missing or a link is empty, work through these steps in order.

**1. Look for the line in the unresolved lists.** Loom lists a call it recognised but could not resolve, with its file and line:

```bash
php artisan loom:scan
jq '.unresolved_dispatches[], .unresolved_routes[] | select(.file == "app/Http/Controllers/OrderController.php")' storage/loom/index.json
```

Find the entry's `reason` in the [reason table](../reference/detection/index.md#unresolved-reasons). It links to the example that produces that reason, next to the form Loom does read.

**2. Find your code shape on the primitive's page.** Not every miss is listed: some forms leave no trace. Open the page for what you expected ([listeners](../reference/detection/listeners.md), [jobs](../reference/detection/jobs.md), [routes](../reference/detection/routes.md) and so on, all listed under [What Loom detects](../reference/detection/index.md)) and compare your code with the examples. A line marked `✗ not recorded` is a form Loom skips.

**3. Check that the file was scanned.** `php artisan loom:scan -v` lists files skipped for syntax errors. Code outside [`scan.paths`](../reference/scan-config.md), or under `scan.exclude`, is never read.

If your code matches a form the docs say is read and it still does not show up, [open an issue](https://github.com/lucasp1337/laravel-loom/issues) with the snippet.
