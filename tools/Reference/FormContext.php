<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

/** Where an example snippet sits, so tests can place it in a real class and scan it. */
enum FormContext
{
    /** The body of a method of a plain class. */
    case Method;

    /** The body of a method of a trait. */
    case TraitMethod;

    /** The body of `booted()` on an Eloquent model. */
    case ModelBooted;

    /** A complete PHP file with the snippet in the context's position. */
    public function wrap(string $snippet, string $class, string $imports): string
    {
        $uses = "use Illuminate\\Support\\Facades\\Bus;\nuse Illuminate\\Support\\Facades\\Event;\nuse Illuminate\\Support\\Facades\\Mail;\n".$imports;

        return match ($this) {
            self::Method => "<?php\n\nnamespace App\\Forms;\n\n{$uses}\nclass {$class}\n{\n    public function run(): void\n    {\n{$snippet}\n    }\n}\n",
            self::TraitMethod => "<?php\n\nnamespace App\\Forms;\n\n{$uses}\ntrait {$class}\n{\n    public function run(): void\n    {\n{$snippet}\n    }\n}\n",
            self::ModelBooted => "<?php\n\nnamespace App\\Forms;\n\n{$uses}\nclass {$class} extends \\Illuminate\\Database\\Eloquent\\Model\n{\n    protected static function booted(): void\n    {\n{$snippet}\n    }\n}\n",
        };
    }
}
