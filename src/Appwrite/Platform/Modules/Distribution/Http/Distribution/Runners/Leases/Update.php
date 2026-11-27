<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Runners\Leases;

use Appwrite\Abuse\Limit;
use Appwrite\Distribution\Lease\Token;
use Appwrite\Distribution\Leases;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

/**
 * Extends a held lease, or tells its runner to stop with `canceled` once the lease is
 * released, is no longer the build's current attempt or the build has ended. An expired
 * lease answers 404, so the agent reports it lost and the build is queued again. The
 * answer always carries an expiry, which the agent parses even when it stops.
 */
class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateRunnerLease';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/distribution/runners/:runnerId/leases/:leaseId')
            ->desc('Update runner lease')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('abuse', [Limit::failures(['url', 'ip'], 100)])
            ->param('runnerId', '', new UID(), 'Runner ID.')
            ->param('leaseId', '', new UID(), 'Lease ID.')
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $runnerId,
        string $leaseId,
        Request $request,
        Response $response,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        Authorization $authorization,
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
        $build = $authorization->skip(fn () => $this->build($dbForProject, $lease));
        $renewed = $authorization->skip(fn () => $leases->renew($lease, $build));

        $response->json([
            'expiresAt' => DateTime::formatTz((string) ($renewed ?? $lease)->getAttribute('expiresAt', '') ?: DateTime::now()),
            'canceled' => $renewed === null,
        ]);
    }

    private function build(Database $dbForProject, Document $lease): Document
    {
        return $dbForProject->getDocument('distributionBuilds', (string) $lease->getAttribute('buildId', ''));
    }
}
