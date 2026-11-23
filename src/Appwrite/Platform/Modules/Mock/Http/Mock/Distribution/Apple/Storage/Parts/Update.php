<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Storage\Parts;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Text;

class Update extends Base
{
    public static function getName(): string
    {
        return 'updateDistributionAppleStoragePart';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath(self::PREFIX . '/storage/:reservationId/parts/:partNumber')
            ->desc('Update App Store Connect storage part')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('reservationId', '', new Text(64), 'Reservation ID from the upload operation URL.')
            ->param('partNumber', '', new Text(16), 'Part number from the upload operation URL.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $reservationId, string $partNumber, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $reservation = $this->reservation($cache, $reservationId);

        if ($reservation === null || $reservation['sealed'] || $reservation['expiration'] < \time()) {
            $response->setStatusCode(Response::STATUS_CODE_FORBIDDEN)->text('Request has expired');
            return;
        }

        $operation = null;

        foreach ($reservation['operations'] as $candidate) {
            if ((string) $candidate['partNumber'] === $partNumber) {
                $operation = $candidate;
            }
        }

        $body = $request->getRawPayload();

        if ($operation === null || \strlen($body) !== $operation['length']) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST)->text('The part does not match the upload operation');
            return;
        }

        $cache->save(self::CACHE_UPLOAD_PARTS . $reservationId . ':' . $operation['partNumber'], \base64_encode($body), '', self::TTL);

        $response->setStatusCode(Response::STATUS_CODE_OK)->send();
    }
}
