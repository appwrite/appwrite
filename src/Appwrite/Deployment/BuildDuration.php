<?php

namespace Appwrite\Deployment;

use Utopia\Database\Document;

/**
 * How long a build ran, as recorded on its deployment when the job exits.
 * Usage bills this as build compute (memory × duration × cpus).
 */
final class BuildDuration
{
    /**
     * The worker's measured duration is trusted as-is: termination grace can
     * legitimately run past the timeout. Without one, the duration falls back
     * to wall clock since the build started, which is not build time: an exit
     * reported late (one build billed 50 days when its exit arrived seven weeks
     * after it started) would be billed in full. So the fallback is bounded by
     * the build timeout plus the same 300s of headroom Deployments grants the
     * build's credentials.
     *
     * Callbacks arrive out of order, so buildStartedAt (stamped by the first
     * log callback) can be missing when a terminal callback finalizes first;
     * the fallback then starts from the deployment's creation time.
     *
     * @param ?float $measured seconds the worker measured, if its exit carried any
     * @param int $timeout the build timeout for this project's plan, in seconds
     * @param float $now the current Unix time, in seconds
     */
    public function of(Document $deployment, ?float $measured, int $timeout, float $now): int
    {
        if ($measured !== null && \is_finite($measured) && $measured >= 0) {
            return (int) \ceil($measured);
        }

        if (!empty($deployment->getAttribute('buildEndedAt')) && $deployment->getAttribute('buildDuration') !== null) {
            return (int) $deployment->getAttribute('buildDuration', 0);
        }

        $startedAt = $deployment->getAttribute('buildStartedAt', '') ?: $deployment->getCreatedAt();
        if (empty($startedAt)) {
            return 0;
        }

        try {
            $started = (float) (new \DateTimeImmutable($startedAt))->format('U.u');
            $ended = empty($deployment->getAttribute('buildEndedAt'))
                ? $now
                : (float) (new \DateTimeImmutable($deployment->getAttribute('buildEndedAt')))->format('U.u');
        } catch (\Exception) {
            return 0;
        }

        $elapsed = (int) \ceil(\max(0.0, $ended - $started));

        return $timeout > 0 ? \min($elapsed, $timeout + 300) : $elapsed;
    }
}
