<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\EventDiscoveryFact;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\ListenerRef;
use Lucasp\Loom\Support\Ast\Literal;
use Lucasp\Loom\Support\EventDiscoveryFactKind;
use PhpParser\Node;

/**
 * Collects the shapes that switch event discovery or change its directories:
 * `Application::configure()` / `new Application`, `->withEvents(...)`, the
 * `EventServiceProvider` static switches, and a `shouldDiscoverEvents()`
 * override that returns a literal.
 *
 * @internal
 */
final class EventDiscoveryVisitor extends CollectingVisitor
{
    use IdentifiesEventServiceProvider;

    /** @var list<EventDiscoveryFact> */
    private array $facts = [];

    protected function reset(): void
    {
        $this->facts = [];
    }

    public function enterNode(Node $node): null
    {
        $this->enterClassScope($node);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        match (true) {
            $node instanceof Node\Expr\StaticCall => $this->staticCall($node),
            $node instanceof Node\Expr\New_ => $this->newCall($node),
            $node instanceof Node\Expr\MethodCall => $this->methodCall($node),
            $node instanceof Node\Stmt\ClassMethod => $this->method($node),
            default => null,
        };

        $this->leaveClassScope($node);

        return null;
    }

    private function staticCall(Node\Expr\StaticCall $node): void
    {
        if (! $node->class instanceof Node\Name || ! $node->name instanceof Node\Identifier) {
            return;
        }

        $class = $node->class->getLast();
        $name = $node->name->toString();
        $argument = Args::of($node->args)->valueAt(0);

        // `Application::configure(...)`; a subclass keeps the name suffix.
        if ($name === 'configure' && $class === 'Application') {
            $this->facts[] = new EventDiscoveryFact(EventDiscoveryFactKind::CONFIGURE);

            return;
        }

        if ($class !== 'EventServiceProvider') {
            return;
        }

        match ($name) {
            'disableEventDiscovery' => $this->facts[] = new EventDiscoveryFact(EventDiscoveryFactKind::DISABLE),
            'setEventDiscoveryPaths' => $this->facts[] = new EventDiscoveryFact(EventDiscoveryFactKind::SET_PATHS, paths: $this->paths($argument)),
            'addEventDiscoveryPaths' => $this->facts[] = new EventDiscoveryFact(EventDiscoveryFactKind::ADD_PATHS, paths: $this->paths($argument)),
            default => null,
        };
    }

    private function newCall(Node\Expr\New_ $node): void
    {
        if ($node->class instanceof Node\Name && $node->class->getLast() === 'Application') {
            $this->facts[] = new EventDiscoveryFact(EventDiscoveryFactKind::LEGACY_APPLICATION);
        }
    }

    private function methodCall(Node\Expr\MethodCall $node): void
    {
        if (! $node->name instanceof Node\Identifier || $node->name->toString() !== 'withEvents') {
            return;
        }

        $args = Args::of($node->args);
        $argument = $args->lookup(0, 'discover')?->value;

        // `withEvents()` / `withEvents(true)`: the default directories.
        if ($argument === null || Literal::bool($argument) === true) {
            $this->facts[] = new EventDiscoveryFact(EventDiscoveryFactKind::WITH_EVENTS, value: true);

            return;
        }

        if (Literal::bool($argument) === false) {
            $this->facts[] = new EventDiscoveryFact(EventDiscoveryFactKind::WITH_EVENTS, value: false);

            return;
        }

        if ($argument instanceof Node\Expr\Array_) {
            $this->facts[] = new EventDiscoveryFact(EventDiscoveryFactKind::WITH_EVENTS, value: true, paths: $this->paths($argument));

            return;
        }

        $this->facts[] = new EventDiscoveryFact(EventDiscoveryFactKind::WITH_EVENTS, dynamic: true);
    }

    private function method(Node\Stmt\ClassMethod $node): void
    {
        if (! $this->inEventServiceProvider() || $node->name->toString() !== 'shouldDiscoverEvents') {
            return;
        }

        $statements = $node->stmts ?? [];
        $return = count($statements) === 1 ? $statements[0] : null;
        $value = $return instanceof Node\Stmt\Return_ && $return->expr !== null ? Literal::bool($return->expr) : null;

        $this->facts[] = new EventDiscoveryFact(EventDiscoveryFactKind::OVERRIDE, value: $value);
    }

    /**
     * @return list<string>|null null when any directory expression is unreadable
     */
    private function paths(?Node\Expr $argument): ?array
    {
        $items = match (true) {
            $argument instanceof Node\Expr\Array_ => array_map(static fn (Node\ArrayItem $item): Node\Expr => $item->value, $argument->items),
            $argument !== null => [$argument],
            default => [],
        };

        $paths = [];
        foreach ($items as $item) {
            $path = $this->path($item);
            if ($path === null) {
                return null;
            }
            $paths[] = $path;
        }

        return $paths;
    }

    /** `app_path('x')` is `app/x`, `base_path('x')` is `x`, and literals and `__DIR__` concatenations are kept marked. */
    private function path(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\MagicConst\Dir) {
            return '{dir}';
        }

        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $left = $this->path($expr->left);
            $right = $this->path($expr->right);

            return $left === null || $right === null ? null : $left.$right;
        }

        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name) {
            $argument = Args::of($expr->args)->valueAt(0);
            $tail = $argument === null ? '' : ListenerRef::fold($argument);

            return match ($expr->name->getLast()) {
                'app_path' => $tail === null ? null : 'app/'.$tail,
                'base_path' => $tail,
                default => null,
            };
        }

        return ListenerRef::fold($expr);
    }

    /** @return list<EventDiscoveryFact> */
    public function getFacts(): array
    {
        return $this->facts;
    }
}
