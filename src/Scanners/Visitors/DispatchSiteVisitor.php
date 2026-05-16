<?php

declare(strict_types=1);

namespace Lucasp\Atlas\Scanners\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Collects every statically recognisable dispatch site in a parsed file.
 *
 * The visitor is stateful: it maintains a class+method stack and a closure
 * depth counter so each recorded site carries its enclosing
 * `(classFqcn, methodName)` context. The cross-link pass joins these against
 * listeners[], observers[] and events[] to populate downstream relations.
 *
 * Unresolvable dispatch arguments produce structured unresolved entries
 * conforming to `$defs/unresolvedDispatch` (without the `file` field, which
 * the scanner fills in after traversal).
 *
 * See docs/scanners/DispatchScanner.md §3.
 */
final class DispatchSiteVisitor extends NodeVisitorAbstract
{
    private const EVENT_FACADE = 'Illuminate\\Support\\Facades\\Event';

    private const BUS_FACADE = 'Illuminate\\Support\\Facades\\Bus';

    /** @var array<int, array{class: ?string, method: ?string}> */
    private array $classStack = [];

    private int $closureDepth = 0;

    /**
     * @var array<int, array{
     *     classFqcn: ?string,
     *     method: ?string,
     *     target: string,
     *     form: 'helper'|'facade'|'job_helper'|'dispatchable',
     *     provisionalKind: 'event'|'job'|'ambiguous',
     *     file: ?string,
     *     line: int,
     *     confidence: 'high'
     * }>
     */
    private array $sites = [];

    /** @var array<int, array{file: ?string, line: int, expression: string, reason: string}> */
    private array $unresolved = [];

    private PrettyPrinter $printer;

    public function __construct()
    {
        $this->printer = new PrettyPrinter;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->classStack = [];
        $this->closureDepth = 0;
        $this->sites = [];
        $this->unresolved = [];

        return null;
    }

