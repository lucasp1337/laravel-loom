<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Lucasp\Loom\Benchmarks\AppGenerator;
use Lucasp\Loom\Benchmarks\BenchProfile;

/**
 * @return list<string> sorted relative paths of every .php file under $dir
 */
function benchPhpFiles(string $dir): array
{
    $paths = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $paths[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
        }
    }

    sort($paths, SORT_STRING);

    return $paths;
}

afterEach(function () {
    $files = new Filesystem;
    /** @var list<string> $dirs */
    $dirs = $GLOBALS['benchTempDirs'] ?? [];
    foreach ($dirs as $dir) {
        $files->deleteDirectory($dir);
    }
    $GLOBALS['benchTempDirs'] = [];
});

/**
 * Reserve a unique temp dir that afterEach will clean up.
 */
function benchTempDir(): string
{
    $dir = sys_get_temp_dir().'/loom-bench-test-'.uniqid('', true);
    /** @var list<string> $dirs */
    $dirs = $GLOBALS['benchTempDirs'] ?? [];
    $dirs[] = $dir;
    $GLOBALS['benchTempDirs'] = $dirs;

    return $dir;
}

it('writes the always-present scaffolding files', function () {
    $dir = benchTempDir();

    (new AppGenerator)->generate(BenchProfile::tiny(), $dir);

    $files = new Filesystem;
    expect($files->exists($dir.'/app/Console/Kernel.php'))->toBeTrue();
    expect($files->exists($dir.'/app/Providers/EventServiceProvider.php'))->toBeTrue();
    expect($files->exists($dir.'/bootstrap/app.php'))->toBeTrue();
    expect($files->exists($dir.'/routes/web.php'))->toBeTrue();
    expect($files->exists($dir.'/routes/api.php'))->toBeTrue();
});

it('writes at least one class for each generated category', function () {
    $dir = benchTempDir();

    (new AppGenerator)->generate(BenchProfile::tiny(), $dir);

    $files = new Filesystem;
    expect($files->exists($dir.'/app/Events/Event0.php'))->toBeTrue();
    expect($files->exists($dir.'/app/Listeners/Listener0.php'))->toBeTrue();
    expect($files->exists($dir.'/app/Observers/Observer0.php'))->toBeTrue();
    expect($files->exists($dir.'/app/Models/Model0.php'))->toBeTrue();
    expect($files->exists($dir.'/app/Jobs/Job0.php'))->toBeTrue();
    expect($files->exists($dir.'/app/Mail/Mailable0.php'))->toBeTrue();
    expect($files->exists($dir.'/app/Notifications/Notification0.php'))->toBeTrue();
    expect($files->exists($dir.'/app/Services/Service0.php'))->toBeTrue();
    expect($files->exists($dir.'/app/Http/Controllers/Controller0.php'))->toBeTrue();
});

it('writes exactly totalFiles() php files', function () {
    $dir = benchTempDir();
    $profile = BenchProfile::tiny();

    (new AppGenerator)->generate($profile, $dir);

    expect(benchPhpFiles($dir))->toHaveCount($profile->totalFiles());
});

it('generates deterministically — identical paths and contents across runs', function () {
    $a = benchTempDir();
    $b = benchTempDir();
    $profile = BenchProfile::tiny();

    (new AppGenerator)->generate($profile, $a);
    (new AppGenerator)->generate($profile, $b);

    $pathsA = benchPhpFiles($a);
    $pathsB = benchPhpFiles($b);

    expect($pathsB)->toBe($pathsA);

    $files = new Filesystem;
    foreach ($pathsA as $relative) {
        expect($files->get($b.'/'.$relative))->toBe($files->get($a.'/'.$relative));
    }
});

it('clears a stale target directory before generating', function () {
    $dir = benchTempDir();
    $files = new Filesystem;
    $files->ensureDirectoryExists($dir.'/app');
    $files->put($dir.'/app/Stale.php', '<?php // stale');

    (new AppGenerator)->generate(BenchProfile::tiny(), $dir);

    expect($files->exists($dir.'/app/Stale.php'))->toBeFalse();
});
