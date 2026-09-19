<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Http;

use Illuminate\Http\Response;
use Lucasp\Loom\Ui\Asset;

/**
 * Serves the bundled static files, and only those named by {@see Asset}.
 */
final class AssetController
{
    public function __invoke(string $file): Response
    {
        $asset = Asset::tryFrom($file) ?? abort(404);
        $body = @file_get_contents($asset->path());

        if ($body === false) {
            abort(404);
        }

        return new Response($body, 200, [
            'Content-Type' => $asset->contentType(),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
