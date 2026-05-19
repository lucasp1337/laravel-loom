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
 * Wrapper around nikic/php-parser. Always runs NameResolver before
 * caller visitors so visitors see fully qualified names.
 */
class AstWalker
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * Returns the file's source (or null on read/parse failure) so callers
     * can attach source-level context.
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
