<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Query\EntityKind;
use Lucasp\Loom\Ui\Livewire\ChainPage;
use Lucasp\Loom\Ui\Livewire\Dashboard;
use Lucasp\Loom\Ui\Livewire\EntityDetail;
use Lucasp\Loom\Ui\Livewire\EventDetail;
use Lucasp\Loom\Ui\Livewire\SectionIndex;

$sections = implode('|', array_map(static fn (Sections $s): string => $s->value, Sections::cases()));
$detailSections = implode('|', array_map(
    static fn (EntityKind $k): string => $k->section()->value,
    array_filter(EntityKind::cases(), static fn (EntityKind $k): bool => $k !== EntityKind::EVENT),
));

Route::get('/', Dashboard::class)->name('loom.dashboard');
Route::get('chain/{fqcn}', ChainPage::class)->where('fqcn', '.+')->name('loom.chain');
Route::get('events/{fqcn}', EventDetail::class)->where('fqcn', '.+')->name('loom.event');
Route::get('{section}/{fqcn}', EntityDetail::class)
    ->where('section', $detailSections)->where('fqcn', '.+')->name('loom.entity');
Route::get('{section}', SectionIndex::class)->where('section', $sections)->name('loom.section');
