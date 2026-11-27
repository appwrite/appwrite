<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\Applications;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Emails\Validator\Email;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;

class Create extends Base
{
    private const string LOCK_TARGET = 'mock';
    private const int LOCK_TTL = 10;
    private const float LOCK_WAIT = 10.0;

    public static function getName(): string
    {
        return 'createDistributionGoogleSeedApplication';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::SEEDS . '/applications')
            ->desc('Seed Google Play application')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('packageName', '', new Text(0), 'Application package name.')
            ->param('emails', [], new ArrayList(new Email()), 'Service account emails granted access to the application, added to any already granted.')
            ->param('tracks', [], new JSON(), 'Tracks in the edits.tracks.list shape, replacing the current tracks. When empty, the current tracks are kept, or production, beta, alpha and internal with no releases for a new application.', true)
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $emails
     * @param array<mixed>|string $tracks
     */
    public function action(string $packageName, array $emails, array|string $tracks, Response $response, Cache $cache, Lock $lock): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        if (\is_string($tracks)) {
            $tracks = \json_decode($tracks, true);
        }

        if (!\is_array($tracks) || !\array_is_list($tracks)) {
            throw new Exception(Exception::GENERAL_MOCK, 'Tracks must be a list');
        }

        $tracks = \array_map($this->track(...), $tracks);
        $key = $this->key(self::RESOURCE_APPLICATION, $packageName);

        $application = $lock->withKey(
            'lock:' . $key,
            fn (): array => $this->merge($cache, $key, $packageName, $emails, $tracks),
            target: self::LOCK_TARGET,
            ttl: self::LOCK_TTL,
            waitTimeout: self::LOCK_WAIT,
        );

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json($application);
    }

    /**
     * @param array<string> $emails
     * @param list<array{track: string, releases: list<mixed>}> $tracks
     * @return array{packageName: string, emails: list<string>, tracks: list<array<string, mixed>>}
     */
    private function merge(Cache $cache, string $key, string $packageName, array $emails, array $tracks): array
    {
        $existing = $cache->load($key, self::TTL);
        $existing = \is_array($existing) ? $existing : [];

        if ($tracks === []) {
            $tracks = $existing['tracks'] ?? \array_map(fn (string $track): array => ['track' => $track, 'releases' => []], self::TRACKS);
        }

        $application = [
            'packageName' => $packageName,
            'emails' => \array_values(\array_unique([...($existing['emails'] ?? []), ...$emails])),
            'tracks' => $tracks,
        ];

        $cache->save($key, $application, '', self::TTL);

        return $application;
    }

    /**
     * @return array{track: string, releases: list<mixed>}
     */
    private function track(mixed $track): array
    {
        $releases = \is_array($track) ? ($track['releases'] ?? []) : null;

        if (!\is_array($track) || !\is_string($track['track'] ?? null) || !\is_array($releases) || !\array_is_list($releases)) {
            throw new Exception(Exception::GENERAL_MOCK, 'Each track needs a track name and a list of releases');
        }

        return [
            'track' => $track['track'],
            'releases' => $releases,
        ];
    }
}
