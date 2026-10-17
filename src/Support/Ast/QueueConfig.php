<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use Lucasp\Loom\Dto\QueueConfigData;
use Lucasp\Loom\Dto\QueueDeclaration;
use PhpParser\Node;

/**
 * Reads the queueable-class settings (`connection`, `queue`, `delay`, `tries`,
 * `timeout`, `backoff`) a class or trait body declares as scalar literals:
 * property defaults, and the top-level statements of its constructor
 * (`$this->onQueue('x')`, `$this->onConnection('y')`, `$this->delay(60)` and
 * `$this->queue = 'x'`). A constructor statement inside a branch is not read.
 * Settings not declared map to `null`; merging through parents is the
 * resolver's job.
 *
 * @internal
 */
final class QueueConfig
{
    /** @var list<string> */
    public const PROPERTIES = ['connection', 'queue', 'delay', 'tries', 'timeout', 'backoff'];

    /** Fluent setter name => the setting it sets. */
    private const SETTERS = [
        'onconnection' => 'connection',
        'onqueue' => 'queue',
        'delay' => 'delay',
    ];

    public static function declarationOf(Node\Stmt\ClassLike $node): QueueDeclaration
    {
        $constructor = null;
        $properties = self::blank();

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    $name = $prop->name->toString();
                    if (self::isSetting($name) && $prop->default !== null) {
                        $properties[$name] = Literal::scalar($prop->default);
                    }
                }

                continue;
            }

            if ($stmt instanceof Node\Stmt\ClassMethod && strtolower($stmt->name->toString()) === '__construct') {
                $constructor = $stmt;
            }
        }

        if ($constructor === null) {
            return new QueueDeclaration(self::data($properties), self::data(self::blank()), false, false);
        }

        $set = self::blank();
        $callsParent = false;
        foreach ($constructor->stmts ?? [] as $stmt) {
            if (! $stmt instanceof Node\Stmt\Expression) {
                continue;
            }

            if (self::isParentConstructorCall($stmt->expr)) {
                $callsParent = true;

                continue;
            }

            foreach (self::constructorSettings($stmt->expr) as $key => $value) {
                $set[$key] = $value;
            }
        }

        return new QueueDeclaration(self::data($properties), self::data($set), true, $callsParent);
    }

    private static function isSetting(string $name): bool
    {
        return collect(self::PROPERTIES)->contains($name);
    }

    /** @return array<string, string|int|null> */
    private static function blank(): array
    {
        return collect(self::PROPERTIES)->mapWithKeys(static fn (string $key): array => [$key => null])->all();
    }

    /** @param  array<string, string|int|null>  $values */
    private static function data(array $values): QueueConfigData
    {
        return new QueueConfigData(
            connection: $values['connection'],
            queue: $values['queue'],
            delay: $values['delay'],
            tries: $values['tries'],
            timeout: $values['timeout'],
            backoff: $values['backoff'],
        );
    }

    private static function isParentConstructorCall(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\StaticCall
            && $expr->class instanceof Node\Name
            && strtolower($expr->class->toString()) === 'parent'
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) === '__construct';
    }

    /**
     * Literal settings one constructor statement applies to `$this`.
     *
     * @return array<string, string|int>
     */
    private static function constructorSettings(Node\Expr $expr): array
    {
        // `$this->queue = 'x'`
        if ($expr instanceof Node\Expr\Assign) {
            $target = $expr->var;
            if (! self::isThisProperty($target)) {
                return [];
            }

            $name = $target->name instanceof Node\Identifier ? $target->name->toString() : null;
            $value = Literal::scalar($expr->expr);

            return $name !== null && $value !== null && self::isSetting($name) ? [$name => $value] : [];
        }

        // `$this->onQueue('x')->onConnection('y')`: every link on a `$this` spine
        $settings = [];
        $current = $expr;
        while ($current instanceof Node\Expr\MethodCall) {
            $current = self::collectSetter($current, $settings);
        }

        return $current instanceof Node\Expr\Variable && $current->name === 'this' ? $settings : [];
    }

    /**
     * @param  array<string, string|int>  $settings
     */
    private static function collectSetter(Node\Expr\MethodCall $call, array &$settings): Node\Expr
    {
        if ($call->name instanceof Node\Identifier) {
            $key = self::SETTERS[strtolower($call->name->toString())] ?? null;
            $value = Literal::scalar(Args::of($call->args)->valueAt(0));
            if ($key !== null && $value !== null) {
                // Outer links run last, so they win; the spine is walked outer-first.
                $settings[$key] ??= $value;
            }
        }

        return $call->var;
    }

    /** @phpstan-assert-if-true Node\Expr\PropertyFetch $node */
    private static function isThisProperty(Node\Expr $node): bool
    {
        return $node instanceof Node\Expr\PropertyFetch
            && $node->var instanceof Node\Expr\Variable
            && $node->var->name === 'this';
    }
}
