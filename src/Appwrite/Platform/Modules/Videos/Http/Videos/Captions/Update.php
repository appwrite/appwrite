<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Captions;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Video as VideoMessage;
use Appwrite\Event\Message\VideoAction;
use Appwrite\Event\Publisher\Video as VideoPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\Platform\Modules\Videos\SegmentCache;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Abuse\Adapter\TimeLimit;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updateCaption';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/videos/:videoId/captions/:captionId')
            ->desc('Update caption')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.write')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('event', 'videos.[videoId].captions.[captionId].update')
            ->label('audits.event', 'caption.update')
            ->label('audits.resource', 'video/{request.videoId}/caption/{request.captionId}')
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'captions',
                name: 'updateCaption',
                description: '/docs/references/videos/update-caption.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VIDEO_CAPTION,
                    )
                ]
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->param('captionId', '', new UID(), 'Caption unique ID.')
            ->param('bucketId', '', new UID(), 'Storage bucket unique ID holding the caption file. Omit together with fileId to only update name, code, or default.', true)
            ->param('fileId', '', new UID(), 'Caption file unique ID. Omit together with bucketId to only update name, code, or default.', true)
            // The name is rendered into HLS/DASH manifests, which are quote- and
            // line-delimited; the allowlist keeps structural characters out at the door.
            ->param('name', '', new Text(128, allowList: [...Text::ALPHABET_UPPER, ...Text::ALPHABET_LOWER, ...Text::NUMBERS, ' ', '-', '.', ',', '(', ')', '_', '\'']), 'Caption display name. Allowed characters: a-z, A-Z, 0-9, space, and - . , ( ) _ \'', true)
            ->param('code', '', new WhiteList(\array_column(Config::getParam('locale-languages'), 'code2')), 'Caption ISO 639-2 three-letter language code (for example `heb` for Hebrew).', true)
            ->param('default', null, new Nullable(new Boolean()), 'Make this the default caption track for the video. Omit to leave unchanged.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('user')
            ->inject('authorization')
            ->inject('timelimit')
            ->inject('queueForEvents')
            ->inject('publisherForVideos')
            ->inject('purgeVideoSegmentCache')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        string $captionId,
        string $bucketId,
        string $fileId,
        string $name,
        string $code,
        ?bool $default,
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Authorization $authorization,
        callable $timelimit,
        Event $queueForEvents,
        VideoPublisher $publisherForVideos,
        SegmentCache $segmentCache
    ): void {
        $video = $this->getAuthorizedVideo($dbForProject, $authorization, $user, $videoId);
        $this->assertCanUpdateVideo($authorization, $user, $video);

        $caption = $authorization->skip(fn () => $dbForProject->getDocument('videosCaptions', $captionId));

        if ($caption->isEmpty() || $caption->getAttribute('videoInternalId') !== $video->getSequence()) {
            throw new Exception(Exception::VIDEO_CAPTION_NOT_FOUND);
        }

        $replaceSource = $bucketId !== '' || $fileId !== '';

        if ($replaceSource && ($bucketId === '' || $fileId === '')) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'bucketId and fileId must be provided together to replace a caption file.');
        }

        $sourceChanged = false;

        if ($replaceSource) {
            $file = $this->assertFileAccess($dbForProject, $authorization, $user, $bucketId, $fileId);

            if (!\in_array($file->getAttribute('mimeType', ''), self::CAPTION_MIME_TYPES, true)) {
                throw new Exception(Exception::VIDEO_CAPTION_NOT_VALID);
            }

            // Compared by internal ID: a file deleted and re-uploaded under the
            // same ID is a different source.
            $sourceChanged = (string) $caption->getAttribute('bucketInternalId', '') !== (string) $file->getAttribute('bucketInternalId', '')
                || (string) $caption->getAttribute('fileInternalId', '') !== (string) $file->getSequence();
        }

        // A new source is another caption job, so it counts against the same
        // per-video allowance as creating a caption.
        if ($sourceChanged) {
            $isRateLimited = $timelimit(
                'url:/v1/videos/captions,projectId:{projectId},videoId:{videoId}',
                self::CAPTION_LIMIT,
                self::ENCODE_PERIOD,
                function (TimeLimit $timeLimit) use ($project, $video, $response): bool {
                    $timeLimit = $timeLimit->withParams([
                        '{projectId}' => (string) $project->getId(),
                        '{videoId}' => (string) $video->getId(),
                    ]);

                    $enabled = System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') !== 'disabled';
                    $result = $enabled ? $timeLimit->check() : $timeLimit->peek();

                    $response
                        ->addHeader('X-RateLimit-Limit', (string) $result->limit)
                        ->addHeader('X-RateLimit-Remaining', (string) $result->remaining)
                        ->addHeader('X-RateLimit-Reset', (string) $result->reset);

                    return $enabled && $result->limited;
                }
            );

            if ($isRateLimited) {
                throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED);
            }
        }

        $nextCode = $code !== '' ? $code : (string) $caption->getAttribute('code', '');

        $updates = [
            'name' => $name !== '' ? $name : $caption->getAttribute('name'),
            'code' => $nextCode,
        ];

        if ($default !== null) {
            $updates['default'] = $default;
        }

        if ($replaceSource) {
            $updates['bucketId'] = $file->getAttribute('bucketId', $bucketId);
            $updates['bucketInternalId'] = $file->getAttribute('bucketInternalId', '');
            $updates['fileId'] = $file->getId();
            $updates['fileInternalId'] = $file->getSequence();
        }

        // Only a new source needs re-packaging; renaming or re-flagging the default
        // track leaves the already-segmented WebVTT valid.
        if ($sourceChanged) {
            $updates['status'] = self::STATUS_PENDING;
            $updates['error'] = null;
            $updates['targetDuration'] = null;
            $updates['path'] = null;
        }

        $caption = $authorization->skip(fn () => $dbForProject->updateDocument('videosCaptions', $caption->getId(), new Document($updates)));

        // After the update, so a failed write cannot cost the video its
        // default track.
        if ($default === true) {
            $this->clearDefault($dbForProject, $authorization, $user, $video, $caption->getId());
        }

        if ($sourceChanged) {
            // The old segment row still serves the previous VTT, and this write
            // does not change its URL. Drop the row before the job and the purge:
            // a refetch would store those bytes again, and the worker replaces
            // this row, so a later delete would remove the new segment.
            $authorization->skip(fn () => $dbForProject->deleteDocuments('videosCaptionsSegments', [
                Query::equal('captionInternalId', [$caption->getSequence()]),
            ]));

            $publisherForVideos->enqueue(new VideoMessage(
                project: $project,
                action: VideoAction::Caption,
                video: $video,
                caption: $caption,
            ));
        }

        // Public caption segments share the video Surrogate-Key and the video's
        // updatedAt, so this write does not change their URL.
        $segmentCache->purge($project->getId(), $video->getId());

        $queueForEvents
            ->setParam('videoId', $video->getId())
            ->setParam('captionId', $caption->getId());

        $response->dynamic($caption, Response::MODEL_VIDEO_CAPTION);
    }

    /**
     * Only one track per video may be the default, so demote any current holder.
     */
    private function clearDefault(Database $dbForProject, Authorization $authorization, User $user, Document $video, string $captionId): void
    {
        $isAPIKey = $user->isKey($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

        // The video row is the lock: two requests promoting different tracks
        // at once would otherwise each demote the other and leave no default.
        // Under the lock the later request wins, and its own row is re-asserted
        // in case the earlier one already demoted it. Session callers take that
        // lock through document ACL. API keys and privileged users are not on
        // the document's permission list, so they skip it. Caption rows have no
        // permissions; their writes stay skipped after the parent was authorized.
        $dbForProject->withTransaction(function () use ($dbForProject, $authorization, $video, $captionId, $isAPIKey, $isPrivilegedUser): void {
            if ($isAPIKey || $isPrivilegedUser) {
                $locked = $authorization->skip(fn () => $dbForProject->getDocument('videos', $video->getId(), forUpdate: true));
            } else {
                $locked = $dbForProject->getDocument('videos', $video->getId(), forUpdate: true);
            }

            if ($locked->isEmpty()) {
                throw new Exception(Exception::VIDEO_NOT_FOUND);
            }

            $authorization->skip(function () use ($dbForProject, $video, $captionId): void {
                $existing = $dbForProject->find('videosCaptions', [
                    Query::equal('videoInternalId', [$video->getSequence()]),
                    Query::equal('default', [true]),
                    Query::limit(APP_LIMIT_SUBQUERY),
                ]);

                $promoted = false;
                foreach ($existing as $caption) {
                    if ($caption->getId() === $captionId) {
                        $promoted = true;
                        continue;
                    }

                    $dbForProject->updateDocument('videosCaptions', $caption->getId(), new Document(['default' => false]));
                }

                if (!$promoted) {
                    $dbForProject->updateDocument('videosCaptions', $captionId, new Document(['default' => true]));
                }
            });
        });
    }
}
