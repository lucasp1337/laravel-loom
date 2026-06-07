<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\RouteChainEntry;
use Lucasp\Loom\Dto\RouteEntry;
use Lucasp\Loom\Index\RouterMethod;
use Lucasp\Loom\Scanners\Visitors\RouteChainVisitor;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ScannerFilesystem;
use PhpParser\Node;

/**
 * Discovers HTTP routes declared via the `Route` facade under routes/.
 *
 * Slice 1: leaf verb routes only (get/post/.../any/match). Group prefixes,
 * middleware chains, and dispatch cross-links are out of scope.
 */
final class RouteScanner implements Scanner
{
    use ScannerFilesystem;

    private AstWalker $walker;

    public function __construct(?AstWalker $walker = null)
    {
        $this->walker = $walker ?? new AstWalker;
    }

    /**
     * @return array{routes: list<RouteEntry>}
     */
    public function scan(string $appRoot): array
    {
        $routesDir = $appRoot.DIRECTORY_SEPARATOR.'routes';
        if (! is_dir($routesDir)) {
            return ['routes' => []];
        }

        $entries = [];

        foreach ($this->iteratePhpFiles($routesDir) as $file) {
            // Fresh visitor per file: walk()===null bypasses beforeTraverse,
            // so reusing one would leak the previous file's entries.
            $visitor = new RouteChainVisitor;
            if ($this->walker->walk($file->getPathname(), [$visitor]) === null) {
                continue;
            }
            $relative = $this->relativePath($appRoot, $file->getPathname());

            foreach ($this->translate($visitor->getEntries(), $relative) as $entry) {
                $entries[] = $entry;
            }
        }

        usort(
            $entries,
            fn (RouteEntry $a, RouteEntry $b): int => [$a->file, $a->line, $a->method] <=> [$b->file, $b->line, $b->method],
        );

        return ['routes' => $entries];
    }

    /**
     * @param  list<RouteChainEntry>  $rawEntries
     * @return list<RouteEntry>
     */
    private function translate(array $rawEntries, string $relativeFile): array
    {
        $out = [];
        foreach ($rawEntries as $raw) {
            $name = $this->resolveName($raw);

            foreach ($this->expand($raw, $relativeFile, $name) as $entry) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Expand one raw chain into one entry per HTTP verb it declares.
     *
     * @return list<RouteEntry>
     */
    private function expand(RouteChainEntry $raw, string $relativeFile, ?string $name): array
    {
        $args = $raw->rootArgs;

        if ($raw->rootMethod === RouterMethod::MATCH) {
            return $this->expandMatch($raw, $relativeFile, $name);
        }

        $method = $raw->rootMethod === RouterMethod::ANY
            ? 'ANY'
            : RouterMethod::VERB_MAP[$raw->rootMethod];

        $uri = AstHelpers::scalarString($args[0] ?? null);
        if ($uri === null) {
            return [];
        }

        $action = $this->resolveAction($args[1] ?? null);

        return [new RouteEntry(
            method: $method,
            uri: $uri,
            name: $name,
            controllerFqcn: $action['fqcn'],
            controllerMethod: $action['method'],
            file: $relativeFile,
            line: $raw->line,
        )];
    }

    /**
     * `Route::match([verbs], uri, action)` — one entry per uppercased verb,
     * all sharing uri/action/name.
     *
     * @return list<RouteEntry>
     */
    private function expandMatch(RouteChainEntry $raw, string $relativeFile, ?string $name): array
    {
        $args = $raw->rootArgs;

        $verbs = $this->verbList($args[0] ?? null);
        $uri = AstHelpers::scalarString($args[1] ?? null);
        if ($verbs === [] || $uri === null) {
            return [];
        }

        $action = $this->resolveAction($args[2] ?? null);

        $out = [];
        foreach ($verbs as $verb) {
            $out[] = new RouteEntry(
                method: $verb,
                uri: $uri,
                name: $name,
                controllerFqcn: $action['fqcn'],
                controllerMethod: $action['method'],
                file: $relativeFile,
                line: $raw->line,
            );
        }

        return $out;
    }

    /**
     * Resolve the verb array of a `match` call to uppercase HTTP verbs. Returns
     * empty when not a literal array of strings.
     *
     * @return list<string>
     */
    private function verbList(Node\Arg|Node\VariadicPlaceholder|null $arg): array
    {
        if (! $arg instanceof Node\Arg || ! $arg->value instanceof Node\Expr\Array_) {
            return [];
        }

        $verbs = [];
        foreach ($arg->value->items as $item) {
            if (! $item->value instanceof Node\Scalar\String_) {
                return [];
            }
            $verbs[] = strtoupper($item->value->value);
        }

        return $verbs;
    }

    /**
     * Resolve a route action node to a controller FQCN + method.
     *
     * @return array{fqcn: ?string, method: ?string}
     */
    private function resolveAction(Node\Arg|Node\VariadicPlaceholder|null $arg): array
    {
        if (! $arg instanceof Node\Arg) {
            return ['fqcn' => null, 'method' => null];
        }

        $value = $arg->value;

        // Closure / arrow function: no controller.
        if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) {
            return ['fqcn' => null, 'method' => null];
        }

        // [Ctrl::class, 'method'] or [Ctrl::class].
        if ($value instanceof Node\Expr\Array_) {
            return $this->resolveArrayAction($value);
        }

        // Bare Ctrl::class -> invokable.
        $fqcn = AstHelpers::classConstFqcn($value);
        if ($fqcn !== null) {
            return ['fqcn' => $fqcn, 'method' => '__invoke'];
        }

        // 'Class@method' (legacy) or 'Class' (invokable string).
        $string = AstHelpers::scalarString($value);
        if ($string !== null) {
            return $this->resolveStringAction($string);
        }

        // Variable, dynamic expression, etc. — never guess.
        return ['fqcn' => null, 'method' => null];
    }

    /**
     * @return array{fqcn: ?string, method: ?string}
     */
    private function resolveArrayAction(Node\Expr\Array_ $array): array
    {
        $tuple = AstHelpers::tupleCallable($array);
        if ($tuple !== null) {
            return ['fqcn' => $tuple['class'], 'method' => $tuple['method']];
        }

        // Single-element [Ctrl::class] -> invokable.
        if (count($array->items) === 1) {
            $fqcn = AstHelpers::classConstFqcn($array->items[0]->value);
            if ($fqcn !== null) {
                return ['fqcn' => $fqcn, 'method' => '__invoke'];
            }
        }

        return ['fqcn' => null, 'method' => null];
    }

    /**
     * @return array{fqcn: ?string, method: ?string}
     */
    private function resolveStringAction(string $action): array
    {
        if (str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);

            return ['fqcn' => ltrim($class, '\\'), 'method' => $method];
        }

        // No '@' -> invokable controller string.
        return ['fqcn' => ltrim($action, '\\'), 'method' => '__invoke'];
    }

    /**
     * Scan chain links for a `->name(<string>)` call. Last-wins; null when
     * absent or unresolvable.
     */
    private function resolveName(RouteChainEntry $raw): ?string
    {
        $name = null;

        // Index 0 is the root call; modifiers start at index 1.
        $chain = $raw->chain;
        for ($i = 1, $n = count($chain); $i < $n; $i++) {
            if ($chain[$i]->method !== 'name') {
                continue;
            }
            $label = AstHelpers::scalarString($chain[$i]->args[0] ?? null);
            if ($label !== null) {
                $name = $label;
            }
        }

        return $name;
    }
}
