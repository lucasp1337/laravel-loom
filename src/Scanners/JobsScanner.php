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
 * resolve via PSR-4 to a class under app/.
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
