<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Scanners\Visitors\EloquentListenStringVisitor;
use Lucasp\Loom\Scanners\Visitors\ObserveCallVisitor;
use Lucasp\Loom\Scanners\Visitors\ObservedByAttributeVisitor;
use Lucasp\Loom\Scanners\Visitors\ObserverClassVisitor;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;
use Lucasp\Loom\Support\Sorting;

/**
 * Discovers Eloquent observers across three independent paths
 * (`#[ObservedBy]`, `Model::observe()`, `Event::listen('eloquent.*')`) and
 * emits both `observers[]` and `model_events[]` payloads.
 *
 * See docs/scanners/observers.md for the full design.
 */
final class ObserverScanner implements Scanner
{
    use ScannerFilesystem;

    private const REGISTRATION_ATTRIBUTE = 'attribute';

    private const REGISTRATION_OBSERVE_CALL = 'observe_call';

    private AstWalker $walker;

    private Psr4ClassLocator $locator;

    public function __construct(?AstWalker $walker = null, ?Psr4ClassLocator $locator = null)
    {
        $this->walker = $walker ?? new AstWalker;
        $this->locator = $locator ?? new Psr4ClassLocator;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function scan(string $appRoot): array
    {
        $appDir = $appRoot.DIRECTORY_SEPARATOR.'app';
        if (! is_dir($appDir)) {
            return ['observers' => [], 'model_events' => []];
        }

        /** @var array<string, array{file: string, line: int, hooks: array<int, string>}> $classMap */
        $classMap = [];

        /** @var array<int, array{model: string, observer: string, registration: string}> $observerRegs */
        $observerRegs = [];

        /** @var array<int, array{model: string, hook: string, handler: string, method: string}> $listenEntries */
        $listenEntries = [];

        foreach ($this->iteratePhpFiles($appDir) as $file) {
            $classVisitor = new ObserverClassVisitor;
            $attrVisitor = new ObservedByAttributeVisitor;
            $observeVisitor = new ObserveCallVisitor;
            $listenVisitor = new EloquentListenStringVisitor;

            $this->walker->walk($file->getPathname(), [
                $classVisitor,
                $attrVisitor,
                $observeVisitor,
                $listenVisitor,
            ]);

            $relative = $this->relativePath($appRoot, $file->getPathname());

            foreach ($classVisitor->getClasses() as $class) {
                $fqcn = $class['fqcn'];
                $classMap[$fqcn] = [
                    'file' => $relative,
                    'line' => $class['line'],
                    'hooks' => $classVisitor->getHooks($fqcn),
                ];
            }

            foreach ($attrVisitor->getPairs() as $pair) {
                foreach ($pair['observers'] as $observerFqcn) {
                    $observerRegs[] = [
                        'model' => $pair['model'],
                        'observer' => $observerFqcn,
                        'registration' => self::REGISTRATION_ATTRIBUTE,
                    ];
                }
            }

            foreach ($observeVisitor->getPairs() as $pair) {
                foreach ($pair['observers'] as $observerFqcn) {
                    $observerRegs[] = [
                        'model' => $pair['model'],
                        'observer' => $observerFqcn,
                        'registration' => self::REGISTRATION_OBSERVE_CALL,
                    ];
                }
            }

            foreach ($listenVisitor->getEntries() as $entry) {
                $listenEntries[] = [
                    'model' => $entry['model'],
                    'hook' => $entry['hook'],
                    'handler' => $entry['handler'],
                    'method' => $entry['method'],
                ];
            }
        }

        $observers = $this->mergeObservers($appRoot, $observerRegs, $classMap);
        $modelEvents = $this->buildModelEvents($observers, $listenEntries);

        return [
            'observers' => $this->emitObservers($observers),
            'model_events' => $this->emitModelEvents($modelEvents),
        ];
    }

    /**
     * Dedupe `(observer, model)` registration tuples and resolve observer file
     * locations. Precedence: `attribute > observe_call`. Observers whose file
     * cannot be located on disk are silently dropped.
     *
     * @param  array<int, array{model: string, observer: string, registration: string}>  $regs
     * @param  array<string, array{file: string, line: int, hooks: array<int, string>}>  $classMap
     * @return array<string, array{fqcn: string, observes: string, file: string, line: int, hooks: array<int, string>, registration: string}>
     *                                                                                                                                        keyed by "{observer}|{model}"
     */
    private function mergeObservers(string $appRoot, array $regs, array $classMap): array
    {
        /** @var array<string, string> $registrationByPair pair-key => registration */
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
                // Observer file unlocatable — schema requires file/line; drop.
                continue;
            }

            $result[$key] = [
                'fqcn' => $observer,
                'observes' => $model,
                'file' => $location['file'],
                'line' => $location['line'],
                'hooks' => $location['hooks'],
                'registration' => $registration,
            ];
        }

