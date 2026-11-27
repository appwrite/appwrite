<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Builds\Signing;

use Appwrite\Distribution\Builds;
use Appwrite\Distribution\Signing;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;
use Utopia\System\System;

/**
 * Hands a sign job its signing bundle, once. The job presents the single-use
 * token Builds issued for it; the bundle is the identity Builds recorded on the
 * subject when it submitted that job. For an upload-sign the subject is the
 * artifact, so `:buildId` carries the artifact id.
 */
class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getBuildSigning';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/distribution/apps/:appId/builds/:buildId/signing')
            ->desc('Get build signing')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('buildId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Build ID, or the artifact ID of an uploaded bundle.', false, ['dbForProject'])
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $buildId,
        Request $request,
        Response $response,
        Document $project,
        Database $dbForProject,
        Authorization $authorization,
        Cache $cache,
    ): void {
        // Operators switch Distribution on with _APP_DISTRIBUTION. The api hook enforces it by SDK namespace, which internal routes do not carry.
        if (System::getEnv('_APP_DISTRIBUTION', 'disabled') !== 'enabled') {
            throw new Exception(Exception::GENERAL_SERVICE_DISABLED);
        }

        $header = $request->getHeaderLine('authorization');
        if (!\str_starts_with($header, 'Bearer ')) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Missing signing token.');
        }

        $binding = (new Signing($cache))->redeem($project, \substr($header, \strlen('Bearer ')));
        if ($binding === null || $binding['appId'] !== $appId || $binding['subjectId'] !== $buildId) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid signing token.');
        }

        $signingIdentity = $authorization->skip(fn () => $this->signingIdentity($dbForProject, $binding));

        Span::add('signing.identity.id', $signingIdentity->getId());

        $response
            ->addHeader('Cache-Control', 'no-store')
            ->json(Signing::bundle($signingIdentity));
    }

    /**
     * Reads past document permissions only what the redeemed token is bound to: the
     * guest caller is authenticated by the token alone, so its binding, not the route
     * params, names the artifact and, through the current sign job, the identity.
     *
     * @param array{appId: string, subjectId: string, attempt: int} $binding
     */
    private function signingIdentity(Database $dbForProject, array $binding): Document
    {
        $artifact = $dbForProject->getDocument('distributionArtifacts', $binding['subjectId']);
        if ($artifact->isEmpty() || $artifact->getAttribute('appId') !== $binding['appId']) {
            throw new Exception(Exception::DISTRIBUTION_ARTIFACT_NOT_FOUND);
        }

        $job = $artifact->getAttribute('metadata', [])['job'] ?? [];
        if (($job['stage'] ?? '') !== Builds::STAGE_SIGN || (int) ($job['attempt'] ?? 0) !== $binding['attempt']) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'The signing token is not for the current sign job.');
        }

        $signingIdentity = $dbForProject->getDocument('distributionSigningIdentities', (string) ($job['signingIdentityId'] ?? ''));
        if ($signingIdentity->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_NOT_FOUND);
        }

        return $signingIdentity;
    }
}
