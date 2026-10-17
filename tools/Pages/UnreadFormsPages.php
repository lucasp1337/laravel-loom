<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Illuminate\Support\Str;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Tools\Reference\UnreadForm;
use Lucasp\Loom\Tools\Reference\UnreadForms;
use Lucasp\Loom\Tools\Reference\UnreadSection;
use Lucasp\Loom\Tools\Regions\GeneratedRegion;
use Lucasp\Loom\Tools\Regions\Region;

/**
 * The "forms Loom does not read" page: every unresolved reason with its code
 * shape and fix, from the reason enum and the typed examples in
 * `tools/Reference/UnreadForms`.
 */
final class UnreadFormsPages implements RegionSource
{
    public function regions(): array
    {
        $reasons = collect(UnresolvedReason::cases())->groupBy(static fn (UnresolvedReason $reason): string => UnreadSection::of($reason)->name);

        $blocks = collect(UnreadSection::cases())->map(function (UnreadSection $section) use ($reasons): string {
            $body = collect($reasons->get($section->name, []))->map(fn (UnresolvedReason $reason): string => $this->reason($reason))->implode("\n\n");

            return "## {$section->title()}\n\n{$section->intro()}\n\n{$body}";
        });

        return [new GeneratedRegion(Region::UnreadForms, $blocks->implode("\n\n"))];
    }

    private function reason(UnresolvedReason $reason): string
    {
        $forms = collect(UnreadForms::for($reason))->map(fn (UnreadForm $form): string => "```php\n{$form->display()}\n```\n\n**Fix:** {$form->fix}");

        return "### `{$reason->value}`\n\n".Str::ucfirst($reason->summary())."\n\n".$forms->implode("\n\n");
    }
}
