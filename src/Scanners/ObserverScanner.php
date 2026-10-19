<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\ClosureListenerEntry;
use Lucasp\Loom\Dto\EloquentListenRecord;
use Lucasp\Loom\Dto\ModelEventEntry;
use Lucasp\Loom\Dto\ModelEventHandler;
use Lucasp\Loom\Dto\ObserverEntry;
use Lucasp\Loom\Dto\SourceLocation;
use Lucasp\Loom\Dto\UnresolvedDispatchEntry;
use Lucasp\Loom\Index\ListenerRegistration;
use Lucasp\Loom\Index\ModelHook;
use Lucasp\Loom\Index\ObserverRegistration;
use Lucasp\Loom\Scanners\Visitors\EloquentListenStringVisitor;
use Lucasp\Loom\Scanners\Visitors\ModelHookCallVisitor;
use Lucasp\Loom\Scanners\Visitors\ObserveCallVisitor;
use Lucasp\Loom\Scanners\Visitors\ObservedByAttributeVisitor;
use Lucasp\Loom\Scanners\Visitors\ObserverClassVisitor;
use Lucasp\Loom\Support\Ast\AstWalker;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\EloquentEventName;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;
use Lucasp\Loom\Support\ScanScope;
use Lucasp\Loom\Support\Sorting;

/**
 * Discovers Eloquent observers via `#[ObservedBy]`, `Model::observe()`, and
 * `Event::listen('eloquent.*')`. Emits both observers[] and model_events[].
 * Registrations are read from the scan paths and from the providers that
 * `bootstrap/providers.php` and `config/app.php` list.
 *
 * `observers[]` has one entry per (observer, model) pair from the attribute and
 * the `observe()` call; when both exist the attribute wins. An observer's
 * `hooks` are the methods named after an observable Eloquent event, of any
 * visibility and declared, inherited or trait-provided (a vendor parent is
 * opaque), because Laravel registers an observer method whenever
 * `method_exists` is true. `booting` and `booted` are fired as model events but
 * are not observable, so they are never hooks. An observer with no matching
 * method is still emitted with `hooks: []`.
 *
 * Both sections are written here and nowhere else: the cross-link pass reads
 * `model_events[]` but never regenerates it.
 *
 * `model_events[]` has one entry per (model, hook) that has at least one
 * handler: observer hook methods, the `Event::listen('eloquent.*')` strings
 * and `$listen` keys, and model hook registrations (`static::creating(...)`,
 * see {@see ModelHookCallVisitor}), deduplicated on `handler::method` with the
 * observer winning. A pair with no handler is not emitted.
 *
 * A closure model hook is a handler with method `{closure}` on the model, and
 * a `closure_listeners[]` entry (registration `model_hook`, event
 * `eloquent.{hook}: {Model}`) that carries the dispatches inside it. A hook
 * registered in a trait is expanded to every class that uses the trait. A hook
 * or `eloquent.*` registration that cannot be read is emitted in
 * `unresolved_dispatches`.
 *
 * @internal
 */
final class ObserverScanner implements Scanner
{
    use ScannerFilesystem;

    private AstWalker $walker;

    private Psr4ClassLocator $locator;

    public function __construct(?AstWalker $walker = null, ?Psr4ClassLocator $locator = null, ?ScanScope $scope = null)
    {
        $this->walker = $walker ?? new AstWalker;
        $this->locator = $locator ?? new Psr4ClassLocator;
        $this->scope = $scope;
    }

