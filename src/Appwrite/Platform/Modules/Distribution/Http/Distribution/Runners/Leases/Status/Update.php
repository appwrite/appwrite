<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Runners\Leases\Status;

use Appwrite\Abuse\Limit;
use Appwrite\Distribution\Lease\Status;
use Appwrite\Distribution\Lease\Token;
use Appwrite\Distribution\Leases;
use Appwrite\Event\Message\DistributionBuild as DistributionBuildMessage;
use Appwrite\Event\Message\DistributionBuildAction;
use Appwrite\Event\Publisher\DistributionBuild as DistributionBuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

/**
 * A runner hands back a lease it cannot finish, such as when its VM fails before the
 * build starts, so the build is queued again without waiting for the lease to expire.
 * Handing back a lease that already ended changes nothing.
 */
class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateRunnerLeaseStatus';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/distribution/runners/:runnerId/leases/:leaseId/status')
            ->desc('Update runner lease status')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('abuse', [Limit::failures(['url', 'ip'], 100)])
            ->param('runnerId', '', new UID(), 'Runner ID.')
            ->param('leaseId', '', new UID(), 'Lease ID.')
            ->param('status', '', new WhiteList([Status::Queued->value], true), 'Lease status. Only `queued` is accepted, which hands the lease back.')
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('publisherForDistributionBuilds')
            ->callback($this->action(...));
    }

    public function action(
        string $runnerId,
        string $leaseId,
        string $status,
        Request $request,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
        DistributionBuildPublisher $publisherForDistributionBuilds,
    ): void {
        // Operators switch Distribution on with _APP_DISTRIBUTION. The api hook enforces it by SDK namespace, which internal routes do not carry.
        if (System::getEnv('_APP_DISTRIBUTION', 'disabled') !== 'enabled') {
            throw new Exception(Exception::GENERAL_SERVICE_DISABLED);
        }

        $header = $request->getHeaderLine('authorization');
        $token = \str_starts_with($header, 'Bearer ') ? \substr($header, \strlen('Bearer ')) : '';
        $verified = Token::verify($token, Leases::secret());
        if ($verified === null || $verified->runnerId !== $runnerId || $verified->leaseId !== $leaseId) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid lease token.');
        }

        if ($verified->projectInternalId !== (string) $project->getSequence()) {
            throw new Exception(Exception::DISTRIBUTION_LEASE_NOT_FOUND);
        }

        $leases = new Leases($dbForPlatform);
        $lease = $authorization->skip(fn () => $leases->held($verified, $token));

        if ($authorization->skip(fn () => $leases->expire($lease))) {
            $publisherForDistributionBuilds->enqueue(new DistributionBuildMessage(
                project: $project,
                action: DistributionBuildAction::Reconcile,
                id: (string) $lease->getAttribute('buildId', ''),
            ));
        }

        $response->noContent();
    }
}
