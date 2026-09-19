<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Http;

use Lucasp\Loom\Ui\Asset;
use Lucasp\Loom\Ui\LoomConfig;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the bundled static files, and only those named by {@see Asset}.
 */
final class AssetController
{
    public function __invoke(string $file): BinaryFileResponse
    {
        if (! app(LoomConfig::class)->servesIn(app()->environment())) {
            abort(404);
        }

        $asset = Asset::tryFrom($file) ?? abort(404);

        if (! is_file($asset->path())) {
            abort(404);
        }

        $response = new BinaryFileResponse($asset->path(), 200, ['Content-Type' => $asset->contentType()]);
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->headers->addCacheControlDirective('immutable');

        return $response;
    }
}
