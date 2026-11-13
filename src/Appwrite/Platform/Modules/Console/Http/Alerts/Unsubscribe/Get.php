<?php

namespace Appwrite\Platform\Modules\Console\Http\Alerts\Unsubscribe;

use Appwrite\Alert\Unsubscribe;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Operator;
use Utopia\Database\Validator\Authorization;

/**
 * What the unsubscribe page shows: the monitor and project the email named,
 * whether its type's emails can all be stopped, and what is stopped already.
 * Nothing the email did not already show.
 */
class Get extends Base
{
    public static function getName(): string
    {
        return 'getAlertsUnsubscribe';
    }

    protected function getMethod(): string
    {
        return self::HTTP_REQUEST_METHOD_GET;
    }

    protected function change(array $unsubscribed, string $entry): ?Operator
    {
        return null;
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

        $type = (string) $monitor->getAttribute('type', '');
        $project = (string) $monitor->getAttribute('projectId', '') === ''
            ? null
            : $authorization->skip(fn () => $dbForPlatform->getDocument('projects', (string) $monitor->getAttribute('projectId')));
        $unsubscribed = $user->getAttribute('alertsUnsubscribed', []);

        $response->json([
            'monitorName' => (string) $monitor->getAttribute('name', ''),
            'projectName' => (string) $project?->getAttribute('name', ''),
            'type' => $type,
            'optOut' => $this->optOut($monitor),
            'monitor' => \in_array($monitor->getId(), $unsubscribed, true),
            'all' => $type !== '' && \in_array(Unsubscribe::type($type), $unsubscribed, true),
        ]);
    }
}
