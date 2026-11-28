<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Runners;

use Appwrite\Abuse\Limit;
use Appwrite\Distribution\Runner\Status;
use Appwrite\Distribution\Runner\Token;
use Appwrite\Distribution\Runners;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

/**
 * The runner agent's heartbeat: `online` every 30 seconds, `draining` while it
 * finishes its leases, and `offline` when it exits.
 */
class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateRunner';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/distribution/runners/:runnerId')
            ->desc('Update runner')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('abuse', [Limit::failures(['url', 'ip'], 100)])
            ->param('runnerId', '', new UID(), 'Runner ID.')
            ->param('status', '', new WhiteList(Status::values(), true), 'Runner status.')
            ->param('version', null, new Nullable(new Text(32)), 'Runner agent version.', true)
            ->param('labels', null, new Nullable(new ArrayList(new Text(128), APP_LIMIT_ARRAY_PARAMS_SIZE)), 'Labels the runner can build for.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param list<string>|null $labels
     */
    public function action(
        string $runnerId,
        string $status,
        ?string $version,
        ?array $labels,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        // Operators switch Distribution on with _APP_DISTRIBUTION. The api hook enforces it by SDK namespace, which internal routes do not carry.
        if (System::getEnv('_APP_DISTRIBUTION', 'disabled') !== 'enabled') {
            throw new Exception(Exception::GENERAL_SERVICE_DISABLED);
        }

        $header = $request->getHeaderLine('authorization');
        $token = \str_starts_with($header, 'Bearer ') ? \substr($header, \strlen('Bearer ')) : '';
        $verified = Token::verify($token, Runners::secret());
        if ($verified === null || $verified->runnerId !== $runnerId) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid runner token.');
        }

        $runners = new Runners($dbForPlatform);
        $runner = $authorization->skip(fn () => $runners->held($verified, $token));
        $runner = $authorization->skip(fn () => $runners->beat($runner, Status::from($status), $version, $labels));

        $response->json([
            'runnerId' => $runner->getId(),
            'status' => $runner->getAttribute('status'),
            'seenAt' => DateTime::formatTz($runner->getAttribute('seenAt')),
        ]);
    }
}