    /**
     * @return array{observers: list<ObserverEntry>, model_events: list<ModelEventEntry>, closure_listeners: list<ClosureListenerEntry>, unresolved_dispatches: list<UnresolvedDispatchEntry>}
     */
    public function scan(string $appRoot): array
    {
        /** @var array<string, array{file: string, line: int}> $classMap */
        $classMap = [];

        /** @var array<int, array{model: string, observer: string, registration: ObserverRegistration}> $observerRegs */
        $observerRegs = [];

        /** @var array<int, array{model: string, hook: string, handler: string, method: string, file: string, line: int}> $listenEntries */
        $listenEntries = [];

        /** @var list<array{record: EloquentListenRecord, file: string}> $hookRecords */
        $hookRecords = [];
        /** @var list<UnresolvedDispatchEntry> $unresolved */
        $unresolved = [];

        foreach ($this->registrationFiles($appRoot, $this->walker) as $file) {
            $hookVisitor = new ModelHookCallVisitor;
            $classVisitor = new ObserverClassVisitor;
            $attrVisitor = new ObservedByAttributeVisitor;
            $observeVisitor = new ObserveCallVisitor;
            $listenVisitor = new EloquentListenStringVisitor;

            $this->walker->walk($file->getPathname(), [
                $classVisitor,
                $attrVisitor,
                $observeVisitor,
                $listenVisitor,
                $hookVisitor,
            ]);

            $relative = $this->relativePath($appRoot, $file->getPathname());

            foreach ([...$listenVisitor->getUnresolved(), ...$hookVisitor->getUnresolved()] as $record) {
                $unresolved[] = new UnresolvedDispatchEntry($relative, $record->line, $record->expression, $record->reason);
            }
            foreach ($hookVisitor->getEntries() as $record) {
                $hookRecords[] = ['record' => $record, 'file' => $relative];
            }

            foreach ($classVisitor->getClasses() as $class) {
                $classMap[$class->fqcn] = [
                    'file' => $relative,
                    'line' => $class->line,
                ];
            }

            foreach ($attrVisitor->getPairs() as $pair) {
                foreach ($pair->observers as $observerFqcn) {
                    $observerRegs[] = [
                        'model' => $pair->model,
                        'observer' => $observerFqcn,
                        'registration' => ObserverRegistration::ATTRIBUTE,
                    ];
                }
            }

            foreach ($observeVisitor->getPairs() as $pair) {
                foreach ($pair->observers as $observerFqcn) {
                    $observerRegs[] = [
                        'model' => $pair->model,
                        'observer' => $observerFqcn,
                        'registration' => ObserverRegistration::OBSERVE_CALL,
                    ];
                }
            }

            foreach ($listenVisitor->getEntries() as $entry) {
                $listenEntries[] = [
                    'model' => $entry->model,
                    'hook' => $entry->hook,
                    'handler' => $entry->handler,
                    'method' => $entry->method,
                    'file' => $relative,
                    'line' => $entry->line,
                ];
            }
        }

        $resolver = new ClassHierarchyResolver($appRoot, $this->walker, $this->scope());
        $closureListeners = [];
        foreach ($this->expandTraitHooks($hookRecords, $classMap, $resolver) as $hook) {
            $record = $hook['record'];
            $listenEntries[] = [
                'model' => $record->model,
                'hook' => $record->hook,
                'handler' => $record->handler,
                'method' => $record->method,
                'file' => $hook['file'],
                'line' => $record->line,
            ];
            if ($record->isClosure()) {
                $closureListeners[] = new ClosureListenerEntry(
                    event: EloquentEventName::make(ModelHook::from($record->hook), $record->model),
                    file: $hook['file'],
                    line: $record->line,
                    endLine: (int) $record->endLine,
                    registration: ListenerRegistration::MODEL_HOOK,
                    queued: false,
                );
            }
        }

        $observers = $this->mergeObservers($appRoot, $observerRegs, $classMap, $resolver);
        $modelEvents = $this->buildModelEvents($observers, $listenEntries);

        return [
            'observers' => $this->emitObservers($observers),
            'model_events' => $this->emitModelEvents($modelEvents),
            'closure_listeners' => $closureListeners,
            'unresolved_dispatches' => $unresolved,
        ];
    }

