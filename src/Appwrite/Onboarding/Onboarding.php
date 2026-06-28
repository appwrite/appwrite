<?php

namespace Appwrite\Onboarding;

use Throwable;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;

class Onboarding
{
    /**
     * Mark one or more onboarding stages (keyed by SDK method, e.g.
     * `functions.createDeployment`) completed under project.onboarding.
     *
     * Methods absent from the onboarding config, the console project, and
     * stages already completed/skipped are ignored. All provided methods are
     * persisted in a single write so they cannot clobber one another.
     *
     * Request-context callers should wrap this in Authorization::skip(); worker
     * contexts already run unscoped.
     *
     * @param  array<int, string>  $methods
     */
    public static function complete(Database $dbForPlatform, Document $project, array $methods, string $actorType): void
    {
        if ($project->getId() === 'console' || $methods === []) {
            return;
        }

        $onboarding = Config::getParam('onboarding', []);

        $byMethod = $project->getAttribute('onboarding', []);
        if (! \is_array($byMethod)) {
            $byMethod = [];
        }

        $changed = false;
        foreach ($methods as $method) {
            if (! isset($onboarding[$method])) {
                continue;
            }

            $status = $byMethod[$method]['status'] ?? null;
            if ($status === ONBOARDING_STATUS_COMPLETED || $status === ONBOARDING_STATUS_SKIPPED) {
                continue;
            }

            $byMethod[$method] = [
                'status' => ONBOARDING_STATUS_COMPLETED,
                'at' => DateTime::now(),
                'actorType' => $actorType,
            ];
            $changed = true;
        }

        if (! $changed) {
            return;
        }

        try {
            $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
                'onboarding' => $byMethod,
            ]));
        } catch (Throwable) {
            // Missing `onboarding` attribute on upgraded installs must not break the caller.
        }
    }
}
