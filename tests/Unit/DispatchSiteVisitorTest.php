<?php

declare(strict_types=1);

use Lucasp\Loom\Index\DispatchForm;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Parse a PHP source string and run DispatchSiteVisitor (after NameResolver) over it.
 *
 * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
 */
function runDispatchSiteVisitor(string $source): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($source);

    expect($ast)->not->toBeNull();

    $visitor = new DispatchSiteVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return [$visitor->getSites(), $visitor->getUnresolved()];
}

// -----------------------------------------------------------------------------
// Recognised forms
// -----------------------------------------------------------------------------

it('recognises event(new Foo()) as helper + event', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Events\Foo;
    class Svc {
        public function go(): void {
            event(new Foo());
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Events\\Foo');
    expect($sites[0]->form)->toBe(DispatchForm::HELPER);
    expect($sites[0]->provisionalKind)->toBe(DispatchKinds::EVENT);
    expect($sites[0]->classFqcn)->toBe('App\\Services\\Svc');
    expect($sites[0]->method)->toBe('go');
    expect($sites[0]->confidence)->toBe('high');
});

it('recognises event(Foo::class) as helper + event', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Events\Foo;
    class Svc {
        public function go(): void {
            event(Foo::class);
        }
    }
    PHP;

    [$sites] = runDispatchSiteVisitor($source);

    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Events\\Foo');
    expect($sites[0]->form)->toBe(DispatchForm::HELPER);
    expect($sites[0]->provisionalKind)->toBe(DispatchKinds::EVENT);
});

it('recognises Event::dispatch(Foo::class) as facade + event', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Events\Foo;
    use Illuminate\Support\Facades\Event;
    class Svc {
        public function go(): void {
            Event::dispatch(Foo::class);
        }
    }
    PHP;

    [$sites] = runDispatchSiteVisitor($source);

    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Events\\Foo');
    expect($sites[0]->form)->toBe(DispatchForm::FACADE);
    expect($sites[0]->provisionalKind)->toBe(DispatchKinds::EVENT);
});

it('recognises Foo::dispatch() as dispatchable + ambiguous', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Events\Foo;
    class Svc {
        public function go(): void {
            Foo::dispatch();
        }
    }
    PHP;

    [$sites] = runDispatchSiteVisitor($source);

    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Events\\Foo');
    expect($sites[0]->form)->toBe(DispatchForm::DISPATCHABLE);
    expect($sites[0]->provisionalKind)->toBe(DispatchKinds::AMBIGUOUS);
});

it('recognises dispatch(new Bar()) as job_helper + job', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Jobs\Bar;
    class Svc {
        public function go(): void {
            dispatch(new Bar());
        }
    }
    PHP;

    [$sites] = runDispatchSiteVisitor($source);

    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Jobs\\Bar');
    expect($sites[0]->form)->toBe(DispatchForm::JOB_HELPER);
    expect($sites[0]->provisionalKind)->toBe(DispatchKinds::JOB);
});

it('recognises Bus::dispatch(new Bar()) as job_helper + job', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Jobs\Bar;
    use Illuminate\Support\Facades\Bus;
    class Svc {
        public function go(): void {
            Bus::dispatch(new Bar());
        }
    }
    PHP;

    [$sites] = runDispatchSiteVisitor($source);

    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Jobs\\Bar');
    expect($sites[0]->form)->toBe(DispatchForm::JOB_HELPER);
    expect($sites[0]->provisionalKind)->toBe(DispatchKinds::JOB);
});

// -----------------------------------------------------------------------------
// Class / method context
// -----------------------------------------------------------------------------

it('captures handle() method context for an enclosing class', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Listeners;
    use App\Events\Foo;
    class Send {
        public function handle($event): void {
            event(new Foo());
        }
    }
    PHP;

    [$sites] = runDispatchSiteVisitor($source);

    expect($sites)->toHaveCount(1);
    expect($sites[0]->classFqcn)->toBe('App\\Listeners\\Send');
    expect($sites[0]->method)->toBe('handle');
});

it('captures __construct method context', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Events\Foo;
    class Svc {
        public function __construct() {
            event(new Foo());
        }
    }
    PHP;

    [$sites] = runDispatchSiteVisitor($source);

    expect($sites)->toHaveCount(1);
    expect($sites[0]->method)->toBe('__construct');
});

it('captures observer-hook method names like created', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Observers;
    use App\Events\Foo;
    class UserObserver {
        public function created($model): void {
            event(new Foo());
        }
    }
    PHP;

    [$sites] = runDispatchSiteVisitor($source);

    expect($sites)->toHaveCount(1);
    expect($sites[0]->method)->toBe('created');
});

