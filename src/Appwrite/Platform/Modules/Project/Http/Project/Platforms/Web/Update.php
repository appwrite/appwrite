<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Platforms\Web;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\Network\Platform;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Hostname;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectWebPlatform';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/project/platforms/web/:platformId')
            ->httpAlias('/v1/projects/:projectId/platforms/:platformId')
            ->desc('Update project web platform')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'platforms.[platformId].update')
            ->label('audits.event', 'project.platform.update')
            ->label('audits.resource', 'project.platform/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'platforms',
                name: 'updateWebPlatform',
                description: <<<EOT
                Update a web platform by its unique ID. Use this endpoint to update the platform's name or hostname.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PLATFORM_WEB,
                    )
                ]
            ))
            ->param('platformId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Platform ID.', false, ['dbForPlatform'])
            ->param('name', null, new Text(128), 'Platform name. Max length: 128 chars.')
            ->param('hostname', '', new Hostname(), 'Platform web hostname. Max length: 256 chars.', optional: true) // Optional for backwards compatibility
            ->param('key', '', new Text(256), 'Package name for Android or bundle ID for iOS or macOS. Max length: 256 chars.', optional: true, deprecated: true) // Exists for backwards compatibility
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $platformId,
        string $name,
        string $hostname,
        ?string $key, // For backwards compatibility
        Response $response,
        QueueEvent $queueForEvents,
        Database $dbForPlatform,
        Authorization $authorization,
        Document $project,
    ) {
        $key = $key ?? ''; // App platform attribute, backwards compatibility

        // Backwards compatibility
        // Used to have: type, name, key, hostname
        if (!empty($key)) {
            // Validate deprecated app id (key)
            $keyValidator = new Text(256);
            if (!$keyValidator->isValid($key)) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Param "key" is invalid: ' . $keyValidator->getDescription());
            }
        }

        // One day, ideally, we ensure hostname is not empty
        // But for backwards compatibility backend must threat it as optional for now

        $platform = $authorization->skip(fn () => $dbForPlatform->getDocument('platforms', $platformId));

        if ($platform->isEmpty() || $platform->getAttribute('projectInternalId', '') !== $project->getSequence()) {
            throw new Exception(Exception::PLATFORM_NOT_FOUND);
        }

        // Wrapped in if, for backwards compatibility
        if (!empty($hostname)) {
            $supportedTypes = [
                Platform::TYPE_WEB,
                // Backwards compatibility
                'flutter-web',
                'unity',
                'flutter-macos',
                'flutter-ios',
                'react-native-ios',
                'apple-ios',
                'apple-macos',
                'apple-watchos',
                'apple-tvos',
                'flutter-android',
                'react-native-android',
                'flutter-windows',
                'flutter-linux',
            ];
            if (!in_array($platform->getAttribute('type', ''), $supportedTypes)) {
                throw new Exception(Exception::PLATFORM_METHOD_UNSUPPORTED);
            }
        }

        $updates = new Document([
            'name' => $name,
        ]);

        // Wrapped in if, for backwards compatibility
        if (!empty($hostname)) {
            $updates->setAttribute('hostname', $hostname);
        }

        // Backwards compatibility
        if (!empty($key)) {
            $updates->setAttribute('key', $key);
        }

        try {
            $platform = $authorization->skip(fn () => $dbForPlatform->updateDocument('platforms', $platform->getId(), $updates));
        } catch (Duplicate) {
            throw new Exception(Exception::PLATFORM_ALREADY_EXISTS);
        }

        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents->setParam('platformId', $platform->getId());

        $response->dynamic($platform, Response::MODEL_PLATFORM_WEB);
    }
}
