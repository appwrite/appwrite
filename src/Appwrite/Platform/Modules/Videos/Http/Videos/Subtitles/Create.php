<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Subtitles;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Video as VideoMessage;
use Appwrite\Event\Message\VideoAction;
use Appwrite\Event\Publisher\Video as VideoPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createSubtitle';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/videos/:videoId/subtitles')
            ->desc('Create subtitle')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.write')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('event', 'videos.[videoId].subtitles.[subtitleId].create')
            ->label('audits.event', 'subtitle.create')
            ->label('audits.resource', 'video/{request.videoId}/subtitle/{response.$id}')
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'subtitles',
                name: 'createSubtitle',
                description: '/docs/references/videos/create-subtitle.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_VIDEO_SUBTITLE,
                    )
                ]
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->param('bucketId', '', new UID(), 'Storage bucket unique ID holding the subtitle file.')
            ->param('fileId', '', new UID(), 'Subtitle file unique ID.')
            // The name is rendered into HLS/DASH manifests, which are quote- and
            // line-delimited; the allowlist keeps structural characters out at the door.
            ->param('name', '', new Text(128, allowList: [...Text::ALPHABET_UPPER, ...Text::ALPHABET_LOWER, ...Text::NUMBERS, ' ', '-', '.', ',', '(', ')', '_', '\'']), 'Subtitle display name. Allowed characters: a-z, A-Z, 0-9, space, and - . , ( ) _ \'')
            ->param('code', '', new WhiteList(\array_column(Config::getParam('locale-languages'), 'code2')), 'Subtitle ISO 639-2 three-letter language code.')
            ->param('default', false, new Boolean(true), 'Make this the default subtitle track for the video.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('user')
            ->inject('authorization')
            ->inject('deviceForVideos')
            ->inject('queueForEvents')
            ->inject('publisherForVideos')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        string $bucketId,
        string $fileId,
        string $name,
        string $code,
        bool $default,
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Authorization $authorization,
        Device $deviceForVideos,
        Event $queueForEvents,
        VideoPublisher $publisherForVideos
    ): void {
        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);
        $file = $this->assertFileAccess($dbForProject, $authorization, $user, $bucketId, $fileId);

        if (!\in_array($file->getAttribute('mimeType', ''), self::SUBTITLE_MIME_TYPES, true)) {
            throw new Exception(Exception::VIDEO_SUBTITLE_NOT_VALID);
        }

        // Uploads win over auto-extracted tracks for the same language.
        $this->deleteEmbeddedSubtitlesForCode($dbForProject, $authorization, $deviceForVideos, $video, $code);

        if ($default) {
            $this->clearDefault($dbForProject, $authorization, $video);
        }

        $subtitle = $authorization->skip(fn () => $dbForProject->createDocument('videos_subtitles', new Document([
            '$id' => ID::unique(),
            'videoId' => $video->getId(),
            'videoInternalId' => $video->getSequence(),
            'bucketId' => $file->getAttribute('bucketId', $bucketId),
            'bucketInternalId' => $file->getAttribute('bucketInternalId', ''),
            'fileId' => $file->getId(),
            'fileInternalId' => $file->getSequence(),
            'name' => $name,
            'code' => $code,
            'default' => $default,
            'status' => self::STATUS_WAITING,
        ])));

        $publisherForVideos->enqueue(new VideoMessage(
            project: $project,
            action: VideoAction::Subtitle,
            video: $video,
            subtitle: $subtitle,
        ));

        $queueForEvents
            ->setParam('videoId', $video->getId())
            ->setParam('subtitleId', $subtitle->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($subtitle, Response::MODEL_VIDEO_SUBTITLE);
    }

    /**
     * Only one track per video may be the default, so demote any current holder.
     */
    private function clearDefault(Database $dbForProject, Authorization $authorization, Document $video): void
    {
        $existing = $authorization->skip(fn () => $dbForProject->find('videos_subtitles', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('default', [true]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));

        foreach ($existing as $subtitle) {
            $authorization->skip(fn () => $dbForProject->updateDocument(
                'videos_subtitles',
                $subtitle->getId(),
                $subtitle->setAttribute('default', false)
            ));
        }
    }
}
