# RouteScanner

Discovers HTTP routes registered in `routes/*.php` and emits the
`routes[]` section of the index.

Route discovery covers individual registrations and the context of any
enclosing `Route::group(...)` — prefix, name prefix, default controller,
and middleware (see [Route groups](#route-groups) and
[Middleware](#middleware)). `Route::resource()` / `Route::apiResource()`
registrations are expanded into their constituent CRUD routes (see
[Resource controllers](#resource-controllers)). The `dispatches[]`
cross-links from the full proposal and middleware-group / alias resolution
are deferred to follow-up PRs (see [Known limitations](#known-limitations)).

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
  "uri": "/admin/users/{id}",
  "name": "admin.users.show",
  "controller_fqcn": "App\\Http\\Controllers\\UserController",
  "controller_method": "show",
  "middleware": ["web", "auth"],
  "file": "routes/web.php",
  "line": 14
}
```

(The `uri`, `name`, and `middleware` above reflect an enclosing
`Route::prefix('admin')->name('admin.')->middleware(['web', 'auth'])` group
applied to a leaf `Route::get('/users/{id}', ...)->name('users.show')`.)

Field semantics:

- **`method`** — HTTP verb, uppercased. One of `GET`, `POST`, `PUT`,
  `PATCH`, `DELETE`, `OPTIONS`, or `ANY` (the synthetic verb for
  `Route::any`). A `Route::match(['get', 'post'], ...)` registration
  yields two entries, `GET` and `POST`.
- **`uri`** — the route URI, with the prefixes of any enclosing
  `Route::group(...)` prepended (e.g. `/admin/users/{id}`). Always begins
  with a leading slash; the root is `/`. See
  [Route groups](#route-groups).
- **`name`** — the named-route name from `->name('...')`, with any
  enclosing group name prefix concatenated as-is (see
  [Route groups](#route-groups)); `null` when the registration carries no
  `->name()` link and no group name prefix applies (or the argument is not
  a statically-resolvable string literal).
- **`controller_fqcn`** — the fully-qualified controller class for the
  action, or `null` for closures and unresolvable actions.
- **`controller_method`** — the action method; `__invoke` for invokable
  controllers; `null` when `controller_fqcn` is `null`.
- **`middleware`** — the resolved middleware chain for the route: the
  middleware of every enclosing group (outermost first) followed by the
  route's own `->middleware(...)`, in source order, with exact-duplicate
  names deduped. `[]` when neither the route nor any enclosing group
  declares middleware. See [Middleware](#middleware).
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

### Route groups

Routes declared inside a `Route::group(...)` inherit the group's context.
Both syntaxes are supported: the array-config form
`Route::group(['prefix' => 'admin', 'as' => 'admin.', 'controller' => C::class], fn)`
and the fluent form
`Route::prefix('admin')->name('admin.')->controller(C::class)->group(fn)`.
Three pieces of context are merged into the leaf route:

- **Prefix** — the group's `prefix` segments are prepended to the route
  URI. The result always carries a leading slash, and the root is `/`.
  `Route::prefix('admin')->group(fn () => Route::get('/panel', ...))`
  yields `uri` `/admin/panel`; `Route::get('/', ...)` inside
  `prefix('admin')` yields `/admin`. Nested groups concatenate, outer to
  inner: `v2` > `users` > `/{id}` yields `/v2/users/{id}`. Ungrouped
  routes are unchanged (`/users`, `/`).

- **Name prefix** — the group's `as` / `name(...)` prefix is concatenated
  **as-is** (no separator inserted) with the leaf route's `->name(...)`.
  `name('admin.')` + leaf `->name('users')` yields `admin.users`. Nested
  prefixes chain: `admin.` > `users.` > `index` yields `admin.users.index`.
  A group name prefix with no leaf `->name()` yields just the prefix (e.g.
  `admin.`).

- **Default controller** — the group's `controller(C::class)` resolves a
  **bare method-name** string action to that controller.
  `Route::controller(UserController::class)->group(fn () => Route::get('/u', 'store'))`
  yields `controller_fqcn` `UserController`, `controller_method` `store`.
  It does **not** override an action that already names a class — an array
  tuple, a bare `Ctrl::class`, or a `'Class@method'` string keeps its own
  controller.

### Middleware

Each route's `middleware` field is the **resolved chain** Loom can see
statically: the middleware of every enclosing group, outermost group to
innermost, followed by the route's own `->middleware(...)`, in source
order. Exact-duplicate names are deduped (first occurrence wins);
otherwise order is preserved.

Recognised forms, on both the route and a group:

- Single string — `->middleware('auth')`.
- Array — `->middleware(['auth', 'verified'])`.
- Chained — `->middleware('a')->middleware('b')` accumulates `a`, `b`.
- Variadic — `->middleware('a', 'b')`.
- `::class` reference — resolved to the FQCN (e.g.
  `->middleware(EnsureTokenIsValid::class)` →
  `"App\\Http\\Middleware\\EnsureTokenIsValid"`).
- Group middleware — via the fluent form
  `Route::middleware([...])->group(...)` or the array-config form
  `Route::group(['middleware' => [...]], fn)`.

Middleware arguments with parameters are kept **verbatim**, including the
parameter list: `->middleware('throttle:60,1')` records `"throttle:60,1"`,
and `->middleware('can:update,post')` records `"can:update,post"`.

Worked example:

```php
Route::middleware(['web', 'auth'])->prefix('account')->group(function () {
    Route::get('/settings', [AccountController::class, 'settings'])
        ->middleware('verified');
});
```

The `/account/settings` route resolves to
`middleware: ["web", "auth", "verified"]` — the two group entries
(outermost first) followed by the route's own `verified`.

### Resource controllers

`Route::resource('photos', PhotoController::class)` is **expanded** into
its constituent CRUD routes — one `routes[]` entry per generated action.
A full resource expands to **seven** entries:

| Action  | method   | uri                    | name             | controller_method |
| ------- | -------- | ---------------------- | ---------------- | ----------------- |
| index   | `GET`    | `/photos`              | `photos.index`   | `index`           |
| create  | `GET`    | `/photos/create`       | `photos.create`  | `create`          |
| store   | `POST`   | `/photos`              | `photos.store`   | `store`           |
| show    | `GET`    | `/photos/{photo}`      | `photos.show`    | `show`            |
| edit    | `GET`    | `/photos/{photo}/edit` | `photos.edit`    | `edit`            |
| update  | `PUT`    | `/photos/{photo}`      | `photos.update`  | `update`          |
| destroy | `DELETE` | `/photos/{photo}`      | `photos.destroy` | `destroy`         |

`controller_fqcn` is the resource controller for every entry
(`App\Http\Controllers\PhotoController` above).

`Route::apiResource('photos', PhotoController::class)` expands to the same
set **minus** the two HTML-form actions, `create` and `edit` — **five**
entries: `index`, `store`, `show`, `update`, `destroy`.

- **Member parameter** — the `{...}` segment is Laravel's `Str::singular`
  of the resource name: `photos` → `{photo}`, `categories` →
  `{category}`. The same singular is used for `show`, `edit`, `update`,
  and `destroy`.

- **`update` verb** — emitted as `PUT`. Laravel registers `update` for
  **both** `PUT` and `PATCH`; this scanner emits a single `PUT` entry for
  it (the `PATCH` alias is not separately synthesized).

- **Group / middleware inheritance** — a `resource()` / `apiResource()`
  call inside a `Route::group(...)` (or fluent `prefix`/`name`/`middleware`
  chain) propagates the enclosing context to **all** generated sub-routes:
  the group prefix is prepended to every `uri`, the group name prefix is
  concatenated onto every `name`, and the group middleware is merged into
  every entry's `middleware` chain — exactly as for leaf routes (see
  [Route groups](#route-groups) and [Middleware](#middleware)).

- **`->only([...])` / `->except([...])`** — these filter the generated
  action set. `Route::resource('photos', ...)->only(['index', 'show'])`
  emits just the `index` and `show` entries; `->except(['create', 'edit'])`
  emits the other five. Both are honoured; if both are present Laravel's
  precedence applies.

The override forms `->names(...)`, `->parameters(...)`, `->scoped(...)`,
and `->shallow()` are **not** applied — the expansion always uses the
default names and parameters above (see
[Known limitations](#known-limitations)).

## Known limitations

These are deliberate scope boundaries, not bugs — each is planned
follow-up work.

- **Middleware groups are not expanded.** A middleware group name such as
  `web` or `api` is captured **as-is**; its constituent classes are not
  substituted in. Resolving a group to its members needs the HTTP kernel
  config, which this scanner does not read.
- **Middleware aliases are not resolved.** An alias like `auth` or
  `throttle` is recorded under its alias name, not the class it maps to.
  Alias-to-class resolution also needs kernel config.
- **`withoutMiddleware(...)` is not applied.** Middleware removed from a
  route or group via `->withoutMiddleware(...)` is **not** subtracted from
  the resolved `middleware` chain — the field reflects only what is added.
- **Resource override forms are not applied.** `->names(...)`,
  `->parameters(...)`, `->scoped(...)`, and `->shallow()` chained on a
  `resource()` / `apiResource()` registration are **ignored** — the
  expansion uses Laravel's default names and `{singular}` parameters
  regardless. See [Resource controllers](#resource-controllers).
- **Nested / dotted resource names are not specially handled.** A dotted
  name like `Route::resource('photos.comments', ...)` is treated as a flat
  resource name; the nested-parameter URIs Laravel would generate
  (`/photos/{photo}/comments/{comment}`) are **not** synthesized.
- **Batch resource forms are not expanded.** The array forms
  `Route::resources([...])` and `Route::apiResources([...])` emit no
  entries; only the single-resource `resource()` / `apiResource()` calls
  are expanded.
- **No `dispatches[]` cross-links.** The full #8 proposal links each route
  to the dispatch sites inside its handler. That cross-link is not in this
  slice — it is a follow-up PR.
- **Attribute routing not handled.** `#[Route(...)]` attributes on
  controller methods are invisible to this slice.
- **Unresolvable actions emit null controller fields.** When the action
  is stored in a variable or is otherwise not a statically-resolvable
  tuple / class-constant / string, `controller_fqcn` and
  `controller_method` are both `null` rather than guessed.
</content>
</invoke>
