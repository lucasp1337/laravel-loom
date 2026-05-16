<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Thin convenience wrapper around nikic/php-parser.
 *
 * Owns a single Parser instance so scanners reuse it across files. Always
 * runs NameResolver first so visitors see fully qualified names — manual
 * name resolution is forbidden.
 */
class AstWalker
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * Parse a file and traverse it with the given visitors.
     *
     * Returns the contents of the file (or null if it could not be read/parsed)
     * so visitors can attach source-level context (e.g. raw expressions for
     * unresolved_dispatches entries).
     *
     * @param  array<int, NodeVisitor>  $visitors
     */
    public function walk(string $file, array $visitors): ?string
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            return null;
        }

        try {
            $ast = $this->parser->parse($source);
        } catch (Error) {
            return null;
        }

        if ($ast === null) {
            return $source;
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        foreach ($visitors as $visitor) {
            $traverser->addVisitor($visitor);
        }
        $traverser->traverse($ast);

        return $source;
    }
}
