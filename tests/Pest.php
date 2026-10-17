<?php

declare(strict_types=1);

use Lucasp\Loom\Mcp\IndexRepository;
use Lucasp\Loom\Support\Ast\AstWalker;
use Lucasp\Loom\Tests\TestCase;
use PhpParser\Node;
use PhpParser\NodeVisitor;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Run one visitor over a PHP source string behind the production NameResolver
 * and return it, so a test reads its collected state.
 *
 * @template T of NodeVisitor
 *
 * @param  T  $visitor
 * @return T
 */
function runVisitor(NodeVisitor $visitor, string $source): NodeVisitor
{
    AstWalker::walkSource($source, [$visitor]);

    return $visitor;
}

/**
 * Parse a PHP expression string and return its root expression node.
 */
function parseExpr(string $expr): Node\Expr
{
    $ast = AstWalker::walkSource('<?php '.$expr.';');

    $stmt = $ast[0];
    expect($stmt)->toBeInstanceOf(Node\Stmt\Expression::class);

    return $stmt->expr;
}

/** Write a minimal index JSON to a temp file for the MCP tests; returns its path. */
function mcpTempIndex(array $overrides = []): string
{
    $index = array_merge([
        'schema_version' => '1.0',
        'loom_version' => '0.3.0',
        'scanned_at' => '2026-01-01T00:00:00Z',
        'laravel_version' => '12.x',
        'events' => [],
        'listeners' => [],
        'closure_listeners' => [],
        'observers' => [],
        'model_events' => [],
        'jobs' => [],
        'mailables' => [],
        'notifications' => [],
        'scheduled_tasks' => [],
        'routes' => [],
        'unresolved_dispatches' => [],
        'stats' => [],
    ], $overrides);

    $path = tempnam(sys_get_temp_dir(), 'loom-mcp-').'.json';
    file_put_contents($path, json_encode($index));

    return $path;
}

/** Point the MCP index repository at a snapshot file. */
function mcpUseIndex(string $path): void
{
    app(IndexRepository::class)->useSnapshot($path);
}
