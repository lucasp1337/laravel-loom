<?php

declare(strict_types=1);

use Lucasp\Loom\Support\AppPath;

it('strips trailing separators from the root', function (string $root): void {
    expect(AppPath::root($root))->toBe('/srv/app')
        ->and(AppPath::prefix($root))->toBe('/srv/app'.DIRECTORY_SEPARATOR);
})->with(['plain' => ['/srv/app'], 'slash' => ['/srv/app/'], 'many' => ['/srv/app//']]);

it('joins a forward-slashed relative path under the root', function (): void {
    expect(AppPath::join('/srv/app/', 'bootstrap/app.php'))
        ->toBe('/srv/app'.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php');
});

it('relativises only paths under the root', function (): void {
    expect(AppPath::relative('/srv/app', '/srv/app/app/Events/A.php'))->toBe('app/Events/A.php')
        ->and(AppPath::relative('/srv/app/', '/srv/app/x.php'))->toBe('x.php')
        ->and(AppPath::relative('/srv/app', '/srv/app-other/x.php'))->toBeNull()
        ->and(AppPath::relative('/srv/app', '/etc/passwd'))->toBeNull();
});

it('displays app-relative paths and leaves outside paths as given', function (): void {
    expect(AppPath::display('/srv/app', '/srv/app/routes/web.php'))->toBe('routes/web.php')
        ->and(AppPath::display('/srv/app', '/elsewhere/web.php'))->toBe('/elsewhere/web.php');
});

it('detects absolute paths', function (string $path, bool $absolute): void {
    expect(AppPath::isAbsolute($path))->toBe($absolute);
})->with([
    'posix' => ['/var/x', true],
    'unc' => ['\\\\host\\share', true],
    'drive' => ['C:\\x', true],
    'drive slash' => ['d:/x', true],
    'relative' => ['storage/x', false],
    'dot' => ['./x', false],
]);
