<?php

declare(strict_types=1);

use Lucasp\Loom\Tools\Pages\RegionSource;
use Lucasp\Loom\Tools\ReferencePages;
use Lucasp\Loom\Tools\Regions\GeneratedRegion;
use Lucasp\Loom\Tools\Regions\Markers;
use Lucasp\Loom\Tools\Regions\Region;
use Lucasp\Loom\Tools\Regions\RegionName;
use Lucasp\Loom\Tools\Regions\RegionPrefix;
use Lucasp\Loom\Tools\SourceContext;
use Lucasp\Loom\Tools\SourceRegistry;

/** A source with fixed regions, for exercising the renderer without the real docs. */
function fixtureSource(string ...$bodies): RegionSource
{
    return new class($bodies) implements RegionSource
    {
        /** @param  array<string, string>  $bodies */
        public function __construct(private readonly array $bodies) {}

        public function regions(): array
        {
            $regions = [];
            foreach ($this->bodies as $name => $body) {
                $regions[] = new GeneratedRegion(RegionName::fromString($name), $body);
            }

            return $regions;
        }
    };
}

/** @param  array<string, string>  $pages  path under docs/ => content */
function fixtureDocs(array $pages): string
{
    $root = sys_get_temp_dir().'/loom-docs-'.bin2hex(random_bytes(6));
    foreach ($pages as $path => $content) {
        if (! is_dir(dirname($root.'/docs/'.$path))) {
            mkdir(dirname($root.'/docs/'.$path), 0777, true);
        }
        file_put_contents($root.'/docs/'.$path, $content);
    }

    register_shutdown_function(static function () use ($root): void {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    });

    return $root;
}

function marked(string $name, string $inside = ''): string
{
    $region = RegionName::fromString($name);

    return Markers::open($region)."\n".$inside.Markers::close($region)."\n";
}

it('places one region on several pages, in nested directories', function (): void {
    $root = fixtureDocs([
        'a.md' => "# A\n\n".marked('shared'),
        'deep/b.md' => "# B\n\n".marked('shared')."\nafter\n",
        'plain.md' => "# No markers\n",
    ]);

    $pages = (new ReferencePages($root, [fixtureSource(shared: 'Table')]))->render();

    expect(array_keys($pages))->toBe(['docs/a.md', 'docs/deep/b.md'])
        ->and($pages['docs/a.md'])->toContain("Table\n".Markers::close(RegionName::fromString('shared')))
        ->and($pages['docs/deep/b.md'])->toContain('Table')->toContain("\nafter\n");
});

it('writes only drifted pages and reports drift without writing', function (): void {
    $root = fixtureDocs(['a.md' => marked('r', 'old'."\n")]);
    $renderer = new ReferencePages($root, [fixtureSource(r: 'new')]);
    $before = file_get_contents($root.'/docs/a.md');

    expect($renderer->drifted())->toBe(['docs/a.md'])
        ->and(file_get_contents($root.'/docs/a.md'))->toBe($before)
        ->and($renderer->write())->toBe(['docs/a.md'])
        ->and($renderer->drifted())->toBe([])
        ->and($renderer->write())->toBe([]);
});

it('fails on a marker no source produces, naming page and region', function (): void {
    $root = fixtureDocs(['a.md' => marked('ghost')]);

    expect(fn () => (new ReferencePages($root, [fixtureSource()]))->render())
        ->toThrow(RuntimeException::class, 'docs/a.md places region ghost but no source produces it');
});

it('fails on a region produced but placed on no page', function (): void {
    $root = fixtureDocs(['a.md' => "# A\n"]);

    expect(fn () => (new ReferencePages($root, [fixtureSource(orphan: 'x')]))->render())
        ->toThrow(RuntimeException::class, '<!-- generated:orphan -->');
});

it('fails when two sources produce the same region', function (): void {
    $root = fixtureDocs(['a.md' => marked('dup')]);

    expect(fn () => (new ReferencePages($root, [fixtureSource(dup: '1'), fixtureSource(dup: '2')]))->render())
        ->toThrow(RuntimeException::class, 'Region dup is produced by more than one source');
});

it('rejects malformed markers with a message naming page and region', function (string $content, string $message): void {
    $root = fixtureDocs(['a.md' => $content]);

    expect(fn () => (new ReferencePages($root, [fixtureSource(a: 'x', b: 'y')]))->render())
        ->toThrow(RuntimeException::class, $message);
})->with([
    'unclosed' => ["<!-- generated:a -->\ntext\n", 'docs/a.md never closes region a'],
    'closed but never opened' => ["text\n<!-- /generated:a -->\n", 'docs/a.md closes region a that was never opened'],
    'nested' => ["<!-- generated:a -->\n<!-- generated:b -->\n<!-- /generated:b -->\n<!-- /generated:a -->\n", 'docs/a.md nests region b inside region a'],
    'mismatched close' => ["<!-- generated:a -->\n<!-- /generated:b -->\n", 'docs/a.md closes region b while region a is open'],
    'duplicate in page' => ["<!-- generated:a -->\n<!-- /generated:a -->\n<!-- generated:a -->\n<!-- /generated:a -->\n", 'docs/a.md places region a more than once'],
    'stray marker text' => ["<!--generated:a-->\n", 'docs/a.md has marker text that is not a well-formed generated marker'],
]);

it('renders and parses markers through one class', function (): void {
    $name = RegionName::keyed(RegionPrefix::SchemaSection, 'events');
    $text = Markers::open($name).'body'.Markers::close($name);
    $pairs = Markers::pairs($text, 'p.md');

    expect($pairs)->toHaveCount(1)
        ->and($pairs[0]->name->value)->toBe('schema:events')
        ->and(substr($text, $pairs[0]->bodyStart, $pairs[0]->bodyEnd - $pairs[0]->bodyStart))->toBe('body')
        ->and(RegionName::fixed(Region::SchemaTop)->value)->toBe('schema:top');
});

it('discovers every RegionSource class in tools/Pages', function (): void {
    $declared = [];
    foreach (glob(dirname(__DIR__, 2).'/tools/Pages/*.php') as $file) {
        if (preg_match('/^final class (\w+) implements RegionSource/m', (string) file_get_contents($file), $m) === 1) {
            $declared[] = 'Lucasp\\Loom\\Tools\\Pages\\'.$m[1];
        }
    }

    expect($declared)->not->toBeEmpty()
        ->and(SourceRegistry::classes())->toEqualCanonicalizing($declared);
});

it('has a producing source for every marker in the real docs and no unplaced region', function (): void {
    $root = dirname(__DIR__, 2);
    $sources = SourceRegistry::discover(new SourceContext($root));

    // render() throws for a marker without a source or a region without a marker.
    expect(array_keys((new ReferencePages($root, $sources))->render()))->not->toBeEmpty();
});

it('exits non-zero from the --check flag only when a region drifted', function (): void {
    $root = dirname(__DIR__, 2);
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/tools/generate-reference.php').' --check 2>&1', $output, $code);

    expect($code)->toBe(0, implode("\n", $output));
});