    /**
     * A hook registered in a trait runs for every class that uses it, so each
     * such class gets its own record; a trait nothing uses yields none.
     *
     * @param  list<array{record: EloquentListenRecord, file: string}>  $hooks
     * @param  array<string, array{file: string, line: int}>  $classMap
     * @return list<array{record: EloquentListenRecord, file: string}>
     */
    private function expandTraitHooks(array $hooks, array $classMap, ClassHierarchyResolver $resolver): array
    {
        $expanded = [];
        foreach ($hooks as $hook) {
            $record = $hook['record'];
            if (! $record->viaTrait) {
                $expanded[] = $hook;

                continue;
            }

            foreach (array_keys($classMap) as $class) {
                if (! $resolver->isInstantiable($class) || ! collect($resolver->traitsAll($class))->contains($record->model)) {
                    continue;
                }
                $expanded[] = [
                    'record' => new EloquentListenRecord(
                        model: $class,
                        hook: $record->hook,
                        handler: $record->isClosure() ? $class : $record->handler,
                        method: $record->method,
                        line: $record->line,
                        endLine: $record->endLine,
                    ),
                    'file' => $hook['file'],
                ];
            }
        }

        return $expanded;
    }

    /**
     * Precedence: attribute > observe_call. Unlocatable observers dropped.
     *
     * @param  array<int, array{model: string, observer: string, registration: ObserverRegistration}>  $regs
     * @param  array<string, array{file: string, line: int}>  $classMap
     * @return array<string, array{fqcn: string, observes: string, file: string, line: int, hooks: list<string>, registration: ObserverRegistration}>
     */
    private function mergeObservers(string $appRoot, array $regs, array $classMap, ClassHierarchyResolver $resolver): array
    {
        /** @var array<string, ObserverRegistration> $registrationByPair */
        $registrationByPair = [];

        foreach ($regs as $reg) {
            $key = $reg['observer'].'|'.$reg['model'];
            $existing = $registrationByPair[$key] ?? null;
            if ($existing === null) {
                $registrationByPair[$key] = $reg['registration'];

                continue;
            }
            if ($this->precedence($reg['registration']) > $this->precedence($existing)) {
                $registrationByPair[$key] = $reg['registration'];
            }
        }

        $result = [];
        foreach ($registrationByPair as $key => $registration) {
            [$observer, $model] = explode('|', $key, 2);

            $location = $classMap[$observer] ?? $this->locateByPsr4Guess($appRoot, $observer);
            if ($location === null) {
                continue;
            }

            $result[$key] = [
                'fqcn' => $observer,
                'observes' => $model,
                'file' => is_array($location) ? $location['file'] : $location->file,
                'line' => is_array($location) ? $location['line'] : $location->line,
                'hooks' => $this->hooksOf($resolver, $observer),
                'registration' => $registration,
            ];
        }

        return $result;
    }

    /**
     * Observable Eloquent events the observer has a method for. Laravel
     * registers an observer method with `method_exists`, so declared,
     * inherited and trait methods all count, whatever their visibility.
     *
     * @return list<string>
     */
    private function hooksOf(ClassHierarchyResolver $resolver, string $observerFqcn): array
    {
        $methods = $resolver->effectiveMethods($observerFqcn);

        $hooks = [];
        foreach (ModelHook::observableValues() as $hook) {
            if (isset($methods[strtolower($hook)])) {
                $hooks[] = $hook;
            }
        }
        $hooks = array_values(collect($hooks)->sort()->all());

        return $hooks;
    }

    private function precedence(ObserverRegistration $registration): int
    {
        return match ($registration) {
            ObserverRegistration::ATTRIBUTE => 2,
            ObserverRegistration::OBSERVE_CALL => 1,
        };
    }

