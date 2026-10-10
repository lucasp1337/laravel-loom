<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\MailableEntry;
use Lucasp\Loom\Scanners\Discovery\ClassPrimitiveDiscovery;
use Lucasp\Loom\Scanners\Discovery\MailableClassSpec;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScanScope;

/**
 * Discovers mailable classes under app/Mail/ plus dispatch-site targets
 * that resolve via PSR-4. See {@see MailableClassSpec}.
 *
 * Matches every concrete class under Mail/ and the mailable argument of
 * `Mail::send/sendNow/queue/onQueue/queueOn/later/laterOn(...)`, including the
 * `Mail::to()/cc()/bcc()/locale()/mailer()` receiver chains before the
 * terminal call. The argument may carry its own fluent chain
 * (`Mail::send((new X)->onConnection('redis'))`); a variable argument does not
 * resolve. Queue state and `queue_config` follow the same rules as jobs.
 *
 * @internal
 */
final class MailableScanner implements Scanner
{
    private AstWalker $walker;

    private Psr4ClassLocator $locator;

    public function __construct(?AstWalker $walker = null, ?Psr4ClassLocator $locator = null, private readonly ?ScanScope $scope = null)
    {
        $this->walker = $walker ?? new AstWalker;
        $this->locator = $locator ?? new Psr4ClassLocator;
    }

    /**
     * @return array{mailables: list<MailableEntry>}
     */
    public function scan(string $appRoot): array
    {
        $discovery = new ClassPrimitiveDiscovery($this->walker, $this->locator, $this->scope);

        return ['mailables' => $discovery->discover($appRoot, new MailableClassSpec)];
    }
}
