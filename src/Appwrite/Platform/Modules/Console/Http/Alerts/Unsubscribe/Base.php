<?php

namespace Appwrite\Platform\Modules\Console\Http\Alerts\Unsubscribe;

use Appwrite\Alert\Unsubscribe;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Operator;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

/**
 * An alert email's unsubscribe link and one-click header hold a token naming
 * the console user and the monitor. With it alone, the console's unsubscribe
 * page reads the monitor and what is stopped already, and stops or resumes
 * that monitor's emails (`monitor`) or every alert email of its type (`all`)
 * where the type allows it. Console notifications are not affected. Not part of
 * any SDK: only that page and mail clients call it.
 */
abstract class Base extends Action
{
    use HTTP;

    public const string SCOPE_MONITOR = 'monitor';
    public const string SCOPE_ALL = 'all';

    abstract protected function getMethod(): string;

    /**
     * The change to the user's opted-out monitors and types, applied in the
     * database so concurrent requests each keep theirs.
     *
     * @param list<string> $unsubscribed what the user had stopped when read
     */
    abstract protected function change(array $unsubscribed, string $entry): ?Operator;

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
            ->param('scope', self::SCOPE_MONITOR, new WhiteList([self::SCOPE_MONITOR, self::SCOPE_ALL], true), 'What to stop or resume: this monitor\'s emails, or every alert email of its type.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $token,
        string $scope,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        [$user, $monitor] = $this->resolve($token, $request, $dbForPlatform, $authorization);

        $entry = $monitor->getId();
        if ($scope === self::SCOPE_ALL) {
            if (!$this->optOut($monitor)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'This monitor\'s type does not allow stopping all of its emails.');
            }
            $entry = Unsubscribe::type((string) $monitor->getAttribute('type'));
        }

        $change = $this->change($user->getAttribute('alertsUnsubscribed', []), $entry);
        if ($change !== null) {
            $authorization->skip(fn () => $dbForPlatform->updateDocument('users', $user->getId(), new Document([
                'alertsUnsubscribed' => $change,
            ])));
        }

        $response->noContent();
    }

    /**
     * The user and the monitor the token names. A monitor deleted since the
     * email was sent comes back holding only its ID and type.
     *
     * @return array{0: Document, 1: Document}
     */
    protected function resolve(string $token, Request $request, Database $dbForPlatform, Authorization $authorization): array
    {
        // A one-click unsubscribe (RFC 8058) posts `List-Unsubscribe=One-Click` to the
        // header's URL, so the token comes in the query rather than the body.
        $token = $token !== '' ? $token : (string) $request->getQuery('token', '');

        try {
            ['userId' => $userId, 'monitorId' => $monitorId, 'type' => $type] = (new Unsubscribe(System::getEnv('_APP_OPENSSL_KEY_V1')))->verify($token);
        } catch (\InvalidArgumentException) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $user = $authorization->skip(fn () => $dbForPlatform->getDocument('users', $userId));
        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $monitor = $authorization->skip(fn () => $dbForPlatform->getDocument('monitors', $monitorId));

        // The type comes from the token, so a deleted monitor's link still reaches its type-wide opt-out.
        return [$user, $monitor->isEmpty() ? new Document(['$id' => $monitorId, 'type' => $type]) : $monitor];
    }

    /**
     * Whether every alert email of the monitor's type can be stopped at once.
     */
    protected function optOut(Document $monitor): bool
    {
        return (Config::getParam('alerts', [])['types'][(string) $monitor->getAttribute('type', '')]['optOut'] ?? false) === true;
    }
}