    /**
     * @param  array<string, array{fqcn: string, observes: string, file: string, line: int, hooks: list<string>, registration: ObserverRegistration}>  $observers
     * @param  array<int, array{model: string, hook: string, handler: string, method: string, file: string, line: int}>  $listenEntries
     * @return array<string, array{model: string, event: string, handled_by: list<ModelEventHandler>}>
     */
    private function buildModelEvents(array $observers, array $listenEntries): array
    {
        /** @var array<string, array{model: string, event: string, handled_by: array<string, ModelEventHandler>}> $acc */
        $acc = [];

        foreach ($observers as $observer) {
            foreach ($observer['hooks'] as $hook) {
                $key = $observer['observes'].'|'.$hook;
                if (! isset($acc[$key])) {
                    $acc[$key] = [
                        'model' => $observer['observes'],
                        'event' => $hook,
                        'handled_by' => [],
                    ];
                }
                $acc[$key]['handled_by'][$observer['fqcn'].'::'.$hook] = new ModelEventHandler(
                    handler: $observer['fqcn'],
                    method: $hook,
                    file: $observer['file'],
                    line: $observer['line'],
                );
            }
        }

        foreach ($listenEntries as $entry) {
            $key = $entry['model'].'|'.$entry['hook'];
            if (! isset($acc[$key])) {
                $acc[$key] = [
                    'model' => $entry['model'],
                    'event' => $entry['hook'],
                    'handled_by' => [],
                ];
            }
            // Several closures can share a method name, so they are told apart by location.
            $handlerKey = $entry['method'] === EloquentListenRecord::CLOSURE_METHOD
                ? $entry['handler'].'::'.$entry['method'].'@'.$entry['file'].':'.$entry['line']
                : $entry['handler'].'::'.$entry['method'];
            $acc[$key]['handled_by'][$handlerKey] ??= new ModelEventHandler(
                handler: $entry['handler'],
                method: $entry['method'],
                file: $entry['file'],
                line: $entry['line'],
            );
        }

        $out = [];
        foreach ($acc as $key => $data) {
            if ($data['handled_by'] === []) {
                continue;
            }
            $handlers = $data['handled_by'];
            $handlers = collect($handlers)->sortKeys()->all();
            $handlers = array_values($handlers);
            $out[$key] = [
                'model' => $data['model'],
                'event' => $data['event'],
                'handled_by' => $handlers,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array{fqcn: string, observes: string, file: string, line: int, hooks: list<string>, registration: ObserverRegistration}>  $observers
     * @return list<ObserverEntry>
     */
    private function emitObservers(array $observers): array
    {
        $values = array_values($observers);
        $values = array_values(collect($values)->sort(Sorting::byKeys(['fqcn', 'observes']))->all());

        $entries = [];
        foreach ($values as $observer) {
            $hooks = $observer['hooks'];
            $hooks = array_values(collect($hooks)->sort()->all());
            $entries[] = new ObserverEntry(
                fqcn: $observer['fqcn'],
                file: $observer['file'],
                line: $observer['line'],
                observes: $observer['observes'],
                registration: $observer['registration'],
                hooks: $hooks,
            );
        }

        return $entries;
    }

    /**
     * @param  array<string, array{model: string, event: string, handled_by: list<ModelEventHandler>}>  $modelEvents
     * @return list<ModelEventEntry>
     */
    private function emitModelEvents(array $modelEvents): array
    {
        $entries = [];
        foreach ($modelEvents as $data) {
            $entries[] = new ModelEventEntry(
                id: 'eloquent.'.$data['event'].': '.$data['model'],
                model: $data['model'],
                event: $data['event'],
                handledBy: $data['handled_by'],
            );
        }

        $entries = array_values(collect($entries)->sort(fn (ModelEventEntry $a, ModelEventEntry $b): int => $a->id <=> $b->id)->all());

        return $entries;
    }

    private function locateByPsr4Guess(string $appRoot, string $fqcn): ?SourceLocation
    {
        $absolute = $this->locator->locate($appRoot, $fqcn);
        if ($absolute === null || ! $this->scope()->admits($appRoot, $absolute)) {
            return null;
        }

        $visitor = new ObserverClassVisitor;
        $this->walker->walk($absolute, [$visitor]);

        foreach ($visitor->getClasses() as $class) {
            if ($class->fqcn !== $fqcn) {
                continue;
            }

            return new SourceLocation(
                file: $this->relativePath($appRoot, $absolute),
                line: $class->line,
            );
        }

        return null;
    }
}