it('records two sites in a single method with shared context', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Events\Foo;
    use App\Events\Bar;
    class Svc {
        public function go(): void {
            event(new Foo());
            event(new Bar());
        }
    }
    PHP;

    [$sites] = runDispatchSiteVisitor($source);

    expect($sites)->toHaveCount(2);
    foreach ($sites as $s) {
        expect($s->classFqcn)->toBe('App\\Services\\Svc');
        expect($s->method)->toBe('go');
    }
});

it('captures distinct method names across methods in one class', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Events\Foo;
    use App\Events\Bar;
    class Svc {
        public function a(): void { event(new Foo()); }
        public function b(): void { event(new Bar()); }
    }
    PHP;

    [$sites] = runDispatchSiteVisitor($source);

    expect($sites)->toHaveCount(2);
    $byMethod = [];
    foreach ($sites as $s) {
        $byMethod[$s->method] = $s->target;
    }
    expect($byMethod)->toBe([
        'a' => 'App\\Events\\Foo',
        'b' => 'App\\Events\\Bar',
    ]);
});

// -----------------------------------------------------------------------------
// Skip cases
// -----------------------------------------------------------------------------

it('emits a resolved dispatch site inside a closure, tagged inClosure', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Events\Foo;
    class Svc {
        public function go(): void {
            $c = function () {
                event(new Foo());
            };
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    // Resolved closure-internal sites now emit (tagged inClosure) so
    // ClosureDispatchAttributionPhase can attribute them; unresolved stays
    // suppressed.
    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Events\\Foo');
    expect($sites[0]->inClosure)->toBeTrue();
    expect($unresolved)->toBe([]);
});

it('emits a resolved dispatch site inside an arrow function, tagged inClosure', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Events\Foo;
    class Svc {
        public function go(): void {
            $c = fn () => event(new Foo());
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Events\\Foo');
    expect($sites[0]->inClosure)->toBeTrue();
    expect($unresolved)->toBe([]);
});

it('skips top-level dispatches outside any class', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Events\Foo;
    event(new Foo());
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($sites)->toBe([]);
    expect($unresolved)->toBe([]);
});

// -----------------------------------------------------------------------------
// Unresolved reasons
// -----------------------------------------------------------------------------

it('records event($variable) as dynamic_class_name unresolved', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    class Svc {
        public function go($variable): void {
            event($variable);
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($sites)->toBe([]);
    expect($unresolved)->toHaveCount(1);
    expect($unresolved[0]->reason)->toBe('dynamic_class_name');
    expect($unresolved[0]->expression)->toContain('event(');
});

it('records string interpolation as string_concatenation unresolved', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    class Svc {
        public function go(string $x): void {
            event("App\\Events\\{$x}");
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($sites)->toBe([]);
    expect($unresolved)->toHaveCount(1);
    expect($unresolved[0]->reason)->toBe('string_concatenation');
});

it('records string concatenation as string_concatenation unresolved', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    class Svc {
        public function go(string $name): void {
            event("App\\Events\\" . $name);
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($sites)->toBe([]);
    expect($unresolved)->toHaveCount(1);
    expect($unresolved[0]->reason)->toBe('string_concatenation');
});

it('records app() resolution as container_resolution unresolved', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    class Svc {
        public function go(): void {
            event(app('events.key'));
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($sites)->toBe([]);
    expect($unresolved)->toHaveCount(1);
    expect($unresolved[0]->reason)->toBe('container_resolution');
});

it('records resolve() as container_resolution unresolved', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    class Svc {
        public function go(): void {
            event(resolve('events.key'));
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($sites)->toBe([]);
    expect($unresolved)->toHaveCount(1);
    expect($unresolved[0]->reason)->toBe('container_resolution');
});

it('records $container->make() as container_resolution unresolved', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    class Svc {
        public function go($container): void {
            event($container->make('events.key'));
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($sites)->toBe([]);
    expect($unresolved)->toHaveCount(1);
    expect($unresolved[0]->reason)->toBe('container_resolution');
});

it('records ternary with non-resolvable branches as conditional_dispatch', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    class Svc {
        public function go($flag, $a, $b): void {
            event($flag ? $a : $b);
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($sites)->toBe([]);
    expect($unresolved)->toHaveCount(1);
    expect($unresolved[0]->reason)->toBe('conditional_dispatch');
});

it('expands ternary with resolvable branches into two sites', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Events\Foo;
    use App\Events\Bar;
    class Svc {
        public function go(bool $flag): void {
            event($flag ? new Foo() : new Bar());
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(2);
    $targets = array_column($sites, 'target');
    sort($targets);
    expect($targets)->toBe(['App\\Events\\Bar', 'App\\Events\\Foo']);
});

it('skips event() with zero arguments entirely', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    class Svc {
        public function go(): void {
            event();
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($sites)->toBe([]);
    expect($unresolved)->toBe([]);
});

// -----------------------------------------------------------------------------
// Conditional skips: dispatch_sync / dispatch_now / Bus::dispatchSync / Bus::dispatchNow
// -----------------------------------------------------------------------------

it('skips dispatch_sync(...) entirely', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Jobs\Bar;
    class Svc {
        public function go(): void {
            dispatch_sync(new Bar());
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($sites)->toBe([]);
    expect($unresolved)->toBe([]);
});

it('skips dispatch_now(...) entirely', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Jobs\Bar;
    class Svc {
        public function go(): void {
            dispatch_now(new Bar());
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($sites)->toBe([]);
    expect($unresolved)->toBe([]);
});

it('skips Bus::dispatchSync(...) entirely', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Jobs\Bar;
    use Illuminate\Support\Facades\Bus;
    class Svc {
        public function go(): void {
            Bus::dispatchSync(new Bar());
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($sites)->toBe([]);
    expect($unresolved)->toBe([]);
});

it('skips Bus::dispatchNow(...) entirely', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Jobs\Bar;
    use Illuminate\Support\Facades\Bus;
    class Svc {
        public function go(): void {
            Bus::dispatchNow(new Bar());
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($sites)->toBe([]);
    expect($unresolved)->toBe([]);
});

// -----------------------------------------------------------------------------
// Chain-wrapped dispatch targets (#31)
// -----------------------------------------------------------------------------

it('resolves ->notify((new X)->locale(...)) chain-wrapped target', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Notifications\InvoicePaid;
    class Svc {
        public function go($user): void {
            $user->notify((new InvoicePaid)->locale('es'));
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Notifications\\InvoicePaid');
    expect($sites[0]->provisionalKind)->toBe(DispatchKinds::NOTIFICATION);
});

it('resolves Notification::send($u, (new X)->onQueue(...)) chain-wrapped target', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Notifications\InvoicePaid;
    use Illuminate\Support\Facades\Notification;
    class Svc {
        public function go($users): void {
            Notification::send($users, (new InvoicePaid)->onQueue('emails'));
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Notifications\\InvoicePaid');
    expect($sites[0]->provisionalKind)->toBe(DispatchKinds::NOTIFICATION);
});

it('resolves Mail::to($u)->send((new X)->locale(...)) chain-wrapped target', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Mail\OrderShipped;
    use Illuminate\Support\Facades\Mail;
    class Svc {
        public function go($user): void {
            Mail::to($user)->send((new OrderShipped)->locale('fr'));
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Mail\\OrderShipped');
    expect($sites[0]->provisionalKind)->toBe(DispatchKinds::MAILABLE);
});

it('resolves dispatch((new X)->delay(60)) chain-wrapped target', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Jobs\ProcessOrder;
    class Svc {
        public function go(): void {
            dispatch((new ProcessOrder())->delay(60));
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Jobs\\ProcessOrder');
    expect($sites[0]->provisionalKind)->toBe(DispatchKinds::JOB);
});

it('resolves event((new X($order))->something()) chain-wrapped target', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Events\OrderPlaced;
    class Svc {
        public function go($order): void {
            event((new OrderPlaced($order))->something());
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Events\\OrderPlaced');
    expect($sites[0]->provisionalKind)->toBe(DispatchKinds::EVENT);
});

it('still records variable-receiver ->notify($n->locale(...)) as dynamic_class_name unresolved', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    class Svc {
        public function go($user, $n): void {
            $user->notify($n->locale('es'));
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($sites)->toBe([]);
    expect($unresolved)->toHaveCount(1);
    expect($unresolved[0]->reason)->toBe('dynamic_class_name');
});

// -----------------------------------------------------------------------------
// Dispatch-time chain modifiers / overrides (#32)
// -----------------------------------------------------------------------------

it('captures inner-chain job modifiers: dispatch((new Job)->onQueue()->delay())', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Jobs\ProcessOrder;
    class Svc {
        public function go(): void {
            dispatch((new ProcessOrder)->onQueue('q')->delay(5));
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Jobs\\ProcessOrder');
    expect($sites[0]->overrides->isEmpty())->toBeFalse();
    expect($sites[0]->overrides->toArray())->toBe(['queue' => 'q', 'delay' => 5]);
});

it('captures outer PendingDispatch modifiers: Job::dispatch()->onQueue()->onConnection()->delay()', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Jobs\ProcessOrder;
    class Svc {
        public function go($o): void {
            ProcessOrder::dispatch($o)->onQueue('high')->onConnection('redis')->delay(60);
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Jobs\\ProcessOrder');
    expect($sites[0]->overrides->toArray())->toBe([
        'connection' => 'redis',
        'queue' => 'high',
        'delay' => 60,
    ]);
});

it('captures outer ->afterCommit() on dispatch(new Job)', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Jobs\ProcessOrder;
    class Svc {
        public function go($o): void {
            dispatch(new ProcessOrder($o))->afterCommit();
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Jobs\\ProcessOrder');
    expect($sites[0]->overrides->toArray())->toBe(['after_commit' => true]);
});

it('lets the outer chain win over an inner chain on a same-key conflict', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Jobs\ProcessOrder;
    class Svc {
        public function go($o): void {
            dispatch((new ProcessOrder($o))->onQueue('low'))->onQueue('high');
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    // Inner ->onQueue('low') applied first, outer ->onQueue('high') wins.
    expect($sites[0]->overrides->queue)->toBe('high');
    expect($sites[0]->overrides->toArray())->toBe(['queue' => 'high']);
});

it('captures Mail facade-receiver modifiers: Mail::to($u)->locale()->mailer()->send($m)', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Mail\OrderShipped;
    use Illuminate\Support\Facades\Mail;
    class Svc {
        public function go($user): void {
            Mail::to($user)->locale('fr')->mailer('ses')->send(new OrderShipped());
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Mail\\OrderShipped');
    expect($sites[0]->overrides->toArray())->toBe(['locale' => 'fr', 'mailer' => 'ses']);
});

it('leaves overrides empty for a plain dispatch with no modifiers', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Jobs\ProcessOrder;
    class Svc {
        public function go($o): void {
            dispatch(new ProcessOrder($o));
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->overrides->isEmpty())->toBeTrue();
    expect($sites[0]->overrides->toArray())->toBe([]);
});

it('does not capture a delay key for ->delay($var)', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Jobs\ProcessOrder;
    class Svc {
        public function go($o, $seconds): void {
            dispatch((new ProcessOrder($o))->delay($seconds));
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->overrides->delay)->toBeNull();
    expect($sites[0]->overrides->isEmpty())->toBeTrue();
});

// -----------------------------------------------------------------------------
// Notification channel filter override (#33)
// -----------------------------------------------------------------------------

it('captures a single-channel filter on Notification::send', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Notifications\InvoicePaid;
    use Illuminate\Support\Facades\Notification;
    class Svc {
        public function go($users): void {
            Notification::send($users, new InvoicePaid, ['mail']);
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Notifications\\InvoicePaid');
    expect($sites[0]->provisionalKind)->toBe(DispatchKinds::NOTIFICATION);
    expect($sites[0]->channels)->toBe(['mail']);
});

it('captures a multi-channel filter on Notification::sendNow', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Notifications\InvoicePaid;
    use Illuminate\Support\Facades\Notification;
    class Svc {
        public function go($users): void {
            Notification::sendNow($users, new InvoicePaid, ['mail', 'database']);
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->channels)->toBe(['mail', 'database']);
});

it('surfaces a Class::class channel constant as an FQCN in the filter', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Channels\SlackChannel;
    use App\Notifications\InvoicePaid;
    use Illuminate\Support\Facades\Notification;
    class Svc {
        public function go($users): void {
            Notification::send($users, new InvoicePaid, ['mail', SlackChannel::class]);
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->channels)->toBe(['mail', 'App\\Channels\\SlackChannel']);
});

it('leaves channels null on Notification::send with no filter argument', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Notifications\InvoicePaid;
    use Illuminate\Support\Facades\Notification;
    class Svc {
        public function go($users): void {
            Notification::send($users, new InvoicePaid);
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->channels)->toBeNull();
});

it('leaves channels null for a dynamic (variable) filter argument', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Notifications\InvoicePaid;
    use Illuminate\Support\Facades\Notification;
    class Svc {
        public function go($users, $dynamic): void {
            Notification::send($users, new InvoicePaid, $dynamic);
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Notifications\\InvoicePaid');
    expect($sites[0]->channels)->toBeNull();
});

it('collapses an empty-array filter to null channels', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Notifications\InvoicePaid;
    use Illuminate\Support\Facades\Notification;
    class Svc {
        public function go($users): void {
            Notification::send($users, new InvoicePaid, []);
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->channels)->toBeNull();
});

it('never captures channels for the ->notify(...) method form', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Notifications\InvoicePaid;
    class Svc {
        public function go($user): void {
            $user->notify(new InvoicePaid);
        }
    }
    PHP;

    [$sites, $unresolved] = runDispatchSiteVisitor($source);

    expect($unresolved)->toBe([]);
    expect($sites)->toHaveCount(1);
    expect($sites[0]->target)->toBe('App\\Notifications\\InvoicePaid');
    expect($sites[0]->channels)->toBeNull();
});
