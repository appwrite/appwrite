<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Edits\Bundles;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Base;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\Uploads\XList as Uploads;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Text;

class Create extends Base
{
    public static function getName(): string
    {
        return 'createDistributionGoogleBundleUpload';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::RESUMABLE . '/:packageName/edits/:editId/bundles')
            ->desc('Create Google Play resumable bundle upload')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('packageName', '', new Text(0), 'Application package name.')
            ->param('editId', '', new Text(0), 'Edit ID.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    public function action(string $packageName, string $editId, Request $request, Response $response, Cache $cache, Lock $lock): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $edit = $this->edit($request, $response, $cache, $packageName, $editId);

        if ($edit === null) {
            return;
        }

        if ($request->getQuery('uploadType', '') !== 'resumable') {
            $this->badRequest($response, 'Upload type must be resumable.');
            return;
        }

        $length = $request->getHeaderLine('x-upload-content-length');

        if (!\ctype_digit($length) || (int) $length < 1) {
            $this->badRequest($response, 'X-Upload-Content-Length must be a positive number of bytes.');
            return;
        }

        $session = [
            'id' => \bin2hex(\random_bytes(16)),
            'packageName' => $packageName,
            'editId' => $editId,
            'email' => (string) $edit['email'],
            'length' => (int) $length,
            'received' => 0,
            'parts' => 0,
        ];

        $cache->save($this->key(self::RESOURCE_SESSION, $session['id']), $session, '', self::TTL);
        $this->count($lock, $cache, $packageName, Uploads::SESSIONS, 1);

        $location = $this->origin($request)
            . self::RESUMABLE . '/' . \rawurlencode($packageName) . '/edits/' . \rawurlencode($editId) . '/bundles'
            . '?' . \http_build_query(['uploadType' => 'resumable', 'upload_id' => $session['id']]);

        $response
            ->addHeader('Location', $location)
            ->send();
    }
}
