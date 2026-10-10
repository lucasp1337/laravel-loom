## Laravel Loom

Loom keeps an index of this app's events, listeners, observers, jobs, routes and scheduled tasks, and who fires what. Ask it before you grep.

### When to use it

- Before you change, rename or remove an event, listener, job, observer, route or scheduled task, call the Loom MCP tools to see what it touches.
- After you change any of them, run `php artisan loom:scan` so the index matches the code.

### Which tool answers what

- `impact-of-change` (`fqcn`, `change` = `remove` or `rename`): what a change to a class affects.
- `events-following` (`event_fqcn`, `depth`): what an event sets off, transitively.
- `route-to-events` (`method`, `uri`, `depth`): what an HTTP route sets off.
- `handlers-for` / `dispatch-sites-for` (`event_fqcn`): who handles an event, where it is fired.
- `find-orphans`: events and listeners nothing connects to.
- `find-unresolved-dispatches`: dispatches Loom could not trace to a class.
- `list-entities` (`section`) and `get-entity` (`kind`, `fqcn`): look up names and full records.

### Honesty rules

- A dispatch listed in `unresolved_dispatches` has no known target. Do not guess one; read the code at the reported file and line.
- An event reported as an orphan may be fired by an unresolved dispatch. Check `find-unresolved-dispatches` before calling it dead.
- Only report what a tool returned. If a class is `unknown`, say Loom does not know it.
