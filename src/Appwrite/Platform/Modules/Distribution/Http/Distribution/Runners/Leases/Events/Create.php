<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Runners\Leases\Events;

use Appwrite\Abuse\Limit;
use Appwrite\Distribution\Builds;
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
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;

/**
 * A runner's batch of build events, queued in order as the build job's events of the
 * lease. The worker drops repeats, so a batch resent after a lost answer is accepted.
 * A lease that is no longer the build's current attempt has its events dropped.
 */
class Create extends Action
{
    use HTTP;

    private const int EVENTS = 256;

    private const int EVENT_SIZE = 1_100_000;

    public static function getName(): string
    {
        return 'createRunnerLeaseEvent';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/runners/:runnerId/leases/:leaseId/events')
            ->desc('Create runner lease event')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('abuse', [Limit::failures(['url', 'ip'], 100)])
            ->param('runnerId', '', new UID(), 'Runner ID.')
            ->param('leaseId', '', new UID(), 'Lease ID.')
            ->param('events', [], new ArrayList(new Assoc(self::EVENT_SIZE), self::EVENTS), 'Build events, oldest first. Maximum of ' . self::EVENTS . ' events are allowed.')
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('publisherForDistributionBuilds')
            ->callback($this->action(...));
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    public function action(
        string $runnerId,
        string $leaseId,
        array $events,
        Request $request,
        Response $response,
        Document $project,
        Database $dbForProject,
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

        $lease = $authorization->skip(fn () => (new Leases($dbForPlatform))->held($verified, $token));
        if (!$authorization->skip(fn () => $this->isCurrent($dbForProject, $lease))) {
            $response->noContent();
            return;
        }

        $queued = [];
        foreach ($events as $event) {
            try {
                $job = Leases::event($lease, $event);
            } catch (\InvalidArgumentException $error) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $error->getMessage());
            }

            if ($job !== null && !isset($queued[$job['id']])) {
                $queued[$job['id']] = $job;
            }
        }

        foreach ($queued as $job) {
            $publisherForDistributionBuilds->enqueue(new DistributionBuildMessage(
                project: $project,
                action: DistributionBuildAction::Event,
                id: $job['id'],
                event: $job['event']->value,
                data: $job['data'],
            ));
        }

        $response->noContent();
    }

    private function isCurrent(Database $dbForProject, Document $lease): bool
    {
        if ($lease->getAttribute('status') !== Status::Leased->value) {
            return false;
        }

        $build = $dbForProject->getDocument('distributionBuilds', (string) $lease->getAttribute('buildId', ''));

        return !$build->isEmpty()
            && $build->getAttribute('jobId') === $lease->getId()
            && \in_array($build->getAttribute('status'), Builds::ACTIVE, true);
    }
}
