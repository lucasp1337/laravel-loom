<?php

declare(strict_types=1);

use Lucasp\Loom\Benchmarks\AppGenerator;
use Lucasp\Loom\Benchmarks\BenchProfile;

it('names each profile after its factory', function () {
    expect(BenchProfile::tiny()->name)->toBe('tiny');
    expect(BenchProfile::medium()->name)->toBe('medium');
    expect(BenchProfile::large()->name)->toBe('large');
});

it('scales medium and large counts off the tiny base shape', function () {
    $tiny = BenchProfile::tiny();
    $medium = BenchProfile::medium();
    $large = BenchProfile::large();

    foreach (array_keys($tiny->counts) as $category) {
        expect($medium->count($category))->toBe($tiny->count($category) * 14);
        expect($large->count($category))->toBe($tiny->count($category) * 145);
    }
});

it('keeps the same category shape across profiles', function () {
    expect(array_keys(BenchProfile::medium()->counts))
        ->toBe(array_keys(BenchProfile::tiny()->counts));
    expect(array_keys(BenchProfile::large()->counts))
        ->toBe(array_keys(BenchProfile::tiny()->counts));
});

it('returns 0 for an unknown category', function () {
    expect(BenchProfile::tiny()->count('does-not-exist'))->toBe(0);
});

it('totals files as the sum of counts plus the fixed files', function () {
    foreach ([BenchProfile::tiny(), BenchProfile::medium(), BenchProfile::large()] as $profile) {
        expect($profile->totalFiles())
            ->toBe(array_sum($profile->counts) + AppGenerator::FIXED_FILES);
    }
});

it('resolves a profile by name', function () {
    expect(BenchProfile::named('tiny'))->toEqual(BenchProfile::tiny());
    expect(BenchProfile::named('medium'))->toEqual(BenchProfile::medium());
    expect(BenchProfile::named('large'))->toEqual(BenchProfile::large());
});

it('throws on an unknown profile name', function () {
    BenchProfile::named('bogus');
})->throws(InvalidArgumentException::class);

it('lists all three profiles in ascending size order', function () {
    $all = BenchProfile::all();

    expect($all)->toHaveCount(3);
    expect(array_map(fn (BenchProfile $p): string => $p->name, $all))
        ->toBe(['tiny', 'medium', 'large']);
});
