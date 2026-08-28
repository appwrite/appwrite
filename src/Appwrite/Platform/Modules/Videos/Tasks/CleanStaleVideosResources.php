<?php

namespace Appwrite\Platform\Modules\Videos\Tasks;

use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Videos\Base;
use DateInterval;
use DateTime;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

/**
 * Aborts stuck video source downloads and encoding renditions.
 *
 * After abort, clients may POST /videos/:id/source or /videos/:id/renditions
 * again to re-queue the job.
 */
class CleanStaleVideosResources extends Action
{
    public static function getName(): string
    {
        return 'clean-stale-videos-resources';
    }

    public function __construct()
    {
        $this
            ->desc('Abort stale video downloads and encoding renditions')
            ->param('type', 'loop', new WhiteList(['loop', 'trigger']), 'How to run task. "loop" is meant for container entrypoint, and "trigger" for manual execution.')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->callback($this->action(...));
    }

    public function action(string $type, Database $dbForPlatform, callable $getProjectDB): void
    {
        Console::title('Clean Stale Videos Resources');
        Console::success(APP_NAME . ' clean-stale-videos-resources process has started');

        $interval = \max(1, (int) System::getEnv('_APP_VIDEOS_STUCK_SWEEP_INTERVAL', '300'));

        $run = function () use ($dbForPlatform, $getProjectDB): void {
            $this->sweep($dbForPlatform, $getProjectDB);
        };

        if ($type === 'loop') {
            Console::loop($run, $interval);
        } elseif ($type === 'trigger') {
            $run();
        }
    }

    /**
     * One sweep across active projects. Public so Interval can reuse it.
     */
    public function sweep(Database $dbForPlatform, callable $getProjectDB): void
    {
        $time = DatabaseDateTime::now();
        $downloadRetention = \max(1, (int) System::getEnv('_APP_VIDEOS_DOWNLOAD_STUCK_RETENTION', '300'));
        $encodeRetention = \max(1, (int) System::getEnv('_APP_VIDEOS_ENCODE_STUCK_RETENTION', '1800'));
        $downloadCutoff = DatabaseDateTime::addSeconds(new DateTime(), -1 * $downloadRetention);
        $encodeCutoff = DatabaseDateTime::addSeconds(new DateTime(), -1 * $encodeRetention);
        $downloadCutoffDt = new DateTime($downloadCutoff);
        $encodeCutoffDt = new DateTime($encodeCutoff);

        Console::info("[{$time}] Sweeping stale video resources (download grace {$downloadRetention}s, encode grace {$encodeRetention}s)");

        $before30days = (new DateTime())->sub(DateInterval::createFromDateString('30 days'));

        $this->foreachDocument(
            $dbForPlatform,
            'projects',
            [
                Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
                Query::greaterThanEqual('accessedAt', DatabaseDateTime::format($before30days)),
                Query::orderAsc('teamInternalId'),
            ],
            function (Document $project) use ($getProjectDB, $downloadCutoff, $encodeCutoff, $downloadCutoffDt, $encodeCutoffDt): void {
                if ($project->getId() === 'console') {
                    return;
                }

                try {
                    /** @var Database $dbForProject */
                    $dbForProject = $getProjectDB($project);
                    $this->abortStaleDownloads($dbForProject, $project, $downloadCutoff, $downloadCutoffDt);
                    $this->abortStaleEncodes($dbForProject, $project, $encodeCutoff, $encodeCutoffDt);
                } catch (\Throwable $th) {
                    Console::error(
                        "Failed sweeping project {$project->getId()}: " . $th->getMessage()
                    );
                }
            }
        );
    }

    private function abortStaleDownloads(
        Database $dbForProject,
        Document $project,
        string $cutoff,
        DateTime $cutoffDt
    ): void {
        $this->foreachDocument(
            $dbForProject,
            'videos',
            [
                Query::select(['$id', '$updatedAt', 'status', 'chunksTotal', 'chunksUploaded']),
                Query::equal('status', Base::STALE_SOURCE_STATUSES),
                Query::lessThan('$updatedAt', $cutoff),
            ],
            function (Document $video) use ($dbForProject, $project, $cutoffDt): void {
                try {
                    if (!Base::shouldAbortStaleDownload($video, $cutoffDt)) {
                        return;
                    }

                    $dbForProject->updateDocument('videos', $video->getId(), new Document([
                        'status' => Base::SOURCE_ABORTED,
                    ]));
                    Base::releaseTmpSource($project->getId(), $video->getId());

                    Console::info(
                        "Aborted stale download for video {$video->getId()} in project {$project->getId()}"
                    );
                } catch (\Throwable $th) {
                    Console::error(
                        "Failed aborting download {$video->getId()} for project {$project->getId()}: "
                        . $th->getMessage()
                    );
                }
            }
        );
    }

    private function abortStaleEncodes(
        Database $dbForProject,
        Document $project,
        string $cutoff,
        DateTime $cutoffDt
    ): void {
        $this->foreachDocument(
            $dbForProject,
            'videos_renditions',
            [
                Query::select(['$id', '$updatedAt', 'status', 'progress', 'videoId', 'endedAt']),
                Query::equal('status', Base::STALE_ENCODE_STATUSES),
                Query::lessThan('$updatedAt', $cutoff),
            ],
            function (Document $rendition) use ($dbForProject, $project, $cutoffDt): void {
                try {
                    if (!Base::shouldAbortStaleEncode($rendition, $cutoffDt)) {
                        return;
                    }

                    $data = ['status' => Base::STATUS_ABORTED];
                    if (empty($rendition->getAttribute('endedAt'))) {
                        $data['endedAt'] = DatabaseDateTime::now();
                    }

                    $dbForProject->updateDocument(
                        'videos_renditions',
                        $rendition->getId(),
                        new Document($data)
                    );

                    $videoId = (string) $rendition->getAttribute('videoId', '');
                    if ($videoId !== '') {
                        Base::releaseTmpJobs($project->getId(), $videoId);
                    }

                    Console::info(
                        "Aborted stale encode for rendition {$rendition->getId()} in project {$project->getId()}"
                    );
                } catch (\Throwable $th) {
                    Console::error(
                        "Failed aborting encode {$rendition->getId()} for project {$project->getId()}: "
                        . $th->getMessage()
                    );
                }
            }
        );
    }
}
