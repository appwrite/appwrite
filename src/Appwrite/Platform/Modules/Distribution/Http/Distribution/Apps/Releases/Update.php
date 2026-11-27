<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Releases;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateRelease';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/distribution/apps/:appId/releases/:releaseId')
            ->desc('Update release')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].releases.[releaseId].update')
            ->label('audits.event', 'distribution.release.update')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'releases',
                name: 'updateRelease',
                description: <<<EOT
                Update a release by its unique ID. Only the parameters you pass are changed. New notes replace the current notes. Submissions already sent to a store keep what they were sent with.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DISTRIBUTION_RELEASE,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('releaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Release ID.', false, ['dbForProject'])
            ->param('version', null, new Nullable(new Text(64, 1)), 'Marketing version shown in the stores, for example 1.2.0. Max length: 64 chars.', true)
            ->param('notes', null, new Nullable(new JSON()), 'Release notes as an object of locale to text, for example {"en-US": "Bug fixes."}. Replaces the current notes.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    /**
     * @param array<array-key, mixed>|string|null $notes
     */
    public function action(
        string $appId,
        string $releaseId,
        ?string $version,
        array|string|null $notes,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $release = $dbForProject->getDocument('distributionReleases', $releaseId);

        if ($release->isEmpty() || $release->getAttribute('appInternalId') !== $app->getSequence()) {
            throw new Exception(Exception::DISTRIBUTION_RELEASE_NOT_FOUND);
        }

        if (\is_null($version) && \is_null($notes)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'At least one of version or notes is required.');
        }

        $updates = new Document();

        if (!\is_null($version)) {
            $updates
                ->setAttribute('version', $version)
                ->setAttribute('search', \implode(' ', [$release->getId(), $version]));
        }

        if (!\is_null($notes)) {
            $updates->setAttribute('notes', $this->parseNotes($notes));
        }

        try {
            $release = $dbForProject->updateDocument('distributionReleases', $release->getId(), $updates);
        } catch (Duplicate) {
            throw new Exception(Exception::DISTRIBUTION_RELEASE_ALREADY_EXISTS);
        }

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setParam('releaseId', $release->getId());

        $response->dynamic($release, Response::MODEL_DISTRIBUTION_RELEASE);
    }

    /**
     * @param array<array-key, mixed>|string $notes
     * @return array<string, string>
     */
    private function parseNotes(array|string $notes): array
    {
        if (\is_string($notes)) {
            $notes = \json_decode($notes, true);
        }

        if (!\is_array($notes) || (!empty($notes) && \array_is_list($notes))) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Notes must be an object of locale to text.');
        }

        foreach ($notes as $locale => $text) {
            if (!\is_string($locale) || $locale === '') {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Notes keys must be locales, for example en-US.');
            }

            if (!\is_string($text)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Notes for locale '{$locale}' must be a string.");
            }
        }

        if (\strlen(\json_encode($notes, JSON_THROW_ON_ERROR)) > Create::NOTES_LENGTH) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Notes must not exceed ' . Create::NOTES_LENGTH . ' characters when encoded as JSON.');
        }

        return $notes;
    }
}
