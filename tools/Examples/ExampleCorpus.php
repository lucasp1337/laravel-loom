<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Examples;

use RuntimeException;

/** Every case under `docs/examples/`, in page order: primitive order, then file name. */
final class ExampleCorpus
{
    public const DIRECTORY = 'docs/examples';

    /** @return list<ExampleCase> */
    public static function all(string $root): array
    {
        return array_merge(...array_map(static fn (Primitive $primitive): array => self::for($root, $primitive), Primitive::cases()));
    }

    /** @return list<ExampleCase> */
    public static function for(string $root, Primitive $primitive): array
    {
        $files = glob("{$root}/".self::DIRECTORY."/{$primitive->value}/*.php") ?: [];
        sort($files);

        return array_map(static fn (string $file): ExampleCase => ExampleCase::parse($primitive, $file), $files);
    }

    /** Fails on a directory that is not a primitive, so no case goes unrendered. */
    public static function assertKnownDirectories(string $root): void
    {
        foreach (glob("{$root}/".self::DIRECTORY.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            Primitive::tryFrom(basename($directory)) ?? throw new RuntimeException(self::DIRECTORY.'/'.basename($directory).' is not a Primitive case');
        }
    }
}
