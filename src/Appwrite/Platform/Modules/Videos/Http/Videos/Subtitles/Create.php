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
        Event $queueForEvents,
        VideoPublisher $publisherForVideos
    ): void {
        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);
        $file = $this->assertFileAccess($dbForProject, $authorization, $user, $bucketId, $fileId);

        if (!\in_array($file->getAttribute('mimeType', ''), self::SUBTITLE_MIME_TYPES, true)) {
            throw new Exception(Exception::VIDEO_SUBTITLE_NOT_VALID);
        }

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

        if (!$default) {
            // Uploads outrank auto-extracted tracks: when the current default is
            // an embedded track for the same language, the upload takes the flag
            // so players pick the authored file first. Runs after the insert so a
            // failed create cannot cost the video its default track. Nothing is
            // deleted — extraction runs once per video, so removing an extracted
            // track is irreversible and stays an explicit user action.
            $subtitle = $this->takeDefaultFromEmbedded($dbForProject, $authorization, $video, $subtitle);
        }

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
     * Demote an auto-extracted default track of the same language and hand the
     * flag to the just-created upload. An uploaded default (authored choice) is
     * left alone.
     *
     * Demote before promote: a failure between the two writes leaves the video
     * with no default rather than two, and players handle a missing default far
     * better than a pair of DEFAULT=YES tracks in one manifest.
     */
    private function takeDefaultFromEmbedded(
        Database $dbForProject,
        Authorization $authorization,
        Document $video,
        Document $subtitle
    ): Document {
        $existing = $authorization->skip(fn () => $dbForProject->find('videos_subtitles', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('default', [true]),
            Query::limit(1),
        ]));

        $current = $existing[0] ?? null;

        if (
            $current === null
            || !empty($current->getAttribute('fileId', ''))
            || $current->getAttribute('code', '') !== $subtitle->getAttribute('code', '')
        ) {
            return $subtitle;
        }

        $authorization->skip(fn () => $dbForProject->updateDocument(
            'videos_subtitles',
            $current->getId(),
            new Document(['default' => false])
        ));

        return $authorization->skip(fn () => $dbForProject->updateDocument(
            'videos_subtitles',
            $subtitle->getId(),
            new Document(['default' => true])
        ));
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