    public function enterNode(Node $node): null
    {
        // Treat Trait_ and Class_ identically per design §8 (trait gap).
        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_) {
            $fqcn = $node->namespacedName?->toString();
            $this->classStack[] = ['class' => $fqcn, 'method' => null];

            return null;
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            if ($this->classStack !== []) {
                $top = count($this->classStack) - 1;
                $this->classStack[$top]['method'] = $node->name->toString();
            }

            return null;
        }

        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->closureDepth++;
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        // Read dispatches in leaveNode so NameResolver has resolved every child
        // Name in the argument expressions before we inspect them.
        if ($node instanceof Node\Expr\FuncCall) {
            $this->handleFuncCall($node);
        } elseif ($node instanceof Node\Expr\StaticCall) {
            $this->handleStaticCall($node);
        }

        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_) {
            array_pop($this->classStack);

            return null;
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            if ($this->classStack !== []) {
                $top = count($this->classStack) - 1;
                $this->classStack[$top]['method'] = null;
            }

            return null;
        }

        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->closureDepth = max(0, $this->closureDepth - 1);
        }

        return null;
    }

    private function handleFuncCall(Node\Expr\FuncCall $node): void
    {
        if (! $node->name instanceof Node\Name) {
            return;
        }

        $name = strtolower($node->name->toString());

        if ($name === 'event') {
            $this->recordHelperOrFacade($node, $node->args, 'helper', 'event', 'event');

            return;
        }

        if ($name === 'dispatch') {
            $this->recordHelperOrFacade($node, $node->args, 'job_helper', 'job', 'dispatch');
        }

        // dispatch_sync / dispatch_now intentionally skipped per design §8.
    }

    private function handleStaticCall(Node\Expr\StaticCall $node): void
    {
        if (! $node->class instanceof Node\Name) {
            return;
        }
        if (! $node->name instanceof Node\Identifier) {
            return;
        }

        $methodName = $node->name->toString();
        if ($methodName !== 'dispatch') {
            // dispatchSync / dispatchNow intentionally skipped per design §8.
            return;
        }

        $className = $node->class->toString();

        if ($className === self::EVENT_FACADE || $className === 'Event') {
            $this->recordHelperOrFacade($node, $node->args, 'facade', 'event', 'Event::dispatch');

            return;
        }

        if ($className === self::BUS_FACADE || $className === 'Bus') {
            $this->recordHelperOrFacade($node, $node->args, 'job_helper', 'job', 'Bus::dispatch');

            return;
        }

        // Dispatchable form: X::dispatch(...). Target is the class itself.
        if ($this->shouldSkipEmission()) {
            return;
        }

        $this->sites[] = [
            'classFqcn' => $this->currentClassFqcn(),
            'method' => $this->currentMethod(),
            'target' => $className,
            'form' => 'dispatchable',
            'provisionalKind' => 'ambiguous',
            'file' => null,
            'line' => $node->getStartLine(),
            'confidence' => 'high',
        ];
    }

    /**
     * Emit either a resolved site or an unresolved entry for the
     * helper/facade/bus_facade/job_helper forms (those that take the target
     * class in the first argument).
     *
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     * @param  'helper'|'facade'|'job_helper'  $form
     * @param  'event'|'job'  $kind
     */
    private function recordHelperOrFacade(Node\Expr $callNode, array $args, string $form, string $kind, string $callLabel): void
    {
        if ($args === []) {
            // event() with zero args → silently skip (malformed per §3c).
            return;
        }

        $first = $args[0];
        if (! $first instanceof Node\Arg) {
            return;
        }

        $resolved = $this->resolveStaticClass($first->value);

        // Special case: ternary whose branches both resolve statically →
        // emit two sites instead of an unresolved (per §8).
        if ($resolved === null && $first->value instanceof Node\Expr\Ternary) {
            $ternary = $first->value;
            $ifBranch = $ternary->if;
            $elseBranch = $ternary->else;

            if ($ifBranch !== null) {
                $ifFqcn = $this->resolveStaticClass($ifBranch);
                $elseFqcn = $this->resolveStaticClass($elseBranch);

                if ($ifFqcn !== null && $elseFqcn !== null) {
                    $this->emitResolved($callNode, $ifFqcn, $form, $kind);
                    $this->emitResolved($callNode, $elseFqcn, $form, $kind);

                    return;
                }
            }
        }

        if ($resolved !== null) {
            $this->emitResolved($callNode, $resolved, $form, $kind);

            return;
        }

        // Unresolved — classify reason.
        if ($this->shouldSkipEmission()) {
            // Even unresolved entries are skipped for closure-internal sites
            // and top-level-script sites (design §8: skipped during emission).
            return;
        }

        $reason = $this->classifyUnresolvedReason($first->value);
        $expression = $this->renderExpression($callNode, $callLabel);

        $this->unresolved[] = [
            'file' => null,
            'line' => $callNode->getStartLine(),
            'expression' => $expression,
            'reason' => $reason,
        ];
    }

    /**
     * @param  'helper'|'facade'|'job_helper'  $form
     * @param  'event'|'job'  $kind
     */
    private function emitResolved(Node\Expr $callNode, string $targetFqcn, string $form, string $kind): void
    {
        if ($this->shouldSkipEmission()) {
            return;
        }

        $this->sites[] = [
            'classFqcn' => $this->currentClassFqcn(),
            'method' => $this->currentMethod(),
            'target' => $targetFqcn,
            'form' => $form,
            'provisionalKind' => $kind,
            'file' => null,
            'line' => $callNode->getStartLine(),
            'confidence' => 'high',
        ];
    }

    private function shouldSkipEmission(): bool
    {
        if ($this->closureDepth > 0) {
            return true;
        }

        $classFqcn = $this->currentClassFqcn();
        if ($classFqcn === null) {
            return true;
        }

        return false;
    }

    private function currentClassFqcn(): ?string
    {
        if ($this->classStack === []) {
            return null;
        }

        return $this->classStack[count($this->classStack) - 1]['class'];
    }

    private function currentMethod(): ?string
    {
        if ($this->classStack === []) {
            return null;
        }

        return $this->classStack[count($this->classStack) - 1]['method'];
    }

    /**
     * Resolve a statically determinable target class FQCN from an expression.
     * Returns null if the expression is not a `new X(...)` or `X::class` form.
     */
    private function resolveStaticClass(?Node $expr): ?string
    {
        if ($expr === null) {
            return null;
        }

        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            return $expr->class->toString();
        }

        if ($expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'class'
        ) {
            return $expr->class->toString();
        }

        return null;
    }

    private function classifyUnresolvedReason(Node $expr): string
    {
        if ($expr instanceof Node\Expr\Variable) {
            return 'dynamic_class_name';
        }

        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Expr\Variable) {
            return 'dynamic_class_name';
        }

        if ($expr instanceof Node\Expr\FuncCall
            && $expr->name instanceof Node\Name
            && in_array(strtolower($expr->name->toString()), ['app', 'resolve'], true)
        ) {
            return 'container_resolution';
        }

        if ($expr instanceof Node\Expr\MethodCall
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'make'
        ) {
            return 'container_resolution';
        }

        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            return 'string_concatenation';
        }

        if ($expr instanceof Node\Scalar\Encapsed) {
            return 'string_concatenation';
        }

        if ($expr instanceof Node\Expr\Ternary) {
            return 'conditional_dispatch';
        }

        if ($expr instanceof Node\Expr\Match_) {
            return 'conditional_dispatch';
        }

        return 'dynamic_class_name';
    }

    private function renderExpression(Node\Expr $callNode, string $callLabel): string
    {
        try {
            $rendered = $this->printer->prettyPrintExpr($callNode);
        } catch (\Throwable) {
            $rendered = $callLabel.'(...)';
        }

        // Collapse whitespace and truncate so we keep the schema entry compact.
        $rendered = trim((string) preg_replace('/\s+/', ' ', $rendered));
        if (strlen($rendered) > 80) {
            $rendered = substr($rendered, 0, 77).'...';
        }

        return $rendered;
    }

    /**
     * @return array<int, array{
     *     classFqcn: ?string,
     *     method: ?string,
     *     target: string,
     *     form: 'helper'|'facade'|'job_helper'|'dispatchable',
     *     provisionalKind: 'event'|'job'|'ambiguous',
     *     file: ?string,
     *     line: int,
     *     confidence: 'high'
     * }>
     */
    public function getSites(): array
    {
        return $this->sites;
    }

    /**
     * @return array<int, array{file: ?string, line: int, expression: string, reason: string}>
     */
    public function getUnresolved(): array
    {
        return $this->unresolved;
    }
}
