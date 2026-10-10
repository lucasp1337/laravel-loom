<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lucasp\Loom\Dto\RouteFileReference;
use Lucasp\Loom\Support\Ast\Arg;
use Lucasp\Loom\Support\Ast\Args;
use PhpParser\Node;

/**
 * Evaluates the path expression of a route-file loading call to an absolute
 * path inside the project root. Understands string literals, `__DIR__`,
 * `__FILE__`, `DIRECTORY_SEPARATOR`, `.` concatenation, `dirname()`,
 * `base_path()` and `app_path()`. Anything else is {@see RoutePathProblem::DYNAMIC}.
 *
 * `app_path()` assumes the default `app/` directory: `useAppPath()` is a
 * runtime override Loom cannot see.
 *
 * @internal
 */
final class RoutePathResolver
{
    /**
     * @return string|RoutePathProblem the normalised absolute path, using DIRECTORY_SEPARATOR
     */
    public function resolve(RouteFileReference $reference, string $sourceFile, string $appRoot): string|RoutePathProblem
    {
        $root = self::normalise(Str::rtrim($appRoot, '/\\'));
        $raw = $this->evaluate($reference->path, $sourceFile, $root);
        if ($raw === null) {
            return RoutePathProblem::DYNAMIC;
        }

        $path = Str::replace('\\', '/', $raw);
        if (! Str::startsWith($path, '/') && ! Str::isMatch('#^[A-Za-z]:/#', $path)) {
            return RoutePathProblem::RELATIVE;
        }

        $path = self::normalise($path);
        if (! Str::startsWith($path, $root.'/')) {
            return RoutePathProblem::OUTSIDE_ROOT;
        }

        return Str::replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function evaluate(Node\Expr $expr, string $sourceFile, string $root): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }
        if ($expr instanceof Node\Scalar\MagicConst\Dir) {
            return dirname($sourceFile);
        }
        if ($expr instanceof Node\Scalar\MagicConst\File) {
            return $sourceFile;
        }
        if ($expr instanceof Node\Expr\ConstFetch && $expr->name->toString() === 'DIRECTORY_SEPARATOR') {
            return '/';
        }
        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $left = $this->evaluate($expr->left, $sourceFile, $root);
            $right = $left === null ? null : $this->evaluate($expr->right, $sourceFile, $root);

            return $right === null ? null : $left.$right;
        }
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name) {
            return $this->call(Fqcn::normalize($expr->name->toString()), Args::of($expr->args), $sourceFile, $root);
        }

        return null;
    }

    private function call(string $function, Args $args, string $sourceFile, string $root): ?string
    {
        // too many arguments, or first-class callable syntax `base_path(...)`
        if ($args->count() > 2 || $args->isFirstClassCallable()) {
            return null;
        }
        $first = $args->at(0);

        return match ($function) {
            'base_path' => $this->join($root, $first, $sourceFile, $root),
            'app_path' => $this->join($root.'/app', $first, $sourceFile, $root),
            'dirname' => $this->dirname($first, $args->at(1), $sourceFile, $root),
            default => null,
        };
    }

    /** Laravel's `join_paths`: the argument loses its leading separators. */
    private function join(string $base, ?Arg $argument, string $sourceFile, string $root): ?string
    {
        if ($argument === null) {
            return $base;
        }

        $suffix = $this->evaluate($argument->value, $sourceFile, $root);

        return $suffix === null ? null : $base.'/'.Str::ltrim(Str::replace('\\', '/', $suffix), '/');
    }

    private function dirname(?Arg $path, ?Arg $levels, string $sourceFile, string $root): ?string
    {
        if ($path === null) {
            return null;
        }

        $count = 1;
        if ($levels !== null) {
            $count = $levels->value instanceof Node\Scalar\Int_ ? $levels->value->value : 0;
        }
        $value = $this->evaluate($path->value, $sourceFile, $root);
        if ($value === null || $count < 1) {
            return null;
        }

        return dirname(Str::replace('\\', '/', $value), $count);
    }

    /** Collapse `.` and `..` segments lexically; the filesystem is not consulted. */
    private static function normalise(string $path): string
    {
        $path = Str::replace('\\', '/', $path);
        $drive = Str::isMatch('#^[A-Za-z]:/#', $path) ? Str::substr($path, 0, 2) : '';
        $segments = [];

        foreach (explode('/', Str::substr($path, strlen($drive))) as $segment) {
            // `..` climbs to the parent
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            // an empty or `.` segment adds nothing
            if ($segment === '' || $segment === '.') {
                continue;
            }

            $segments[] = $segment;
        }

        return $drive.'/'.Arr::join($segments, '/');
    }
}
