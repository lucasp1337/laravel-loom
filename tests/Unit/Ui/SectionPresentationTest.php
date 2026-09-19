<?php

declare(strict_types=1);

use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Ui\SectionPresentation;
use Lucasp\Loom\Ui\SectionSpec;

it('has a presentation spec for every Sections case', function (Sections $section) {
    $spec = SectionPresentation::for($section);

    expect($spec)->toBeInstanceOf(SectionSpec::class)
        ->and($spec->section)->toBe($section)
        ->and($spec->label)->not->toBe('')
        ->and($spec->emptyTitle)->not->toBe('')
        ->and($spec->columns)->not->toBeEmpty();
})->with(fn () => Sections::cases());

it('gives shortcuts to unique keys', function () {
    $keys = array_filter(array_map(static fn (SectionSpec $s): ?string => $s->shortcut, SectionPresentation::all()));

    expect($keys)->toHaveCount(count(array_unique($keys)))->and($keys)->not->toContain('d');
});

it('keeps the UI layer off the MCP layer and out of the query layer', function () {
    expect('Lucasp\Loom\Ui')->not->toUse('Lucasp\Loom\Mcp')
        ->and('Lucasp\Loom\Ui')->not->toUse('Lucasp\Loom\Dto');
});
