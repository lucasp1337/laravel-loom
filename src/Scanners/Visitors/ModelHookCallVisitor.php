<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\EloquentListenRecord;
use Lucasp\Loom\Index\ModelHook;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\ListenerRef;
use PhpParser\Node;

/**
 * Finds model hook registrations: `static::creating(fn (Order $order) => ...)`
 * and the other Eloquent registrar methods (`saving`, `deleted`,
 * `softDeleted`, ...), wherever they appear: a `boot()` or `booted()` method,
 * a trait's `bootXxx()`, or outside the model as `Order::created(fn ...)`.
 *
 * The receiver is `static` or `self` inside a named class (the model) or a
 * trait (the model is whichever class uses it; the scanner expands it), or an
 * explicit class name. The callback is a closure or arrow function, a
 * `[Handler::class, 'method']` tuple, a bare `Handler::class` (method
 * `handle`), or, on `static`/`self`, a `'Handler@method'` string. `parent::`
 * and `$model::` receivers name no known model and are skipped.
 *
 * A callback of any other shape on `static`/`self` (a variable, a call) is
 * recorded as unresolved. An explicit class receiver with such a callback is
 * skipped, since the class is not known to be a model.
 *
 * @internal
 */
final class ModelHookCallVisitor extends CollectingVisitor
{
    use RecordsUnresolvedRegistrations;
    use TracksClassScope;

    /** @var list<EloquentListenRecord> */
    private array $entries = [];

    protected function reset(): void
    {
        $this->entries = [];
        $this->resetUnresolved();
    }

    public function enterNode(Node $node): null
    {
        $this->enterClassScope($node);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        $this->leaveClassScope($node);

        if (! $node instanceof Node\Expr\StaticCall) {
            return null;
        }
        if (! $node->name instanceof Node\Identifier || ! $node->class instanceof Node\Name) {
            return null;
        }

        $hook = ModelHook::fromRegistrar($node->name->toString());
        $callback = Args::of($node->args)->valueAt(0);
        if ($hook === null || $callback === null) {
            return null;
        }

        $rawClass = $node->class->toString();
        $selfReceiver = match (strtolower($rawClass)) {
            'static', 'self' => true,
            default => false,
        };

        // `parent::creating()` names no statically known model.
        if (strtolower($rawClass) === 'parent') {
            $this->recordUnresolved($callback, UnresolvedReason::RELATIVE_CLASS_NAME);

            return null;
        }

        $model = $selfReceiver ? $this->currentClassFqcn() : $rawClass;
        $viaTrait = $selfReceiver && $this->currentClassNode() instanceof Node\Stmt\Trait_;

        if ($model === null) {
            // `static::creating()` in an anonymous class or at file level.
            $this->recordUnresolved($callback, UnresolvedReason::RELATIVE_CLASS_NAME);

            return null;
        }

        $this->collect($node, $callback, $model, $hook, $selfReceiver, $viaTrait);

        return null;
    }

    private function collect(Node\Expr\StaticCall $call, Node\Expr $callback, string $model, ModelHook $hook, bool $selfReceiver, bool $viaTrait): void
    {
        if ($callback instanceof Node\Expr\Closure || $callback instanceof Node\Expr\ArrowFunction) {
            $this->entries[] = new EloquentListenRecord(
                model: $model,
                hook: $hook->value,
                handler: $model,
                method: EloquentListenRecord::CLOSURE_METHOD,
                line: $callback->getStartLine(),
                endLine: $callback->getEndLine(),
                viaTrait: $viaTrait,
            );

            return;
        }

        // A plain string on an arbitrary class is not known to be a listener.
        $readableShape = $selfReceiver
            || $callback instanceof Node\Expr\Array_
            || $callback instanceof Node\Expr\ClassConstFetch;

        $resolved = $readableShape ? ListenerRef::listener($callback) : null;
        if ($resolved === null) {
            if ($selfReceiver) {
                $this->recordUnresolved($callback, UnresolvedReason::UNREADABLE_MODEL_HOOK);
            }

            return;
        }

        $this->entries[] = new EloquentListenRecord(
            model: $model,
            hook: $hook->value,
            handler: $resolved['listener'],
            method: $resolved['method'],
            line: $call->getStartLine(),
            viaTrait: $viaTrait,
        );
    }

    /** @return list<EloquentListenRecord> */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
