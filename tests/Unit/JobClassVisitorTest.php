<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\Visitors\JobClassVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Parse a PHP source string and run JobClassVisitor (after NameResolver) over it.
 *
 * @return array<int, array{
 *     fqcn: string,
 *     line: int,
 *     queued: bool,
 *     has_handle: bool,
 *     queue_config: array{
 *         connection: string|int|null,
 *         queue: string|int|null,
 *         delay: string|int|null,
 *         tries: string|int|null,
 *         timeout: string|int|null,
 *         backoff: string|int|null
 *     }
 * }>
 */
function runJobClassVisitor(string $source): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($source);

    expect($ast)->not->toBeNull();

    $visitor = new JobClassVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return $visitor->getClasses();
}

it('marks a job as queued when implementing ShouldQueue via a use import', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Jobs;

    use Illuminate\Contracts\Queue\ShouldQueue;

    class ProcessOrder implements ShouldQueue
    {
        public function handle(): void
        {
        }
    }
    PHP;

    $classes = runJobClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['fqcn'])->toBe('App\\Jobs\\ProcessOrder');
    expect($classes[0]['line'])->toBe(7);
    expect($classes[0]['queued'])->toBeTrue();
    expect($classes[0]['has_handle'])->toBeTrue();
});

it('marks a job as queued when implementing the ShouldQueue FQCN directly', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Jobs;

    class DirectFqcnJob implements \Illuminate\Contracts\Queue\ShouldQueue
    {
        public function handle(): void
        {
        }
    }
    PHP;

    $classes = runJobClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['queued'])->toBeTrue();
});

it('marks a job as not queued when ShouldQueue is absent', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Jobs;

    class SyncJob
    {
        public function handle(): void
        {
        }
    }
    PHP;

    $classes = runJobClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['queued'])->toBeFalse();
    expect($classes[0]['queue_config'])->toBe([
        'connection' => null,
        'queue' => null,
        'delay' => null,
        'tries' => null,
        'timeout' => null,
        'backoff' => null,
    ]);
});

it('extracts every literal scalar queue-config property', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Jobs;

    use Illuminate\Contracts\Queue\ShouldQueue;

    class FullConfig implements ShouldQueue
    {
        public string $connection = 'redis';
        public string $queue = 'high';
        public int $delay = 30;
        public int $tries = 5;
        public int $timeout = 120;
        public int $backoff = 10;
    }
    PHP;

    $classes = runJobClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['queue_config'])->toBe([
        'connection' => 'redis',
        'queue' => 'high',
        'delay' => 30,
        'tries' => 5,
        'timeout' => 120,
        'backoff' => 10,
    ]);
});

it('leaves undeclared queue-config properties as null', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Jobs;

    use Illuminate\Contracts\Queue\ShouldQueue;

    class PartialConfig implements ShouldQueue
    {
        public string $queue = 'low';
        public int $tries = 3;
    }
    PHP;

    $classes = runJobClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['queue_config'])->toBe([
        'connection' => null,
        'queue' => 'low',
        'delay' => null,
        'tries' => 3,
        'timeout' => null,
        'backoff' => null,
    ]);
});

it('leaves non-scalar initializers as null', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Jobs;

    use Illuminate\Contracts\Queue\ShouldQueue;

    class DynamicConfig implements ShouldQueue
    {
        public $queue = self::DEFAULT_QUEUE;
        public $connection;
        public $tries;
    }
    PHP;

    $classes = runJobClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['queue_config']['queue'])->toBeNull();
    expect($classes[0]['queue_config']['connection'])->toBeNull();
    expect($classes[0]['queue_config']['tries'])->toBeNull();
});

it('extracts queue-config across public, protected, private, and static modifiers', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Jobs;

    use Illuminate\Contracts\Queue\ShouldQueue;

    class MixedModifiers implements ShouldQueue
    {
        public string $connection = 'redis';
        protected string $queue = 'mid';
        private int $tries = 2;
        public static int $timeout = 90;
    }
    PHP;

    $classes = runJobClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['queue_config']['connection'])->toBe('redis');
    expect($classes[0]['queue_config']['queue'])->toBe('mid');
    expect($classes[0]['queue_config']['tries'])->toBe(2);
    expect($classes[0]['queue_config']['timeout'])->toBe(90);
});

it('extracts a typed-property initializer', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Jobs;

    use Illuminate\Contracts\Queue\ShouldQueue;

    class TypedTries implements ShouldQueue
    {
        public int $tries = 3;
    }
    PHP;

    $classes = runJobClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['queue_config']['tries'])->toBe(3);
});

it('skips abstract classes', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Jobs;

    use Illuminate\Contracts\Queue\ShouldQueue;

    abstract class AbstractJob implements ShouldQueue
    {
        public function handle(): void
        {
        }
    }
    PHP;

    $classes = runJobClassVisitor($source);

    expect($classes)->toBe([]);
});

it('skips interfaces', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Jobs;

    interface JobContract
    {
        public function handle(): void;
    }
    PHP;

    $classes = runJobClassVisitor($source);

    expect($classes)->toBe([]);
});

it('skips traits', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Jobs;

    trait JobHelpers
    {
        public function handle(): void
        {
        }
    }
    PHP;

    $classes = runJobClassVisitor($source);

    expect($classes)->toBe([]);
});

it('skips anonymous classes', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Jobs;

    $job = new class {
        public function handle(): void
        {
        }
    };
    PHP;

    $classes = runJobClassVisitor($source);

    expect($classes)->toBe([]);
});

it('emits each concrete class independently when multiple are declared in one file', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Jobs;

    use Illuminate\Contracts\Queue\ShouldQueue;

    class JobOne implements ShouldQueue
    {
        public int $tries = 1;
        public function handle(): void {}
    }

    class JobTwo
    {
        public function handle(): void {}
    }
    PHP;

    $classes = runJobClassVisitor($source);

    expect($classes)->toHaveCount(2);
    expect($classes[0]['fqcn'])->toBe('App\\Jobs\\JobOne');
    expect($classes[0]['queued'])->toBeTrue();
    expect($classes[0]['queue_config']['tries'])->toBe(1);
    expect($classes[1]['fqcn'])->toBe('App\\Jobs\\JobTwo');
    expect($classes[1]['queued'])->toBeFalse();
});

it('records has_handle as false when the class has no handle() method', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Jobs;

    class NoHandle
    {
        public function somethingElse(): void
        {
        }
    }
    PHP;

    $classes = runJobClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['has_handle'])->toBeFalse();
});
