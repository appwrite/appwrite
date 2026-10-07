import type { ReactNode } from 'react'
import { Link, useParams } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { ChevronDown, Info } from 'lucide-react'
import { VideoFact, VideoPage } from '../_components/VideoPage'
import { VideoPreviewEmbedMenu } from '../_components/VideoPreviewEmbedMenu'
import { VideoStreamPlayer } from '../_components/player/VideoStreamPlayer'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { sdk } from '@/lib/appwrite/sdk'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  isVideoMetadataReady,
  useProjectVideo,
  useVideoRenditions,
  videoSourceHasAudio,
} from '@/lib/react-query/hooks'
import {
  formatBitrate,
  formatResolution,
} from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'

export type VideoOverviewInitialData = {
  video: Models.Video
  renditions: Models.VideoRenditionList
}

type ViewProps = {
  initialData?: VideoOverviewInitialData
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId: string
  }
  const { data: videoFromHook } = useProjectVideo(projectId, videoId)
  const { data: renditionsFromHook } = useVideoRenditions(projectId, videoId)

  const video = videoFromHook ?? initialData?.video
  const renditions =
    renditionsFromHook?.renditions ?? initialData?.renditions.renditions ?? []

  if (!video) return null

  return (
    <VideoPage
      title={t('Overview')}
      showVideoId
      titleActions={
        <div className="flex items-center gap-2">
          <VideoPreviewEmbedMenu
            variant="toolbar"
            projectId={projectId}
            video={video}
            renditions={renditions}
          />
          <SourcePopover projectId={projectId} video={video} />
        </div>
      }
    >
      <VideoStreamPlayer
        projectId={projectId}
        video={video}
        renditions={renditions}
        variant="featured"
      />
    </VideoPage>
  )
}

function SourcePopover({
  projectId,
  video,
}: {
  projectId: string
  video: Models.Video
}) {
  const t = useT()
  const hasSource = Boolean(video.bucketId && video.fileId)
  const storage = sdk.forProject(projectId).storage
  const viewUrl = hasSource
    ? withAdminMode(
        storage.getFileView({
          bucketId: video.bucketId,
          fileId: video.fileId,
        }),
      )
    : null
  const downloadUrl = hasSource
    ? withAdminMode(
        storage.getFileDownload({
          bucketId: video.bucketId,
          fileId: video.fileId,
        }),
      )
    : null

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="outline" size="sm" className="h-9 gap-1.5 text-[13px]">
          <Info className="h-3.5 w-3.5" />
          {t('Source')}
          <ChevronDown className="h-3.5 w-3.5 text-muted-foreground" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align="end"
        className="w-[min(92dvw,640px)] p-0"
        onOpenAutoFocus={(event) => event.preventDefault()}
      >
        <div className="px-5 py-4">
          <h3 className="text-[14px] font-semibold text-foreground">
            {t('Source details')}
          </h3>
          <p className="mt-1 text-[12px] text-muted-foreground">
            {isVideoMetadataReady(video)
              ? t('Read from the file during the first encoding job.')
              : t(
                  'Metadata appears once the first rendition or timeline job reads the file.',
                )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="grid max-h-[min(70dvh,560px)] gap-5 overflow-y-auto px-5 py-4 sm:grid-cols-3">
          <FactGroup title={t('File')}>
            <VideoFact
              label={t('Container')}
              term="container"
              value={video.format}
            />
            <VideoFact
              label={t('Storage file')}
              value={
                <Link
                  to="/projects/$projectId/storage/$bucketId"
                  params={{ projectId, bucketId: video.bucketId }}
                  className="break-all hover:underline"
                >
                  {video.fileId}
                </Link>
              }
              mono
              breakAll
            />
            <VideoFact
              label={t('Bucket ID')}
              value={video.bucketId}
              mono
              breakAll
            />
            <VideoFact
              label={t('Created')}
              value={
                <DateTooltip
                  date={video.$createdAt}
                  className="text-[13px] font-normal text-foreground"
                />
              }
            />
            <VideoFact
              label={t('Updated')}
              value={
                <DateTooltip
                  date={video.$updatedAt}
                  className="text-[13px] font-normal text-foreground"
                />
              }
            />
          </FactGroup>
          <FactGroup title={t('Video stream')}>
            <VideoFact
              label={t('Resolution')}
              value={
                video.width > 0
                  ? formatResolution(video.width, video.height)
                  : ''
              }
            />
            <VideoFact label={t('Aspect ratio')} value={video.aspectRatio} />
            <VideoFact
              label={t('Codec')}
              term="codec"
              value={[video.videoCodec, video.videoFormatProfile]
                .filter(Boolean)
                .join(' · ')}
            />
            <VideoFact
              label={t('Bitrate')}
              term="bitrate"
              value={
                video.videoBitRate > 0 ? formatBitrate(video.videoBitRate) : ''
              }
            />
            <VideoFact
              label={t('Frame rate')}
              value={
                video.videoFrameRate
                  ? [`${video.videoFrameRate} fps`, video.videoFrameRateMode]
                      .filter(Boolean)
                      .join(' · ')
                  : ''
              }
            />
          </FactGroup>
          <FactGroup title={t('Audio stream')}>
            <VideoFact
              label={t('Codec')}
              term="codec"
              value={[video.audioCodec, video.audioFormat]
                .filter(Boolean)
                .join(' · ')}
            />
            <VideoFact
              label={t('Bitrate')}
              term="bitrate"
              value={
                video.audioBitRate > 0 ? formatBitrate(video.audioBitRate) : ''
              }
            />
            <VideoFact
              label={t('Sample rate')}
              value={video.audioSampleRate ? `${video.audioSampleRate} Hz` : ''}
            />
            {isVideoMetadataReady(video) && !videoSourceHasAudio(video) ? (
              <p className="mt-3 text-[12px] leading-relaxed text-muted-foreground">
                {t(
                  'No audio track was found when the file was probed. Encoding profiles cannot add audio. Replace the storage file with one that includes audio and create a new video, or confirm the original plays with sound on your device.',
                )}
              </p>
            ) : null}
          </FactGroup>
        </div>
        <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-5 py-3 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            disabled={!viewUrl}
            asChild={!!viewUrl}
          >
            {viewUrl ? (
              <a href={viewUrl} target="_blank" rel="noopener noreferrer">
                {t('View')}
              </a>
            ) : (
              t('View')
            )}
          </Button>
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            disabled={!downloadUrl}
            asChild={!!downloadUrl}
          >
            {downloadUrl ? (
              <a href={downloadUrl} target="_blank" rel="noopener noreferrer">
                {t('Download')}
              </a>
            ) : (
              t('Download')
            )}
          </Button>
        </div>
      </PopoverContent>
    </Popover>
  )
}

function FactGroup({
  title,
  children,
}: {
  title: string
  children: ReactNode
}) {
  return (
    <div>
      <h4 className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
        {title}
      </h4>
      <dl className="mt-3 space-y-3">{children}</dl>
    </div>
  )
}

