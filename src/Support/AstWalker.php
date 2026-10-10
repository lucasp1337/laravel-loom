<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Lucasp\Loom\Dto\SkippedFile;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Wrapper around nikic/php-parser. Always runs NameResolver before
 * caller visitors so visitors see fully qualified names.
 *
 * @internal
 */
class AstWalker
{
    private Parser $parser;

    /** @var array<string, SkippedFile> keyed by absolute path so a file shared by several scanners counts once */
    private array $skipped = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * Returns the file's source (or null on read/parse failure) so callers
     * can attach source-level context. Failures are recorded, see skippedFiles().
     *
     * @param  array<int, NodeVisitor>  $visitors
     */
    public function walk(string $file, array $visitors): ?string
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            $this->skipped[$file] ??= new SkippedFile($file, null, 'Could not read file');

            return null;
        }

        try {
            $ast = $this->parser->parse($source);
        } catch (Error $e) {
            $line = $e->getStartLine();
            $this->skipped[$file] ??= new SkippedFile($file, $line > 0 ? $line : null, $e->getRawMessage());

            return null;
        }

        if ($ast === null) {
            return $source;
        }

        $this->traverse($ast, $visitors);

        return $source;
    }

    /**
     * Parse `$source` and run `$visitors` over it behind the production
     * NameResolver, for callers that already hold the source (tests, snippets).
     * Unlike walk(), a parse error throws.
     *
     * @param  array<int, NodeVisitor>  $visitors
     * @return array<int, Node\Stmt> the name-resolved statements
     *
     * @throws Error when the source does not parse
     */
    public static function walkSource(string $source, array $visitors = []): array
    {
        $walker = new self;

        return $walker->traverse($walker->parser->parse($source) ?? [], $visitors);
    }

    /**
     * @param  array<int, Node\Stmt>  $ast
     * @param  array<int, NodeVisitor>  $visitors
     * @return array<int, Node\Stmt>
     */
    private function traverse(array $ast, array $visitors): array
    {
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        foreach ($visitors as $visitor) {
            $traverser->addVisitor($visitor);
        }

        /** @var array<int, Node\Stmt> */
        return $traverser->traverse($ast);
    }

    /**
     * Files that could not be read or parsed so far, sorted by path (absolute).
     *
     * @return list<SkippedFile>
     */
    public function skippedFiles(): array
    {
        $skipped = $this->skipped;
        $skipped = collect($skipped)->sortKeys()->all();

        return array_values($skipped);
    }
}
