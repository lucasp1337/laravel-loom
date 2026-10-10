<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\EventEntry;
use Lucasp\Loom\Scanners\Discovery\ClassPrimitiveDiscovery;
use Lucasp\Loom\Scanners\Discovery\EventClassSpec;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScanScope;

/**
 * Discovers event classes under app/Events/ plus targets reached from
 * statically resolvable dispatch sites elsewhere in app/.
 *
 * Matches (see {@see EventClassSpec} for the rules):
 * - every top-level class under Events/, abstract ones included; interfaces,
 *   traits and anonymous classes are skipped; several classes in one file each
 *   count;
 * - the target of `event(new X)` / `event(X::class)` (extra arguments ignored),
 *   `broadcast(...)`, `Event::dispatch(...)`, and `X::dispatch/dispatchIf/
 *   dispatchUnless(...)`. `broadcast_if/unless` are dispatch sites but not
 *   discovery seeds;
 * - the `Foo::class` values of a model's `$dispatchesEvents`.
 *
 * A seeded target outside Events/ is located through PSR-4 (see
 * {@see ClassPrimitiveDiscovery}) and kept only when it is inside a scan
 * directory, not excluded, and declares that FQCN. The Dispatchable form is
 * accepted only for a class under Events/, otherwise every Dispatchable job
 * would land in `events[]`. When both paths find a class the directory walk
 * wins for file and line. `dispatched_from` and `handled_by` are left empty for
 * the cross-link pass; `id` equals `fqcn`.
 *
 * @internal
 */
final class EventScanner implements Scanner
{
    private AstWalker $walker;

    private Psr4ClassLocator $locator;

    public function __construct(?AstWalker $walker = null, ?Psr4ClassLocator $locator = null, private readonly ?ScanScope $scope = null)
    {
        $this->walker = $walker ?? new AstWalker;
        $this->locator = $locator ?? new Psr4ClassLocator;
    }

    /**
     * @return array{events: list<EventEntry>}
     */
    public function scan(string $appRoot): array
    {
        $discovery = new ClassPrimitiveDiscovery($this->walker, $this->locator, $this->scope);

        return ['events' => $discovery->discover($appRoot, new EventClassSpec)];
    }
}
