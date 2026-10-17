<?php

declare(strict_types=1);

use Lucasp\Loom\Dto\JobClassRecord;
use Lucasp\Loom\Dto\QueueConfigData;
use Lucasp\Loom\Scanners\Visitors\ClassDeclarationVisitor;
use Lucasp\Loom\Scanners\Visitors\JobClassVisitor;

/**
 * @return list<JobClassRecord>
 */
function runJobClassVisitor(string $source): array
{
    $visitor = runVisitor(new JobClassVisitor, $source);

    return $visitor->getClasses();
}

/** Property-default queue settings of the nth declaration in $source. */
function jobQueueConfig(string $source, int $index): QueueConfigData
{
    $visitor = runVisitor(new ClassDeclarationVisitor, $source);

    return $visitor->getDeclarations()[$index]->queue->properties;
}

it('records fqcn and line for a job class', function () {
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
    expect($classes[0]->fqcn)->toBe('App\\Jobs\\ProcessOrder');
    expect($classes[0]->line)->toBe(7);
});

it('leaves queue config empty when no properties are declared', function () {
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
    expect(jobQueueConfig($source, 0))->toEqual(new QueueConfigData(
        connection: null,
        queue: null,
        delay: null,
        tries: null,
        timeout: null,
        backoff: null,
    ));
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
    expect(jobQueueConfig($source, 0))->toEqual(new QueueConfigData(
        connection: 'redis',
        queue: 'high',
        delay: 30,
        tries: 5,
        timeout: 120,
        backoff: 10,
    ));
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
    expect(jobQueueConfig($source, 0))->toEqual(new QueueConfigData(
        connection: null,
        queue: 'low',
        delay: null,
        tries: 3,
        timeout: null,
        backoff: null,
    ));
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
    expect(jobQueueConfig($source, 0)->queue)->toBeNull();
    expect(jobQueueConfig($source, 0)->connection)->toBeNull();
    expect(jobQueueConfig($source, 0)->tries)->toBeNull();
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
    expect(jobQueueConfig($source, 0)->connection)->toBe('redis');
    expect(jobQueueConfig($source, 0)->queue)->toBe('mid');
    expect(jobQueueConfig($source, 0)->tries)->toBe(2);
    expect(jobQueueConfig($source, 0)->timeout)->toBe(90);
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
    expect(jobQueueConfig($source, 0)->tries)->toBe(3);
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
    expect($classes[0]->fqcn)->toBe('App\\Jobs\\JobOne');
    expect(jobQueueConfig($source, 0)->tries)->toBe(1);
    expect($classes[1]->fqcn)->toBe('App\\Jobs\\JobTwo');
});

it('reads $afterCommit from a property default and the constructor', function (string $body, ?bool $property, ?bool $constructor) {
    $source = "<?php\nnamespace App\\Jobs;\nclass Charge\n{\n{$body}\n}\n";

    $declaration = runVisitor(new ClassDeclarationVisitor, $source)->getDeclarations()[0]->queue;

    expect($declaration->properties->afterCommit)->toBe($property)
        ->and($declaration->constructor->afterCommit)->toBe($constructor);
})->with([
    'property true' => ['public $afterCommit = true;', true, null],
    'property false' => ['public $afterCommit = false;', false, null],
    'property uppercase constant' => ['public $afterCommit = TRUE;', true, null],
    'property not a literal' => ['public $afterCommit = self::FLAG;', null, null],
    'constructor afterCommit()' => ['public function __construct() { $this->afterCommit(); }', null, true],
    'constructor beforeCommit()' => ['public function __construct() { $this->beforeCommit(); }', null, false],
    'constructor assignment' => ['public function __construct() { $this->afterCommit = true; }', null, true],
    'constructor chained' => ['public function __construct() { $this->onQueue("x")->afterCommit(); }', null, true],
    'constructor in a branch' => ['public function __construct($f) { if ($f) { $this->afterCommit(); } }', null, null],
]);
