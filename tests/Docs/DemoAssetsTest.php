<?php

declare(strict_types=1);

/**
 * The README demo GIF is recorded by `just demo-gif`, never in CI; this keeps
 * a re-recording from bloating every clone.
 */
it('keeps the demo GIF under its size budget', function (): void {
    $gif = dirname(__DIR__, 2).'/docs/assets/demo.gif';

    expect(is_file($gif))->toBeTrue()
        ->and(filesize($gif))->toBeLessThanOrEqual(1_500_000);
});
