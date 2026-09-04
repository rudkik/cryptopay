<?php

namespace App\Support;

/**
 * Reader for config/features.php — the optional modules of this deployment.
 *
 * Single source for the three places a flag surfaces: the `feature:<flag>`
 * route middleware, the public bootstrap config and the admin auth payloads.
 */
final class FeatureFlags
{
    /** Unknown flags read as disabled: an unrecognised gate must fail closed. */
    public static function enabled(string $flag): bool
    {
        return (bool) config("features.{$flag}", false);
    }

    /** @return array<string, bool> */
    public static function all(): array
    {
        return array_map(
            static fn ($enabled): bool => (bool) $enabled,
            (array) config('features', []),
        );
    }
}
