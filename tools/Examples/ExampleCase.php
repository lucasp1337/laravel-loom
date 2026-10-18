<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Examples;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One `docs/examples/<primitive>/<case>.php` file, parsed. The format and
 * the marker grammar are documented in `tests/Docs/ExamplesTest.php`.
 */
final readonly class ExampleCase
{
    private const PATH = '~^// ([\w./-]+\.php)$~';

    private const TITLE = '~^// (.+)$~';

    private const MARKERS = '~//\s*([→✗].*)$~u';

    /**
     * @param  list<ExampleFile>  $files
     * @param  list<Marker>  $markers
     */
    public function __construct(
        public Primitive $primitive,
        public string $slug,
        public string $title,
        public array $files,
        public array $markers,
    ) {}

    public static function parse(Primitive $primitive, string $path): self
    {
        $name = $primitive->value.'/'.basename($path);
        $lines = explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($path)));
        if ($lines[0] !== '<?php') {
            throw new RuntimeException("{$name} must start with <?php");
        }

        $title = null;
        /** @var list<array{path: string, first: int, lines: list<string>}> $files */
        $files = [];
        foreach (array_slice($lines, 1, preserve_keys: true) as $index => $text) {
            $number = $index + 1;
            $current = array_key_last($files);
            match (true) {
                // A path comment opens the next app file.
                (bool) preg_match(self::PATH, $text, $opened) => $files[] = ['path' => $opened[1], 'first' => $number + 1, 'lines' => []],
                // Before the first file only the title comment and blank lines may appear.
                $current === null => $title = self::header($name, $text, $title),
                default => $files[$current]['lines'][] = $text,
            };
        }

        if ($title === null || $files === []) {
            throw new RuntimeException("{$name} needs a `// title` line and at least one `// path.php` line");
        }

        $parsed = array_map(static fn (array $file): ExampleFile => new ExampleFile($file['path'], $file['first'], $file['lines']), $files);

        return new self($primitive, basename($path, '.php'), $title, $parsed, self::markers($parsed));
    }

    /**
     * Every unresolved reason this case states with `✗`.
     *
     * @return Collection<int, string>
     */
    public function reasons(): Collection
    {
        return collect($this->markers)
            ->filter(static fn (Marker $marker): bool => $marker->kind === MarkerKind::Unread && $marker->text !== Marker::NOT_RECORDED)
            ->map(static fn (Marker $marker): string => $marker->text)
            ->unique()
            ->values();
    }

    private static function header(string $name, string $text, ?string $title): ?string
    {
        return match (true) {
            trim($text) === '' => $title,
            $title === null && preg_match(self::TITLE, $text, $match) === 1 => $match[1],
            default => throw new RuntimeException("{$name}: `{$text}` sits before the first `// path.php` line"),
        };
    }

    /**
     * @param  list<ExampleFile>  $files
     * @return list<Marker>
     */
    private static function markers(array $files): array
    {
        return array_values(collect($files)->flatMap(static fn (ExampleFile $file): Collection => collect($file->lines)
            ->flatMap(static function (string $text, int $offset) use ($file): array {
                if (preg_match(self::MARKERS, $text, $match) !== 1) {
                    return [];
                }

                return collect(preg_split('~(?=[→✗])~u', $match[1], -1, PREG_SPLIT_NO_EMPTY) ?: [])
                    ->map(static fn (string $marker): Marker => new Marker(
                        MarkerKind::from(Str::substr($marker, 0, 1)),
                        trim(Str::substr($marker, 1)),
                        $file->path,
                        $file->firstLine + $offset,
                    ))
                    ->all();
            }))
            ->all());
    }
}
