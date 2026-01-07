<?php

namespace Appwrite\Platform\Modules\Avatars\Http;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as PlatformAction;
use Appwrite\Utopia\Response;
use Throwable;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Image\Image;
use Utopia\Logger\Logger;

class Action extends PlatformAction
{
    protected function avatarCallback(string $type, string $code, int $width, int $height, int $quality, Response $response): void
    {
        $code = \strtolower($code);
        $type = \strtolower($type);
        $set = Config::getParam('avatar-' . $type, []);

        if (empty($set)) {
            throw new Exception(Exception::AVATAR_SET_NOT_FOUND);
        }

        if (!\array_key_exists($code, $set)) {
            throw new Exception(Exception::AVATAR_NOT_FOUND);
        }

        if (!\extension_loaded('imagick')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Imagick extension is missing');
        }

        $output = 'png';
        $path = $set[$code]['path'];
        $type = 'png';

        if (!\is_readable($path)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'File not readable in ' . $path);
        }

        $image = new Image(\file_get_contents($path));
        $image->crop((int) $width, (int) $height);
        $output = (empty($output)) ? $type : $output;
        $data = $image->output($output, $quality);
        $response
            ->addHeader('Cache-Control', 'private, max-age=2592000') // 30 days
            ->setContentType('image/png')
            ->file($data);
        unset($image);
    }

    protected function getUserGitHub(string $userId, Document $project, Database $dbForProject, Database $dbForPlatform, ?Logger $logger): array
    {
        try {
            $user = Authorization::skip(fn () => $dbForPlatform->getDocument('users', $userId));

            $sessions = $user->getAttribute('sessions', []);

            $gitHubSession = null;
            foreach ($sessions as $session) {
                if ($session->getAttribute('provider', '') === 'github') {
                    $gitHubSession = $session;
                    break;
                }
            }

            if (empty($gitHubSession)) {
                throw new Exception(Exception::USER_SESSION_NOT_FOUND, 'GitHub session not found.');
            }

            $provider = $gitHubSession->getAttribute('provider', '');
            $accessToken = $gitHubSession->getAttribute('providerAccessToken');
            $accessTokenExpiry = $gitHubSession->getAttribute('providerAccessTokenExpiry');
            $refreshToken = $gitHubSession->getAttribute('providerRefreshToken');

            $appId = $project->getAttribute('oAuthProviders', [])[$provider . 'Appid'] ?? '';
            $appSecret = $project->getAttribute('oAuthProviders', [])[$provider . 'Secret'] ?? '{}';

            $oAuthProviders = Config::getParam('oAuthProviders');
            $className = $oAuthProviders[$provider]['class'];
            if (!\class_exists($className)) {
                throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED);
            }

            $oauth2 = new $className($appId, $appSecret, '', [], []);

            $isExpired = new \DateTime($accessTokenExpiry) < new \DateTime('now');
            if ($isExpired) {
                try {
                    $oauth2->refreshTokens($refreshToken);

                    $accessToken = $oauth2->getAccessToken('');
                    $refreshToken = $oauth2->getRefreshToken('');

                    $verificationId = $oauth2->getUserID($accessToken);

                    if (empty($verificationId)) {
                        throw new \Exception("Locked tokens."); // Race codition, handeled in catch
                    }

                    $gitHubSession
                        ->setAttribute('providerAccessToken', $accessToken)
                        ->setAttribute('providerRefreshToken', $refreshToken)
                        ->setAttribute('providerAccessTokenExpiry', DateTime::addSeconds(new \DateTime(), (int)$oauth2->getAccessTokenExpiry('')));

                    Authorization::skip(fn () => $dbForProject->updateDocument('sessions', $gitHubSession->getId(), $gitHubSession));

                    $dbForProject->purgeCachedDocument('users', $user->getId());
                } catch (Throwable $err) {
                    $index = 0;
                    do {
                        $previousAccessToken = $gitHubSession->getAttribute('providerAccessToken');

                        $user = Authorization::skip(fn () => $dbForPlatform->getDocument('users', $userId));
                        $sessions = $user->getAttribute('sessions', []);

                        $gitHubSession = new Document();
                        foreach ($sessions as $session) {
                            if ($session->getAttribute('provider', '') === 'github') {
                                $gitHubSession = $session;
                                break;
                            }
                        }

                        $accessToken = $gitHubSession->getAttribute('providerAccessToken');

                        if ($accessToken !== $previousAccessToken) {
                            break;
                        }

                        $index++;
                        \usleep(500000);
                    } while ($index < 10);
                }
            }

            $oauth2 = new $className($appId, $appSecret, '', [], []);
            $githubUser = $oauth2->getUserSlug($accessToken);
            $githubId = $oauth2->getUserID($accessToken);

            return [
                'name' => $githubUser,
                'id' => $githubId
            ];
        } catch (Exception $error) {
            return [];
        }
    }
}
