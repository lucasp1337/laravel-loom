<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

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
 * Each file is parsed and name-resolved once and the statements are kept for
 * the next scanner that walks it, so a scan parses a file once instead of once
 * per scanner. Visitors only read the tree, so sharing it is safe. A hit is
 * keyed by path and content hash, so an edited file is re-parsed. The cache
 * admits files until `$cacheBudgetBytes` of source is held and then stops
 * (no eviction): every scanner sweeps the same files in the same order, so
 * evicting would never hit, while keeping the first files hits for all of
 * them. Past the budget a file is parsed per walk, as before. Memory is about
 * 10 to 15 times the cached source size.
 *
 * @internal
 */
class AstWalker
{
    private Parser $parser;

    /** Hash used to detect that a cached file changed. */
    private const CONTENT_HASH = 'xxh3';

    /** @var array<string, SkippedFile> keyed by absolute path so a file shared by several scanners counts once */
    private array $skipped = [];

    /** @var array<string, ParsedFile> keyed by absolute path */
    private array $parsed = [];

    private int $cachedBytes = 0;

    private readonly int $cacheBudgetBytes;

    /** Upper bound of the automatic budget: about 330 MB of trees on a large app. */
    private const MAX_BUDGET_BYTES = 8 * 1024 * 1024;

    /** Measured tree memory per source byte (php-parser nodes carry positions and attributes). */
    private const TREE_BYTES_PER_SOURCE_BYTE = 40;

    /**
     * Source bytes worth caching: at most MAX_BUDGET_BYTES, and when the
     * memory limit is finite, a share of the headroom so the cache cannot
     * push a scan over the limit.
     */
    private static function defaultBudget(): int
    {
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        $headroom = $limit > 0 ? max(0, $limit - memory_get_usage()) : PHP_INT_MAX;

        return min(self::MAX_BUDGET_BYTES, intdiv($headroom, 2 * self::TREE_BYTES_PER_SOURCE_BYTE));
    }

    /**
     * @param  int|null  $cacheBudgetBytes  source bytes of parsed files kept for reuse, 0 disables the cache; null sizes it from the memory limit
     */
    public function __construct(?int $cacheBudgetBytes = null)
    {
        $this->cacheBudgetBytes = $cacheBudgetBytes ?? self::defaultBudget();
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

        $hash = hash(self::CONTENT_HASH, $source);
        $ast = $this->parsed[$file] ?? null;
        $ast = $ast?->hash === $hash ? $ast->statements : $this->parse($file, $source, $hash);

        if ($ast === null) {
            return null;
        }

        $this->visit($ast, $visitors);

        return $source;
    }

    /**
     * Parse and name-resolve, keeping the result while the budget allows.
     * A parse failure is recorded and not cached.
     *
     * @return array<int, Node\Stmt>|null null when the source does not parse
     */
    private function parse(string $file, string $source, string $hash): ?array
    {
        try {
            $statements = $this->resolve($this->parser->parse($source) ?? []);
        } catch (Error $e) {
            $line = $e->getStartLine();
            $this->skipped[$file] ??= new SkippedFile($file, $line > 0 ? $line : null, $e->getRawMessage());

            return null;
        }

        $size = strlen($source);
        $this->cachedBytes -= isset($this->parsed[$file]) ? $this->parsed[$file]->size : 0;
        unset($this->parsed[$file]);

        if ($this->cachedBytes + $size <= $this->cacheBudgetBytes) {
            $this->cachedBytes += $size;
            $this->parsed[$file] = new ParsedFile($hash, $size, $statements);
        }

        return $statements;
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
        $walker = new self(0);
        $statements = $walker->resolve($walker->parser->parse($source) ?? []);
        $walker->visit($statements, $visitors);

        return $statements;
    }

    /**
     * @param  array<int, Node\Stmt>  $ast
     * @return array<int, Node\Stmt>
     */
    private function resolve(array $ast): array
    {
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);

        /** @var array<int, Node\Stmt> */
        return $traverser->traverse($ast);
    }

    /**
     * @param  array<int, Node\Stmt>  $ast  already name-resolved
     * @param  array<int, NodeVisitor>  $visitors
     */
    private function visit(array $ast, array $visitors): void
    {
        $traverser = new NodeTraverser;
        foreach ($visitors as $visitor) {
            $traverser->addVisitor($visitor);
        }
        $traverser->traverse($ast);
    }

    /**
     * Record a file a scanner read but could not extract what it expected from.
     * The first note per file wins, like a parse failure.
     */
    public function recordSkip(string $file, ?int $line, string $message): void
    {
        $this->skipped[$file] ??= new SkippedFile($file, $line, $message);
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
