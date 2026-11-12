<?php

namespace Appwrite\Deployment;

use Utopia\Storage\Device;

final class Output
{
    /**
     * Completion can overtake the output callback. Check storage for a bounded
     * grace period instead of depending on the callback's cache marker.
     * Storage errors propagate: an unavailable store is not a missing build.
     */
    public static function wait(Device $device, string $path, float $timeout = 10.0): bool
    {
        if ($timeout < 0 || !\is_finite($timeout)) {
            throw new \InvalidArgumentException('Output timeout must be finite and non-negative');
        }

        $deadline = \hrtime(true) + $timeout * 1_000_000_000;

        do {
            if (Deployments::isArtifact($device, $path)) {
                return true;
            }

            $remaining = $deadline - \hrtime(true);
            if ($remaining <= 0) {
                return false;
            }

            // The worker enables SWOOLE_HOOK_ALL, so this yields its coroutine.
            \usleep((int) \min(250_000, $remaining / 1_000));
        } while (true);
    }
}