        return $result;
    }

    private function precedence(string $registration): int
    {
        return match ($registration) {
            self::REGISTRATION_ATTRIBUTE => 2,
            self::REGISTRATION_OBSERVE_CALL => 1,
            default => 0,
        };
    }

    /**
     * Build `(model, hook) => handled_by[]` from observer hooks (one entry per
     * matching observer hook) plus path C handlers (always one entry each).
     *
     * @param  array<string, array{fqcn: string, observes: string, file: string, line: int, hooks: array<int, string>, registration: string}>  $observers
     * @param  array<int, array{model: string, hook: string, handler: string, method: string}>  $listenEntries
     * @return array<string, array{model: string, event: string, handled_by: array<int, string>}>
     */
    private function buildModelEvents(array $observers, array $listenEntries): array
    {
        /** @var array<string, array{model: string, event: string, handled_by: array<string, true>}> $acc */
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
                $acc[$key]['handled_by'][$observer['fqcn'].'::'.$hook] = true;
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
            $acc[$key]['handled_by'][$entry['handler'].'::'.$entry['method']] = true;
        }

        $out = [];
        foreach ($acc as $key => $data) {
            if ($data['handled_by'] === []) {
                continue;
            }
            $handlers = array_keys($data['handled_by']);
            sort($handlers);
            $out[$key] = [
                'model' => $data['model'],
                'event' => $data['event'],
                'handled_by' => $handlers,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array{fqcn: string, observes: string, file: string, line: int, hooks: array<int, string>, registration: string}>  $observers
     * @return array<int, array<string, mixed>>
     */
    private function emitObservers(array $observers): array
    {
        $values = array_values($observers);
        usort($values, Sorting::byKeys(['fqcn', 'observes']));

        $entries = [];
        foreach ($values as $observer) {
            $hooks = $observer['hooks'];
            sort($hooks);
            $entries[] = [
                'fqcn' => $observer['fqcn'],
                'file' => $observer['file'],
                'line' => $observer['line'],
                'observes' => $observer['observes'],
                'registration' => $observer['registration'],
                'hooks' => $hooks,
                'dispatches' => [],
            ];
        }

        return $entries;
    }

    /**
     * @param  array<string, array{model: string, event: string, handled_by: array<int, string>}>  $modelEvents
     * @return array<int, array<string, mixed>>
     */
    private function emitModelEvents(array $modelEvents): array
    {
        $entries = [];
        foreach ($modelEvents as $data) {
            $entries[] = [
                'id' => 'eloquent.'.$data['event'].': '.$data['model'],
                'kind' => 'model_event',
                'model' => $data['model'],
                'event' => $data['event'],
                'handled_by' => $data['handled_by'],
            ];
        }

        usort($entries, Sorting::byKeys(['id']));

        return $entries;
    }

    /**
     * @return array{file: string, line: int, hooks: array<int, string>}|null
     */
    private function locateByPsr4Guess(string $appRoot, string $fqcn): ?array
    {
        $absolute = $this->locator->locate($appRoot, $fqcn);
        if ($absolute === null) {
            return null;
        }

        $visitor = new ObserverClassVisitor;
        $this->walker->walk($absolute, [$visitor]);

        $class = AstHelpers::findClass($visitor->getClasses(), $fqcn);
        if ($class === null) {
            return null;
        }

        return [
            'file' => $this->relativePath($appRoot, $absolute),
            'line' => $class['line'],
            'hooks' => $visitor->getHooks($fqcn),
        ];
    }
}
