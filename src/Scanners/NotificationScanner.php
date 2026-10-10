<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\NotificationEntry;
use Lucasp\Loom\Scanners\Discovery\ClassPrimitiveDiscovery;
use Lucasp\Loom\Scanners\Discovery\NotificationClassSpec;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScanScope;

/**
 * Discovers notification classes under app/Notifications/ plus
 * dispatch-site targets that resolve via PSR-4. See {@see NotificationClassSpec}.
 *
 * Matches every concrete class under Notifications/ and the notification
 * argument of `$any->notify(...)`, `$any->notifyNow(...)` (the receiver is not
 * type-resolved), `Notification::send/sendNow($to, $n, $channels)` and
 * `Notification::route(...)->notify(...)`. The argument may carry its own
 * chain (`$user->notify((new X)->locale('es'))`); modifiers on the facade
 * before `send` are not read.
 *
 * `channels[]` comes from a `via()` whose body is a single `return [...]` of
 * literal strings (lowercased) and `Class::class` constants (FQCN), in source
 * order. Any other `via()` body (conditional, property, variable or keyed
 * items) gives `channels: []` and `channels_dynamic: true`; a class that
 * declares no `via()`, or inherits one, gives `channels: []` and
 * `channels_dynamic: false`.
 *
 * @internal
 */
final class NotificationScanner implements Scanner
{
    private AstWalker $walker;

    private Psr4ClassLocator $locator;

    public function __construct(?AstWalker $walker = null, ?Psr4ClassLocator $locator = null, private readonly ?ScanScope $scope = null)
    {
        $this->walker = $walker ?? new AstWalker;
        $this->locator = $locator ?? new Psr4ClassLocator;
    }

    /**
     * @return array{notifications: list<NotificationEntry>}
     */
    public function scan(string $appRoot): array
    {
        $discovery = new ClassPrimitiveDiscovery($this->walker, $this->locator, $this->scope);

        return ['notifications' => $discovery->discover($appRoot, new NotificationClassSpec)];
    }
}
