<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Edits\Tracks;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;

class Update extends Base
{
    private const array FRACTIONAL = [self::STATUS_IN_PROGRESS, self::STATUS_HALTED];

    public static function getName(): string
    {
        return 'updateDistributionGoogleTrack';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath(self::APPLICATIONS . '/:packageName/edits/:editId/tracks/:track')
            ->desc('Update Google Play edit track')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('packageName', '', new Text(0), 'Application package name.')
            ->param('editId', '', new Text(0), 'Edit ID.')
            ->param('track', '', new Text(0), 'Track name.')
            ->param('releases', [], new JSON(), 'Releases replacing the track\'s releases, in the TrackRelease shape.', true)
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    /**
     * @param array<mixed>|string $releases
     */
    public function action(string $packageName, string $editId, string $track, array|string $releases, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $edit = $this->edit($request, $response, $cache, $packageName, $editId);

        if ($edit === null) {
            return;
        }

        $index = $this->locate($edit['tracks'] ?? [], $track);

        if ($index === null) {
            $this->notFound($response, 'trackNotFound', 'Track not found: ' . $track . '.');
            return;
        }

        if (\is_string($releases)) {
            $releases = \json_decode($releases, true);
        }

        if (!\is_array($releases) || !\array_is_list($releases)) {
            $this->badRequest($response, 'Releases must be a list.');
            return;
        }

        $draft = $this->review($cache, $packageName)['draft'];
        $known = [
            ...$this->bundleCodes($edit['bundles'] ?? []),
            ...$this->versionCodes($edit['tracks']),
        ];

        foreach ($releases as $release) {
            $error = $this->validate($release, $known, $draft);

            if ($error !== null) {
                $this->badRequest($response, $error);
                return;
            }
        }

        $releases = \array_map($this->normalise(...), $releases);

        $edit['tracks'][$index]['releases'] = $releases;
        $this->saveEdit($cache, $edit);

        $response->json([
            'track' => $track,
            'releases' => $releases,
        ]);
    }

    /**
     * @param list<string> $known
     */
    private function validate(mixed $release, array $known, bool $draft): ?string
    {
        if (!\is_array($release) || ($release !== [] && \array_is_list($release))) {
            return 'Each release must be an object.';
        }

        $status = $release['status'] ?? null;

        if (!\in_array($status, self::STATUSES, true)) {
            return 'Invalid release status: ' . \var_export($status, true) . '.';
        }

        if ($draft && $status !== self::STATUS_DRAFT) {
            return self::DRAFT_APP;
        }

        $codes = $release['versionCodes'] ?? null;

        if (!\is_array($codes) || $codes === [] || !\array_is_list($codes)) {
            return 'Each release needs a list of version codes.';
        }

        foreach ($codes as $code) {
            if (!\is_int($code) && !(\is_string($code) && \ctype_digit($code))) {
                return 'Version codes must be integers.';
            }

            if (!\in_array((string) $code, $known, true)) {
                return 'Version code ' . $code . ' has not been uploaded to this edit.';
            }
        }

        $fraction = $release['userFraction'] ?? null;

        if ($fraction !== null) {
            if (!\in_array($status, self::FRACTIONAL, true)) {
                return 'userFraction can only be set when status is inProgress or halted.';
            }

            if ((!\is_int($fraction) && !\is_float($fraction)) || $fraction <= 0 || $fraction >= 1) {
                return 'userFraction must be greater than 0 and less than 1.';
            }
        }

        if ($status === self::STATUS_IN_PROGRESS && $fraction === null) {
            return 'userFraction must be set when status is inProgress.';
        }

        $notes = $release['releaseNotes'] ?? [];

        if (!\is_array($notes)) {
            return 'Release notes must be a list.';
        }

        foreach ($notes as $note) {
            if (!\is_array($note) || !\is_string($note['language'] ?? null) || !\is_string($note['text'] ?? null)) {
                return 'Each release note needs a language and a text.';
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $release
     * @return array<string, mixed>
     */
    private function normalise(array $release): array
    {
        $normalised = [];

        if (\is_string($release['name'] ?? null)) {
            $normalised['name'] = $release['name'];
        }

        $normalised['versionCodes'] = \array_map(\strval(...), $release['versionCodes']);
        $normalised['status'] = $release['status'];

        if (isset($release['userFraction'])) {
            $normalised['userFraction'] = (float) $release['userFraction'];
        }

        if (isset($release['releaseNotes'])) {
            $normalised['releaseNotes'] = $release['releaseNotes'];
        }

        return $normalised;
    }
}
