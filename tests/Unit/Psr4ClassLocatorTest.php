<?php

declare(strict_types=1);

use Lucasp\Loom\Support\Psr4ClassLocator;

function psr4FixtureRoot(): string
{
    $root = sys_get_temp_dir().'/loom-psr4-locator-'.bin2hex(random_bytes(4));
    mkdir($root.'/app/Jobs', 0777, true);
    mkdir($root.'/app/Domain/Billing/Jobs', 0777, true);
    mkdir($root.'/custom/Thing', 0777, true);

    file_put_contents($root.'/app/Jobs/ProcessOrder.php', "<?php\n");
    file_put_contents($root.'/app/Domain/Billing/Jobs/ChargeCustomer.php', "<?php\n");
    file_put_contents($root.'/custom/Thing/Item.php', "<?php\n");

    return $root;
}

/**
 * Normalises native filesystem separators to forward slashes so test
 * assertions are portable between POSIX (`/`) and Windows (`\`) runners.
 */
function psr4Normalize(string $path): string
{
    return str_replace(DIRECTORY_SEPARATOR, '/', $path);
}

it('resolves an App-prefixed FQCN under app/', function () {
    $root = psr4FixtureRoot();
    $locator = new Psr4ClassLocator;

    $result = $locator->locate($root, 'App\\Jobs\\ProcessOrder');

    expect(psr4Normalize($result))->toBe(psr4Normalize($root).'/app/Jobs/ProcessOrder.php');
});

it('resolves deeply nested App-prefixed FQCN', function () {
    $root = psr4FixtureRoot();
    $locator = new Psr4ClassLocator;

    $result = $locator->locate($root, 'App\\Domain\\Billing\\Jobs\\ChargeCustomer');

    expect(psr4Normalize($result))->toBe(psr4Normalize($root).'/app/Domain/Billing/Jobs/ChargeCustomer.php');
});

it('returns null when the guessed file does not exist', function () {
    $root = psr4FixtureRoot();
    $locator = new Psr4ClassLocator;

    expect($locator->locate($root, 'App\\Jobs\\DoesNotExist'))->toBeNull();
});

it('returns null for an empty FQCN', function () {
    $locator = new Psr4ClassLocator;

    expect($locator->locate(sys_get_temp_dir(), ''))->toBeNull();
});

it('trims a leading backslash before resolving', function () {
    $root = psr4FixtureRoot();
    $locator = new Psr4ClassLocator;

    $result = $locator->locate($root, '\\App\\Jobs\\ProcessOrder');

    expect(psr4Normalize($result))->toBe(psr4Normalize($root).'/app/Jobs/ProcessOrder.php');
});

it('lowercases non-App root namespace segments for the path guess', function () {
    $root = psr4FixtureRoot();
    $locator = new Psr4ClassLocator;

    $result = $locator->locate($root, 'Custom\\Thing\\Item');

    expect(psr4Normalize($result))->toBe(psr4Normalize($root).'/custom/Thing/Item.php');
});
