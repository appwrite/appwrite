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
     * Migrates the old builds volume to the one the orchestrator is pinned to:
     * <project>_appwrite-builds to appwrite-builds.
     *
     * Compose prefixed the volume with the project until 2.0, which names it explicitly so
     * jobs-service build containers, created outside the Compose project, can mount it by a
     * fixed name. Without this the upgrade mounts a new, empty volume and every existing
     * artifact is left behind.
     */
    private function carryBuildArtifacts(): void
    {
        $target = (string) ($this->env['_APP_BUILDS_VOLUME'] ?? '') ?: System::getEnv('_APP_BUILDS_VOLUME', 'appwrite-builds');
        $image = (string) ($this->env['_APP_IMAGE'] ?? 'appwrite/appwrite') . ':' . (string) ($this->env['_APP_VERSION'] ?? 'latest');

        // Counted with the Appwrite image, which the upgrade has already pulled, so reading
        // a volume never depends on fetching another one. Null when the volume could not be
        // read at all, which must not be mistaken for an empty one: that would read as
        // nothing to migrate and strand the artifacts in silence.
        $files = static function (string $volume) use ($image): ?int {
            $stdout = '';
            $stderr = '';
            $exit = Console::execute(
                'docker run --rm -v ' . \escapeshellarg($volume . ':/v:ro') . ' ' . \escapeshellarg($image)
                . ' sh -c ' . \escapeshellarg('find /v -type f | wc -l'),
                '',
                $stdout,
                $stderr
            );

            return $exit === 0 ? (int) \trim($stdout) : null;
        };

        $stdout = '';
        $stderr = '';
        $exit = Console::execute('docker volume ls --format ' . \escapeshellarg('{{.Name}}'), '', $stdout, $stderr);

        if ($exit !== 0) {
            throw new \RuntimeException('could not list Docker volumes: ' . \trim($stderr ?: $stdout));
        }

        $volumes = \array_filter(\array_map('trim', \explode("\n", $stdout)));

        $legacy = [];
        $unreadable = false;
        foreach ($volumes as $volume) {
            if ($volume === $target || !\str_ends_with($volume, '_' . $target)) {
                continue;
            }

            $count = $files($volume);

            // Told apart from an empty volume, and raised rather than warned about: the copy
            // can still be made once whatever stopped the read is fixed.
            if ($count === null) {
                $unreadable = true;
                break;
            }

            if ($count > 0) {
                $legacy[] = $volume;
            }
        }

        if ($unreadable) {
            throw new \RuntimeException('could not read the contents of the build volumes');
        }

        if ($legacy === []) {
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

        // Only what is not already there in full is copied, so a copy that died half way is
        // carried on rather than started again. Matching on size rather than existence is
        // what makes that safe: a copy killed mid-file leaves a short one behind, which has
        // to be recognised as unfinished and written again. What is still not intact
        // afterwards is listed, because a copy can stop early -- a full disk, a killed
        // container -- while everything it did run reports success.
        $stdout = '';
        $stderr = '';
        $exit = Console::execute(
            'docker run --rm -v ' . \escapeshellarg($source . ':/from:ro') . ' -v ' . \escapeshellarg($target . ':/to')
            . ' ' . \escapeshellarg($image) . ' sh -c ' . \escapeshellarg(
                'cd /from && find . -type f | while read -r file; do'
                . ' size=$(stat -c %s "$file");'
                . ' [ "$(stat -c %s "/to/$file" 2>/dev/null)" = "$size" ] ||'
                . ' { mkdir -p "/to/$(dirname "$file")" && cp -a "$file" "/to/$file"; };'
                . ' [ "$(stat -c %s "/to/$file" 2>/dev/null)" = "$size" ] || echo "$file"; done'
            ),
            '',
            $stdout,
            $stderr
        );

        $missing = \array_filter(\array_map('trim', \explode("\n", $stdout)));

        // A container that never ran lists nothing missing, which would otherwise read the
        // same as a copy that left nothing behind.
        if ($exit !== 0) {
            throw new \RuntimeException(
                'could not copy build artifacts from "' . $source . '": ' . (\trim($stderr) ?: 'docker exited with ' . $exit)
            );
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                \count($missing) . ' build file(s) could not be copied from "' . $source . '"'
                . ($stderr === '' ? '' : ': ' . \trim($stderr))
            );
        }

        Console::success('Copied ' . $files($target) . ' build file(s). "' . $source . '" was left in place.');
    }
}
