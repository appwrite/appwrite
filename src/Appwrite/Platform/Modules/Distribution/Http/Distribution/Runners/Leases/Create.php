<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Runners\Leases;

use Appwrite\Abuse\Limit;
use Appwrite\Distribution\Lease\Token as LeaseToken;
use Appwrite\Distribution\Leases;
use Appwrite\Distribution\Runner\Status;
use Appwrite\Distribution\Runner\Token;
use Appwrite\Distribution\Runners;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

/**
 * Hands an online runner the oldest queued lease it has the labels for, with what it
 * needs to build, or a 204 when there is none. The queue spans every project, so the
 * runner's own token is the only credential, and a lease whose project or build has
 * moved on is released and passed over.
 */
class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createRunnerLease';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/runners/:runnerId/leases')
            ->desc('Create runner lease')
            ->groups(['api', 'distribution-claims'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('abuse', [Limit::failures(['url', 'ip'], 100)])
            ->param('runnerId', '', new UID(), 'Runner ID.')
            ->param('labels', null, new Nullable(new ArrayList(new Text(128), APP_LIMIT_ARRAY_PARAMS_SIZE)), 'Labels the runner can build for. Defaults to the labels of its last heartbeat.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('getProjectDB')
            ->inject('distributionBuildsFactory')
            ->callback($this->action(...));
    }

    /**
     * @param list<string>|null $labels
     */
    public function action(
        string $runnerId,
        ?array $labels,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        callable $distributionBuildsFactory,
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
        if ($runner->getAttribute('status') !== Status::Online->value) {
            $response->noContent();
            return;
        }

        $runner = $authorization->skip(fn () => $runners->touch($runner, \time()));
        $labels ??= \array_values(\array_map(strval(...), $runner->getAttribute('labels', [])));

        $leases = new Leases($dbForPlatform);
        $tries = Leases::TRIES;

        try {
            while ($tries > 0 && ($claimed = $authorization->skip(fn () => $leases->claim($runner, $labels, $tries))) !== null) {
                $tries -= \max(1, $claimed['tries']);
                $lease = $claimed['lease'];

                $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', (string) $lease->getAttribute('projectId', '')));
                if ($project->isEmpty() || (string) $project->getSequence() !== (string) $lease->getAttribute('projectInternalId', '')) {
                    $authorization->skip(fn () => $leases->release($lease));
                    continue;
                }

                $assignment = $authorization->skip(fn () => $distributionBuildsFactory($getProjectDB($project), $project)->claim($lease, $claimed['token']));
                if ($assignment === null) {
                    $authorization->skip(fn () => $leases->release($lease));
                    continue;
                }

                $response
                    ->setStatusCode(Response::STATUS_CODE_CREATED)
                    ->addHeader('Cache-Control', 'no-store')
                    ->json($assignment);
                return;
            }
        } catch (\LogicException $error) {
            if ($error->getMessage() !== LeaseToken::KEY_REQUIRED) {
                throw $error;
            }

            throw new Exception(Exception::GENERAL_SERVER_ERROR, LeaseToken::KEY_REQUIRED);
        }

        $response->noContent();
    }
}
