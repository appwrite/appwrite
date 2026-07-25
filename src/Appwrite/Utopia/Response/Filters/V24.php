<?php

namespace Appwrite\Utopia\Response\Filters;

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;
use Utopia\System\System;

// Convert 1.9.3 Data format to 1.9.2 format
class V24 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            Response::MODEL_EPHEMERAL_KEY => $this->parseEphemeralKey($content),
            default => $content,
        };
    }

    private function parseEphemeralKey(array $content): array
    {
        unset($content['$id']);
        unset($content['$createdAt']);
        unset($content['$updatedAt']);
        unset($content['name']);
        unset($content['expire']);
        unset($content['sdks']);
        unset($content['accessedAt']);

        $secret = $content['secret'] ?? '';
        unset($content['secret']);

        $content['projectId'] = $this->extractProjectId($secret);
        $content['jwt'] = $secret;

        return $content;
    }

    private function extractProjectId(string $secret): string
    {
        $token = explode('_', $secret, 2)[1] ?? '';
        if ($token === '') {
            return '';
        }

        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256');

        try {
            return $jwt->decode($token, false)['projectId'] ?? '';
        } catch (JWTException) {
            return '';
        }
    }
}
