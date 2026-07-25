<?php

namespace Appwrite\Platform\Modules\Videos\Workers;

use Appwrite\Event\Message\Video as VideoMessage;
use Appwrite\Event\Message\VideoAction;
use Appwrite\Event\Realtime;
use Appwrite\Platform\Modules\Videos\Base;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\Storage\Device;

/**
 * Consumes the `videos` queue: sprite timelines, subtitle packaging and
 * rendition transcoding.
 *
 * The class name matches the module because this worker owns the module's
 * primary resource, as `Modules/Databases/Workers/Databases.php` does.
 *
 * ---------------------------------------------------------------------------
 * TODO(videos): the ffmpeg pipeline is not implemented.
 *
 * This worker is fully wired — it is registered on the `videos` queue, resolves
 * the project database and the videos storage device, hydrates its payload and
 * owns the document status transitions — but the media work itself is stubbed:
 * every job marks its target `error` with a `not_implemented` reason.
 *
 * Restoring it requires a decision on the encoding dependency. The pre-merge
 * implementation used `aminyazdanpanah/php-ffmpeg-video-streaming` pinned to
 * `dev-master` from a personal fork, with upstream unmaintained since 2021.
 * The two options are to vendor/fork that library under the appwrite org, or to
 * shell out to ffmpeg directly (which the sprite-timeline path already did).
 * The full previous implementation is preserved in git history at
 * `app/workers/videos.php` on the last commit before the main merge.
 *
 * What each action has to do:
 *
 *  - Timeline: probe the source with mediainfo, pick a sprite interval from the
 *    duration, tile frames with ffmpeg into sprite sheets, write one
 *    `videos_previews` row per sheet, and emit a WebVTT index. Store cue targets
 *    RELATIVE (`previews/{previewId}#xywh=...`); the old code baked an absolute
 *    host into the stored file, so the artifact broke whenever the host changed.
 *
 *  - Subtitle: fetch the subtitle file, convert SRT to WebVTT when needed, write
 *    `videos_subtitles_segments` rows, upload the VTT, then set targetDuration
 *    and status=ready. Note the old code only wrote the converted file for SRT
 *    input, then unconditionally uploaded that path — a `.vtt` upload read a
 *    file that was never written.
 *
 *  - Encode: transcode to the profile's dimensions/bitrates, package as HLS or
 *    DASH, parse the manifest into `videos_renditions_segments` rows, upload the
 *    output tree, and drive the rendition through
 *    started -> ended -> uploading -> ready, persisting `progress` as it goes.
 * ---------------------------------------------------------------------------
 */
class Videos extends Action
{
    /**
     * Must be exactly 'videos': app/worker.php derives the queue name
     * (`v1-videos`) and looks the action up by this key.
     */
    public static function getName(): string
    {
        return 'videos';
    }

    public function __construct()
    {
        $this
            ->desc('Videos worker')
            ->inject('message')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('deviceForFiles')
            ->inject('deviceForVideos')
            ->inject('queueForRealtime')
            ->inject('authorization')
            ->inject('log')
            ->callback($this->action(...));
    }

    public function action(
        Message $message,
        Document $project,
        Database $dbForProject,
        Device $deviceForFiles,
        Device $deviceForVideos,
        Realtime $queueForRealtime,
        Authorization $authorization,
        Log $log
    ): void {
        $payload = $message->getPayload();

        if (empty($payload)) {
            throw new \Exception('Missing payload');
        }

        $videoMessage = VideoMessage::fromArray($payload);
        $action = $videoMessage->action;

        Span::add('project.id', $project->getId());
        Span::add('video.id', $videoMessage->video->getId());
        Span::add('video.action', $action->value);

        $log->addTag('projectId', $project->getId());
        $log->addTag('videoId', $videoMessage->video->getId());
        $log->addTag('action', $action->value);

        match ($action) {
            VideoAction::Timeline => $this->timeline($dbForProject, $videoMessage),
            VideoAction::Subtitle => $this->subtitle($dbForProject, $videoMessage),
            VideoAction::Encode => $this->encode($dbForProject, $queueForRealtime, $project, $videoMessage),
        };
    }

    /**
     * TODO(videos): extract sprite sheets and emit the WebVTT timeline.
     */
    private function timeline(Database $dbForProject, VideoMessage $videoMessage): void
    {
        Console::warning(
            'Videos worker: timeline generation is not implemented; skipping video '
            . $videoMessage->video->getId()
        );
    }

    /**
     * TODO(videos): normalise the subtitle to WebVTT and segment it.
     */
    private function subtitle(Database $dbForProject, VideoMessage $videoMessage): void
    {
        $subtitle = $videoMessage->subtitle;

        if ($subtitle === null || $subtitle->isEmpty()) {
            throw new \Exception('Missing subtitle in payload');
        }

        Console::warning(
            'Videos worker: subtitle packaging is not implemented; marking subtitle '
            . $subtitle->getId() . ' as error'
        );

        $dbForProject->updateDocument(
            'videos_subtitles',
            $subtitle->getId(),
            $subtitle
                ->setAttribute('status', Base::STATUS_ERROR)
        );
    }

    /**
     * TODO(videos): transcode and package the rendition.
     *
     * The rendition row is created by the HTTP endpoint with status `waiting`,
     * so there is always a document to report failure on.
     */
    private function encode(
        Database $dbForProject,
        Realtime $queueForRealtime,
        Document $project,
        VideoMessage $videoMessage
    ): void {
        $rendition = $videoMessage->rendition;

        if ($rendition === null || $rendition->isEmpty()) {
            throw new \Exception('Missing rendition in payload');
        }

        Console::warning(
            'Videos worker: transcoding is not implemented; marking rendition '
            . $rendition->getId() . ' as error'
        );

        $rendition = $dbForProject->updateDocument(
            'videos_renditions',
            $rendition->getId(),
            $rendition
                ->setAttribute('status', Base::STATUS_ERROR)
                ->setAttribute('startedAt', DateTime::now())
                ->setAttribute('endedAt', DateTime::now())
                ->setAttribute('progress', '0')
                ->setAttribute('metadata', [
                    'code' => 'not_implemented',
                    'message' => 'Video transcoding is not available in this build.',
                ])
        );

        $this->notify($queueForRealtime, $project, $rendition, 'update');
    }

    /**
     * Publishes a rendition change on the project's realtime channels.
     *
     * Unlike the pre-merge worker, this does not hard-code the console project
     * or reach into the static Realtime adapter — it hands the event to the
     * injected queue, which resolves channels and roles from the event name and
     * the document's own permissions.
     */
    private function notify(
        Realtime $queueForRealtime,
        Document $project,
        Document $rendition,
        string $action
    ): void {
        $queueForRealtime
            ->setProject($project)
            ->setSubscribers(['console', $project->getId()])
            ->setEvent('videos.[videoId].renditions.[renditionId].' . $action)
            ->setParam('videoId', $rendition->getAttribute('videoId', ''))
            ->setParam('renditionId', $rendition->getId())
            ->setPayload($rendition->getArrayCopy())
            ->trigger();
    }
}
