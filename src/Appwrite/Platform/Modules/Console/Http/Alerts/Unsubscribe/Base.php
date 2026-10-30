<?php

namespace Appwrite\Platform\Modules\Console\Http\Alerts\Unsubscribe;

use Appwrite\Alert\Unsubscribe;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;

/**
 * The links and one-click headers of an alert email stop that monitor's
 * emails, or every alert email of its type where the type allows it, for the
 * console user the token was signed for; the same token resumes them. Console notifications are not affected.
 * Not part of any SDK: only the console's unsubscribe page and mail clients
 * call it, holding a token instead of a session.
 */
abstract class Base extends Action
{
    use HTTP;

    abstract protected function getMethod(): string;

    /**
     * The user's opted-out monitors once this request's scope is applied.
     *
     * @param list<string> $unsubscribed
     * @return list<string>
     */
    abstract protected function apply(array $unsubscribed, string $scope): array;

    public function __construct()
    {
        $this
            ->setHttpMethod($this->getMethod())
            ->setHttpPath('/v1/console/alerts/unsubscribe')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url}')
            ->label('abuse-limit', 60)
            ->param('token', '', new Text(2048, 0), 'Unsubscribe token from the alert email.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $token,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        // A one-click unsubscribe (RFC 8058) posts `List-Unsubscribe=One-Click` to the
        // header's URL, so the token comes in the query rather than the body.
        $token = $token !== '' ? $token : (string) $request->getQuery('token', '');

        try {
            ['userId' => $userId, 'scope' => $scope] = (new Unsubscribe(System::getEnv('_APP_OPENSSL_KEY_V1')))->verify($token);
        } catch (\InvalidArgumentException) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        // A type whose emails cannot all be stopped takes only per-monitor tokens.
        $prefix = Unsubscribe::type('');
        if (\str_starts_with($scope, $prefix) && (Config::getParam('alerts', [])['types'][\substr($scope, \strlen($prefix))]['optOut'] ?? false) !== true) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $user = $authorization->skip(fn () => $dbForPlatform->getDocument('users', $userId));
        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $unsubscribed = \array_values(\array_unique($this->apply($user->getAttribute('alertsUnsubscribed', []), $scope)));
        if ($unsubscribed !== $user->getAttribute('alertsUnsubscribed', [])) {
            $authorization->skip(fn () => $dbForPlatform->updateDocument('users', $user->getId(), new Document([
                'alertsUnsubscribed' => $unsubscribed,
            ])));
        }

        $response->noContent();
    }
}
