<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Edits\Bundles;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Text;

class Update extends Base
{
    private const int RESUME_INCOMPLETE = 308;
    private const string UNIT = 'bytes ';
    private const string STATUS = '*';

    public static function getName(): string
    {
        return 'updateDistributionGoogleBundleUpload';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath(self::RESUMABLE . '/:packageName/edits/:editId/bundles')
            ->desc('Update Google Play resumable bundle upload')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('packageName', '', new Text(0), 'Application package name.')
            ->param('editId', '', new Text(0), 'Edit ID.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $packageName, string $editId, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->key(self::RESOURCE_SESSION, (string) $request->getQuery('upload_id', ''));
        $session = $cache->load($key, self::TTL);

        if (!\is_array($session) || $session['packageName'] !== $packageName || $session['editId'] !== $editId) {
            $this->notFound($response, 'notFound', 'Upload session not found.');
            return;
        }

        if (isset($session['bundle'])) {
            $response->json($session['bundle']);
            return;
        }

        $body = $request->getRawPayload();
        $range = $this->range($request->getHeaderLine('content-range'), \strlen($body), (int) $session['length']);

        if ($range === null) {
            $this->badRequest($response, 'Invalid Content-Range header.');
            return;
        }

        if ($range !== []) {
            if ($range['start'] !== $session['received'] || \strlen($body) !== $range['end'] - $range['start'] + 1) {
                $this->badRequest($response, 'Content-Range must continue the upload at byte ' . $session['received'] . '.');
                return;
            }

            $cache->save($this->key(self::RESOURCE_PART, $session['id'] . ':' . $session['parts']), \base64_encode($body), '', self::TTL);
            $session['parts']++;
            $session['received'] += \strlen($body);
            $cache->save($key, $session, '', self::TTL);
        }

        if ($session['received'] < $session['length']) {
            $response->setStatusCode(self::RESUME_INCOMPLETE);

            if ($session['received'] > 0) {
                $response->addHeader('Range', 'bytes=0-' . ($session['received'] - 1));
            }

            $response->send();
            return;
        }

        $edit = $this->open($cache, $packageName, $session['email'], $editId);

        if ($edit === null) {
            $this->notFound($response, 'editNotFound', 'This Edit has been deleted.');
            return;
        }

        $bundle = $this->bundle($cache, $session);
        $application = $cache->load($this->key(self::RESOURCE_APPLICATION, $packageName), self::TTL);
        $application = \is_array($application) ? $application : [];

        $used = \array_values(\array_unique([
            ...$this->bundleCodes($application['bundles'] ?? []),
            ...$this->versionCodes($application['tracks'] ?? []),
            ...$this->bundleCodes($edit['bundles'] ?? []),
            ...$this->versionCodes($edit['tracks'] ?? []),
        ]));

        $seeded = $cache->load($this->key(self::RESOURCE_BUNDLE, $packageName . ':' . $bundle['sha256']), self::TTL);
        $versionCode = \is_string($seeded) ? $seeded : (string) (\max([0, ...\array_map(\intval(...), $used)]) + 1);

        if (\in_array($versionCode, $used, true)) {
            $this->versionConflict($response);
            return;
        }

        $bundle = ['versionCode' => (int) $versionCode, ...$bundle];

        $edit['bundles'] = [...($edit['bundles'] ?? []), $bundle];
        $this->saveEdit($cache, $edit);

        $session['bundle'] = $bundle;
        $cache->save($key, $session, '', self::TTL);

        for ($part = 0; $part < $session['parts']; $part++) {
            $cache->purge($this->key(self::RESOURCE_PART, $session['id'] . ':' . $part));
        }

        $response->json($bundle);
    }

    /**
     * @return array{start: int, end: int}|array{}|null
     */
    private function range(string $header, int $size, int $length): ?array
    {
        if ($header === '') {
            return $size === 0 ? [] : ['start' => 0, 'end' => $size - 1];
        }

        if (!\str_starts_with($header, self::UNIT)) {
            return null;
        }

        $parts = \explode('/', \substr($header, \strlen(self::UNIT)));

        if (\count($parts) !== 2 || !\ctype_digit($parts[1]) || (int) $parts[1] !== $length) {
            return null;
        }

        if ($parts[0] === self::STATUS) {
            return [];
        }

        $bounds = \explode('-', $parts[0]);

        if (\count($bounds) !== 2 || !\ctype_digit($bounds[0]) || !\ctype_digit($bounds[1])) {
            return null;
        }

        $start = (int) $bounds[0];
        $end = (int) $bounds[1];

        if ($start > $end || $end >= $length) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @param array<string, mixed> $session
     * @return array{sha1: string, sha256: string}
     */
    private function bundle(Cache $cache, array $session): array
    {
        $sha1 = \hash_init('sha1');
        $sha256 = \hash_init('sha256');

        for ($part = 0; $part < $session['parts']; $part++) {
            $data = \base64_decode((string) $cache->load($this->key(self::RESOURCE_PART, $session['id'] . ':' . $part), self::TTL));
            \hash_update($sha1, $data);
            \hash_update($sha256, $data);
        }

        return [
            'sha1' => \hash_final($sha1),
            'sha256' => \hash_final($sha256),
        ];
    }
}
