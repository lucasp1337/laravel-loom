# RouteScanner

Discovers HTTP routes registered in `routes/*.php` and emits the
`routes[]` section of the index.

This is **slice 1** of issue #8 — basic route discovery only. The
`middleware[]` and `dispatches[]` cross-links from the full proposal are
deferred to follow-up PRs (see [Known limitations](#known-limitations)).

## What it detects

RouteScanner statically parses every `*.php` file under the app's
`routes/` directory (no Laravel boot) and captures each route
registration it can resolve. The recognised root forms are:

1. **Verb methods** — `Route::get('/uri', action)` and the same for
   `post`, `put`, `patch`, `delete`, and `options`. One entry per call,
   `method` set to the uppercased verb.

2. **`Route::any('/uri', action)`** — one entry with `method` set to
   `ANY`.

3. **`Route::match([...], '/uri', action)`** — the first argument is a
   list of verb strings. One entry is emitted **per listed verb**, each
   sharing the same `uri`, `name`, and controller fields.

The route name is captured from a chained `->name('...')` link on the
registration.

### Action forms

The action argument (the last positional argument, or the second for
`match`) is resolved into `controller_fqcn` / `controller_method`:

- **Tuple** — `[UserController::class, 'show']` →
  `controller_fqcn: "App\\Http\\Controllers\\UserController"`,
  `controller_method: "show"`.
- **Invokable** — bare `InvokeController::class` or single-element
  `[InvokeController::class]` →
  `controller_method: "__invoke"`.
- **Legacy string** — `'UserController@show'` is split on `@` into FQCN
  and method.
- **Closure** — `function () { ... }` or `fn () => ...` → both controller
  fields `null`.

## Output

One entry per route (one **per verb** for `match`), conforming to
`$defs/route`:

```json
{
  "method": "GET",
  "uri": "/users/{id}",
  "name": "users.show",
  "controller_fqcn": "App\\Http\\Controllers\\UserController",
  "controller_method": "show",
  "file": "routes/web.php",
  "line": 14
}
```

Field semantics:

- **`method`** — HTTP verb, uppercased. One of `GET`, `POST`, `PUT`,
  `PATCH`, `DELETE`, `OPTIONS`, or `ANY` (the synthetic verb for
  `Route::any`). A `Route::match(['get', 'post'], ...)` registration
  yields two entries, `GET` and `POST`.
- **`uri`** — the route URI exactly as written in the first string
  argument (e.g. `/users/{id}`). Group prefixes are **not** applied — see
  [Known limitations](#known-limitations).
- **`name`** — the named-route name from `->name('...')`, or `null` when
  the registration carries no `->name()` link (or its argument is not a
  statically-resolvable string literal).
- **`controller_fqcn`** — the fully-qualified controller class for the
  action, or `null` for closures and unresolvable actions.
- **`controller_method`** — the action method; `__invoke` for invokable
  controllers; `null` when `controller_fqcn` is `null`.
- **`file`** — path to the route file, relative to the app root (e.g.
  `routes/web.php`).
- **`line`** — 1-indexed line of the route registration.

`stats.routes` is added to the top-level stats block as the count of
entries.

## Expected behavior

- **Verb form**: `Route::get('/users', [UserController::class, 'index'])`
  — one entry, `method: "GET"`, controller fields resolved.
- **`any` form**: `Route::any('/webhook', [HookController::class, 'handle'])`
  — one entry, `method: "ANY"`.
- **`match` form**: `Route::match(['get', 'post'], '/search', [SearchController::class, 'run'])`
  — two entries (`GET` and `POST`), identical except for `method`.
- **Invokable controller**: `Route::get('/dashboard', DashboardController::class)`
  or `Route::get('/dashboard', [DashboardController::class])` — one entry,
  `controller_method: "__invoke"`.
- **Legacy string action**: `Route::get('/users', 'UserController@index')`
  — split on `@` into FQCN and method.
- **Named route**: `Route::get('/users/{id}', [UserController::class, 'show'])->name('users.show')`
  — `name: "users.show"`.
- **Closure action**: `Route::get('/ping', fn () => 'pong')` — one entry,
  both controller fields `null`.

## Known limitations

These are deliberate scope boundaries for slice 1, not bugs — each is
planned follow-up work.

- **Group prefixes / middleware not applied.** A route nested in
  `Route::prefix('admin')->group(function () { Route::get('/panel', ...); })`
  is captured with its own leaf `uri` (`/panel`, not `/admin/panel`). The
  group / `prefix` / `middleware` chains themselves emit nothing.
- **`Route::resource()` / `Route::apiResource()` not expanded.** These
  emit no entries; the implicit CRUD routes they generate are not
  synthesized. Deferred.
- **No `middleware[]` or `dispatches[]` cross-links.** The full #8
  proposal links each route to its middleware stack and to dispatch sites
  inside its handler. Neither is in this slice — both are follow-up PRs.
- **Attribute routing not handled.** `#[Route(...)]` attributes on
  controller methods are invisible to this slice.
- **Unresolvable actions emit null controller fields.** When the action
  is stored in a variable or is otherwise not a statically-resolvable
  tuple / class-constant / string, `controller_fqcn` and
  `controller_method` are both `null` rather than guessed.
</content>
</invoke>
