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
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updateSubtitle';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/videos/:videoId/subtitles/:subtitleId')
            ->desc('Update subtitle')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.write')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('event', 'videos.[videoId].subtitles.[subtitleId].update')
            ->label('audits.event', 'subtitle.update')
            ->label('audits.resource', 'video/{request.videoId}/subtitle/{request.subtitleId}')
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'subtitles',
                name: 'updateSubtitle',
                description: '/docs/references/videos/update-subtitle.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VIDEO_SUBTITLE,
                    )
                ]
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->param('subtitleId', '', new UID(), 'Subtitle unique ID.')
            ->param('bucketId', '', new UID(), 'Storage bucket unique ID holding the subtitle file.')
            ->param('fileId', '', new UID(), 'Subtitle file unique ID.')
            ->param('name', '', new Text(128), 'Subtitle display name.')
            ->param('code', '', new WhiteList(\array_column(Config::getParam('locale-languages'), 'code2')), 'Subtitle ISO 639-2 three-letter language code.')
            ->param('default', false, new Boolean(true), 'Make this the default subtitle track for the video.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('user')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->inject('publisherForVideos')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        string $subtitleId,
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
        Event $queueForEvents,
        VideoPublisher $publisherForVideos
    ): void {
        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);

        $subtitle = $authorization->skip(fn () => $dbForProject->getDocument('videos_subtitles', $subtitleId));

        if ($subtitle->isEmpty() || $subtitle->getAttribute('videoInternalId') !== $video->getSequence()) {
            throw new Exception(Exception::VIDEO_SUBTITLE_NOT_FOUND);
        }

        $file = $this->assertFileAccess($dbForProject, $authorization, $user, $bucketId, $fileId);

        if (!\in_array($file->getAttribute('mimeType', ''), self::SUBTITLE_MIME_TYPES, true)) {
            throw new Exception(Exception::VIDEO_SUBTITLE_NOT_VALID);
        }

        $sourceChanged = $subtitle->getAttribute('fileId') !== $file->getId();

        if ($default) {
            $this->clearDefault($dbForProject, $authorization, $video, $subtitle->getId());
        }

        $subtitle
            ->setAttribute('bucketId', $file->getAttribute('bucketId', $bucketId))
            ->setAttribute('bucketInternalId', $file->getAttribute('bucketInternalId', ''))
            ->setAttribute('fileId', $file->getId())
            ->setAttribute('fileInternalId', $file->getSequence())
            ->setAttribute('name', $name)
            ->setAttribute('code', $code)
            ->setAttribute('default', $default);

        // Only a new source needs re-packaging; renaming or re-flagging the default
        // track leaves the already-segmented WebVTT valid.
        if ($sourceChanged) {
            $subtitle
                ->setAttribute('status', self::STATUS_WAITING)
                ->setAttribute('targetDuration', null)
                ->setAttribute('path', null);
        }

        $subtitle = $authorization->skip(fn () => $dbForProject->updateDocument('videos_subtitles', $subtitle->getId(), $subtitle));

        if ($sourceChanged) {
            $publisherForVideos->enqueue(new VideoMessage(
                project: $project,
                action: VideoAction::Subtitle,
                video: $video,
                subtitle: $subtitle,
            ));
        }

        $queueForEvents
            ->setParam('videoId', $video->getId())
            ->setParam('subtitleId', $subtitle->getId());

        $response->dynamic($subtitle, Response::MODEL_VIDEO_SUBTITLE);
    }

    /**
     * Only one track per video may be the default, so demote any current holder.
     */
    private function clearDefault(Database $dbForProject, Authorization $authorization, Document $video, string $exceptId): void
    {
        $existing = $authorization->skip(fn () => $dbForProject->find('videos_subtitles', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('default', [true]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));

        foreach ($existing as $subtitle) {
            if ($subtitle->getId() === $exceptId) {
                continue;
            }

            $authorization->skip(fn () => $dbForProject->updateDocument(
                'videos_subtitles',
                $subtitle->getId(),
                $subtitle->setAttribute('default', false)
            ));
        }
    }
}
