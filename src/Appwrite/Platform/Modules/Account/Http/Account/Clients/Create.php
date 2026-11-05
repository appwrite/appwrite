<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Clients;

use Appwrite\Abuse\Limit;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Geo\Geo;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Token as ProofsToken;
use Utopia\Auth\Store;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Validator\Authorization;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createClient';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/clients')
            ->desc('Create client')
            ->groups(['api', 'account'])
            ->label('scope', 'sessions.write')
            ->label('audits.event', 'client.create')
            ->label('audits.resource', 'client/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'clients',
                name: 'createClient',
                description: <<<EOT
                Create an account switching client. A client is a container, scoped to one device, that holds multiple sessions and tracks which one is active. Once a client exists, session creation endpoints attach the new session to it automatically, so a single device can stay signed in to several accounts and switch between them.

                The client secret is returned in a cookie. Run this on app start: if the device already has a valid client, the existing one is returned with the same status code instead of an error, so it is always safe to call.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_CLIENT,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse', [Limit::attempts(['ip'], 50)])
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('geo')
            ->inject('client')
            ->inject('locale')
            ->inject('proofForToken')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        Request $request,
        Response $response,
        Document $project,
        Database $dbForProject,
        Geo $geo,
        ?Document $client,
        Locale $locale,
        ProofsToken $proofForToken,
        bool $domainVerification,
        ?string $cookieDomain,
        Event $queueForEvents,
        Authorization $authorization,
    ): void {
        if (($project->getAttribute('auths', [])['accountSwitching'] ?? true) === false) {
            throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Account switching is disabled for this project');
        }

        // A device that already holds a valid client keeps it: devs call this on every app
        // start, so returning the existing client beats an error. No new cookie is written,
        // the device already carries it, and the secret is already gone from our side.
        if ($client !== null) {
            $activeSessionId = $client->getAttribute('activeSessionId', '');
            $sessions = $client->getAttribute('sessions', []);
            foreach ($sessions as $key => $session) {
                /** @var Document $session */
                $session
                    ->setAttribute('countryName', $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown')))
                    ->setAttribute('current', $session->getId() === $activeSessionId)
                    ->setAttribute('secret', $session->getAttribute('secret', ''));
                $sessions[$key] = $session;
            }
            $client->setAttribute('sessions', $sessions);

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->dynamic($client, Response::MODEL_CLIENT);
            return;
        }

        $protocol = $request->getProtocol();
        $duration = $project->getAttribute('auths', [])['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG;
        $expire = DateTime::addSeconds(new \DateTime(), $duration);

        $secret = $proofForToken->generate();
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $geoRecord = $geo->get($request->getIP());

        $clientId = Id::unique();
        $client = new Document(array_merge(
            [
                '$id' => $clientId,
                '$permissions' => [],
                'secret' => $proofForToken->hash($secret), // One way hash encryption to protect DB leak
                'activeSessionId' => '',
                'activeSessionInternalId' => '',
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'countryCode' => \strtolower($geoRecord->getCountryCode()),
                'expire' => $expire,
            ],
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        $client = $authorization->skip(fn () => $dbForProject->createDocument('clients', $client));

        $store = new Store();
        $store
            ->setKey($project->getClientKey())
            ->setProperty('id', $clientId)
            ->setProperty('secret', $secret);
        $encoded = $store->encode();

        if (!$domainVerification) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([$store->getKey() => $encoded]));
        }

        $response
            ->addCookie($store->getKey() . '_legacy', $encoded, (new \DateTime($expire))->getTimestamp(), '/', $cookieDomain, ('https' == $protocol), true, null)
            ->addCookie($store->getKey(), $encoded, (new \DateTime($expire))->getTimestamp(), '/', $cookieDomain, ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED);

        $client
            ->setAttribute('sessions', [])
            ->setAttribute('secret', $encoded);

        $queueForEvents
            ->setParam('clientId', $client->getId())
            ->setPayload($response->output($client, Response::MODEL_CLIENT), sensitive: ['secret']);

        $response->dynamic($client, Response::MODEL_CLIENT);
    }
}
