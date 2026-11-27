<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Runners\Leases\Source;

use Appwrite\Abuse\Limit;
use Appwrite\Distribution\Builds;
use Appwrite\Distribution\Lease\Status;
use Appwrite\Distribution\Lease\Token;
use Appwrite\Distribution\Leases;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

/**
 * Where a runner downloads the source of its lease's commit: a short-lived archive URL
 * from the app's Git provider. Each call creates a new one, because a provider's
 * archive URL lasts minutes.
 */
class Get extends Action
{
    use HTTP;

    private const string FORMAT = 'tarball';

    public static function getName(): string
    {
        return 'getRunnerLeaseSource';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/distribution/runners/:runnerId/leases/:leaseId/source')
            ->desc('Get runner lease source')
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
            ->inject('vcsFactory')
            ->inject('installationTokens')
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
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
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
        $descriptor = $authorization->skip(fn () => $this->descriptor($dbForProject, $dbForPlatform, $vcsFactory, $installationTokens, $lease));

        $response
            ->addHeader('Cache-Control', 'no-store')
            ->json($descriptor);
    }

    /**
     * Reads past document permissions only the build the held lease names, its app and
     * that app's installation.
     *
     * @return array{url: string, headers: array<string, string>, format: string}
     */
    private function descriptor(Database $dbForProject, Database $dbForPlatform, VcsFactory $vcsFactory, InstallationTokens $installationTokens, Document $lease): array
    {
        $build = $dbForProject->getDocument('distributionBuilds', (string) $lease->getAttribute('buildId', ''));
        if (
            $lease->getAttribute('status') !== Status::Leased->value
            || Leases::isExpired($lease, \time())
            || $build->isEmpty()
            || $build->getAttribute('jobId') !== $lease->getId()
            || !\in_array($build->getAttribute('status'), Builds::ACTIVE, true)
        ) {
            throw new Exception(Exception::DISTRIBUTION_LEASE_NOT_FOUND);
        }

        $app = $dbForProject->getDocument('distributionApps', (string) $build->getAttribute('appId', ''));

        try {
            $vcs = $app->isEmpty() ? null : $this->vcs($app, $dbForPlatform, $vcsFactory, $installationTokens);
            $source = $vcs === null ? null : [
                'url' => $vcs->getRepositoryPresignedUrl(
                    (string) $build->getAttribute('providerRepositoryOwner', ''),
                    (string) $build->getAttribute('providerRepositoryName', ''),
                    (string) $build->getAttribute('providerCommitHash', ''),
                ),
                'headers' => $vcs->getRepositoryPresignedUrlHeaders(),
                'format' => self::FORMAT,
            ];
        } catch (\Throwable) {
            $source = null;
        }

        if ($source === null) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'The source could not be prepared.');
        }

        return $source;
    }

    private function vcs(Document $app, Database $dbForPlatform, VcsFactory $vcsFactory, InstallationTokens $installationTokens): ?Git
    {
        $installationId = (string) $app->getAttribute('installationId', '');
        $installation = $installationId === '' ? new Document() : $dbForPlatform->getDocument('installations', $installationId);
        if ($installation->isEmpty()) {
            return null;
        }

        return $vcsFactory->fromInstallation($installationTokens->refreshForInstallation($installation, $dbForPlatform, $vcsFactory));
    }
}
