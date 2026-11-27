<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Releases;

use Appwrite\Distribution\Release\Status;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public const int NOTES_LENGTH = 65536;

    public static function getName(): string
    {
        return 'createRelease';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/apps/:appId/releases')
            ->desc('Create release')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].releases.[releaseId].create')
            ->label('audits.event', 'distribution.release.create')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'releases',
                name: 'createRelease',
                description: <<<EOT
                Create a new release for an app. A release is one marketing version with its release notes, shipped to each target through submissions. Its status starts as draft and follows its submissions. A version can be used by one release of the app only.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_DISTRIBUTION_RELEASE,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('releaseId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'Release ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('version', '', new Text(64, 1), 'Marketing version shown in the stores, for example 1.2.0. Max length: 64 chars.')
            ->param('notes', [], new JSON(), 'Release notes as an object of locale to text, for example {"en-US": "Bug fixes."}.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    /**
     * @param array<array-key, mixed>|string $notes
     */
    public function action(
        string $appId,
        string $releaseId,
        string $version,
        array|string $notes,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $notes = $this->parseNotes($notes);
        $releaseId = $releaseId === 'unique()' ? Id::unique() : $releaseId;

        try {
            $release = $dbForProject->createDocument('distributionReleases', new Document([
                '$id' => $releaseId,
                'appId' => $app->getId(),
                'appInternalId' => $app->getSequence(),
                'version' => $version,
                'notes' => $notes,
                'status' => Status::Draft->value,
                'search' => \implode(' ', [$releaseId, $version]),
            ]));
        } catch (Duplicate) {
            throw new Exception(Exception::DISTRIBUTION_RELEASE_ALREADY_EXISTS);
        }

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setParam('releaseId', $release->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($release, Response::MODEL_DISTRIBUTION_RELEASE);
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

        if (\strlen(\json_encode($notes, JSON_THROW_ON_ERROR)) > self::NOTES_LENGTH) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Notes must not exceed ' . self::NOTES_LENGTH . ' characters when encoded as JSON.');
        }

        return $notes;
    }
}
