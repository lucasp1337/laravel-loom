<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lucasp\Loom\Dto\RouteIssue;
use Lucasp\Loom\Index\UnresolvedRouteReason;
use Lucasp\Loom\Support\Fqcn;
use Lucasp\Loom\Support\RouteAttributeName;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Finds route-declaring attributes (`#[Get('x')]`, `#[Resource('photos')]`)
 * on controller classes and their methods. Loom does not read them, so each
 * one becomes an `attribute_route` issue instead of disappearing.
 *
 * @internal
 */
final class RouteAttributeVisitor extends CollectingVisitor
{
    private PrettyPrinter $printer;

    private bool $inController = false;

    /** @var list<RouteIssue> */
    private array $issues = [];

    public function __construct()
    {
        $this->printer = new PrettyPrinter;
    }

    protected function reset(): void
    {
        $this->inController = false;
        $this->issues = [];
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Class_) {
            $fqcn = $node->namespacedName?->toString() ?? '';
            // controllers by name or namespace only: other classes' attributes are not routes
            $this->inController = $fqcn !== '' && (Str::endsWith($fqcn, 'Controller') || Str::contains($fqcn, '\\Controllers\\'));
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        // read on leave: NameResolver rewrites attribute names as it descends into them
        if ($this->inController && ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\ClassMethod)) {
            $this->inspect($node->attrGroups);
        }

        if ($node instanceof Node\Stmt\Class_) {
            $this->inController = false;
        }

        return null;
    }

    /** @return list<RouteIssue> */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /** @param  list<Node\AttributeGroup>  $groups */
    private function inspect(array $groups): void
    {
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                // NameResolver has rewritten the name to its FQCN by the time the class is entered
                if (RouteAttributeName::matches($attribute->name->toString())) {
                    $this->issues[] = new RouteIssue(UnresolvedRouteReason::ATTRIBUTE_ROUTE, $attribute->getStartLine(), $this->render($attribute));
                }
            }
        }
    }

    private function render(Node\Attribute $attribute): string
    {
        $args = [];
        foreach ($attribute->args as $arg) {
            $value = $this->printer->prettyPrintExpr($arg->value);
            $args[] = $arg->name === null ? $value : $arg->name->toString().': '.$value;
        }

        $rendered = Str::squish('#['.Fqcn::short($attribute->name->toString()).'('.Arr::join($args, ', ').')]');

        return Str::length($rendered) > 80 ? Str::substr($rendered, 0, 77).'...' : $rendered;
    }
}
