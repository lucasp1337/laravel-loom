<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\JobEntry;
use Lucasp\Loom\Scanners\Discovery\ClassPrimitiveDiscovery;
use Lucasp\Loom\Scanners\Discovery\JobClassSpec;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScanScope;

/**
 * Discovers job classes under app/Jobs/ plus dispatch-site targets that
 * resolve via PSR-4 to a class under app/. See {@see JobClassSpec}.
 *
 * Matches every concrete class under Jobs/ and the target of `dispatch(...)`,
 * `Bus::dispatch(...)`, `Bus::chain/batch([...])` items and the Dispatchable
 * statics, which is how jobs in DDD-style layouts outside Jobs/ are found. A
 * target wrapped in a fluent chain resolves through it
 * (`dispatch((new X)->delay(60))`, `X::dispatch()->onQueue('high')`); only
 * `new X` and `X::class` receivers resolve, not variables.
 *
 * `queued` is true when the class implements `ShouldQueue` directly or through
 * a parent indexed under app/ (see {@see ClassHierarchyResolver}); a vendor
 * parent is opaque. `queue_config` carries the class-level scalar
 * `$connection`, `$queue`, `$delay`, `$tries`, `$timeout` and `$backoff`
 * literals, `null` for a key the class does not declare, and is `null` for a
 * class that is not queued. Methods such as `backoff()` are not read.
 *
 * @internal
 */
final class JobsScanner implements Scanner
{
    private AstWalker $walker;

    private Psr4ClassLocator $locator;

    public function __construct(?AstWalker $walker = null, ?Psr4ClassLocator $locator = null, private readonly ?ScanScope $scope = null)
    {
        $this->walker = $walker ?? new AstWalker;
        $this->locator = $locator ?? new Psr4ClassLocator;
    }

    /**
     * @return array{jobs: list<JobEntry>}
     */
    public function scan(string $appRoot): array
    {
        $discovery = new ClassPrimitiveDiscovery($this->walker, $this->locator, $this->scope);

        return ['jobs' => $discovery->discover($appRoot, new JobClassSpec)];
    }
}
