<?php

namespace Appwrite\Migration\Infrastructure\Version;

use Appwrite\Migration\Infrastructure\Migration;
use Utopia\Console;
use Utopia\System\System;

/**
 * Infrastructure changes introduced by 2.0.
 */
class V1 extends Migration
{
    public function getName(): string
    {
        return '2.0.0';
    }

    protected function changes(): array
    {
        return [
            'Carry build artifacts onto the renamed builds volume' => $this->carryBuildArtifacts(...),
        ];
    }

    /**
     * Copies build artifacts onto the builds volume, whose name changed in 2.0.
     *
     * Before 2.0 the volume was declared without a name, so Compose prefixed it with the
     * project and it became <project>_appwrite-builds. 2.0 names it explicitly, because
     * jobs-service build containers are created outside the Compose project and mount it
     * by that literal name through _APP_BUILDS_VOLUME.
     *
     * Starting 2.0 on an older installation therefore mounts a new, empty volume and
     * leaves every existing artifact behind. Deployments stay in the database looking
     * healthy, pointing at build paths that no longer resolve, so the executor cannot
     * unpack a source that is not there, never starts a runtime, and the request fails on
     * the resource timeout. The only log line names the runtime rather than the missing
     * file.
     */
    private function carryBuildArtifacts(): void
    {
        $target = (string) ($this->env['_APP_BUILDS_VOLUME'] ?? '') ?: System::getEnv('_APP_BUILDS_VOLUME', 'appwrite-builds');
        $image = (string) ($this->env['_APP_IMAGE'] ?? 'appwrite/appwrite') . ':' . (string) ($this->env['_APP_VERSION'] ?? 'latest');

        // Counted with the Appwrite image, which the upgrade has already pulled, so reading
        // a volume never depends on fetching another one.
        $files = static function (string $volume) use ($image): int {
            $output = [];
            \exec(
                'docker run --rm -v ' . \escapeshellarg($volume . ':/v:ro') . ' ' . \escapeshellarg($image)
                . ' sh -c ' . \escapeshellarg('find /v -type f 2>/dev/null | wc -l') . ' 2>/dev/null',
                $output
            );

            return (int) \trim((string) ($output[0] ?? '0'));
        };

        $output = [];
        \exec('docker volume ls --format ' . \escapeshellarg('{{.Name}}') . ' 2>/dev/null', $output);
        $volumes = \array_filter(\array_map('trim', $output));

        // The new volume usually does not exist yet: Compose creates it when the containers
        // start, which is after this runs, and the copy below creates it earlier so the
        // artifacts are in place before anything reads them. Reading a volume that does not
        // exist would create an empty one, so the listing gates that.
        $targetFiles = \in_array($target, $volumes, true) ? $files($target) : 0;

        $legacy = [];
        foreach ($volumes as $volume) {
            if ($volume !== $target && \str_ends_with($volume, '_' . $target) && $files($volume) > 0) {
                $legacy[] = $volume;
            }
        }

        if ($legacy === []) {
            return;
        }

        // Something has already written to the new volume, so a copy could overwrite newer
        // artifacts with older ones. Say so rather than skipping in silence: an installation
        // that upgraded once and rebuilt a single deployment still has the rest stranded.
        if ($targetFiles > 0) {
            Console::warning(
                '"' . $target . '" already holds build files, so nothing was copied from '
                . \implode(', ', $legacy) . '. Deployments built before the upgrade may still be'
                . ' missing their artifacts; copy them across manually if any fail to run.'
            );
            return;
        }

        if (\count($legacy) > 1) {
            Console::warning(
                'Found more than one previous build volume (' . \implode(', ', $legacy) . '), so none was copied.'
                . ' Copy the correct one onto "' . $target . '" before using existing deployments.'
            );
            return;
        }

        $source = $legacy[0];
        Console::info('Copying build artifacts from "' . $source . '" to "' . $target . '"...');

        $output = [];
        $exit = 0;
        \exec(\sprintf(
            'docker run --rm -v %s:/from:ro -v %s:/to %s sh -c %s 2>&1',
            \escapeshellarg($source),
            \escapeshellarg($target),
            \escapeshellarg($image),
            \escapeshellarg('cp -a /from/. /to/')
        ), $output, $exit);

        if ($exit !== 0) {
            Console::warning(
                'Failed to copy build artifacts from "' . $source . '": ' . \implode(' ', $output)
                . '. Existing deployments will need rebuilding.'
            );
            return;
        }

        Console::success('Copied ' . $files($target) . ' build file(s). "' . $source . '" was left in place.');
    }
}
