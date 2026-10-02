import type { ReactNode } from 'react'
import { Link, useParams } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { Check, ChevronDown, Circle, Info } from 'lucide-react'
import {
  VideoActionButton,
  VideoFact,
  VideoPage,
  VideoSectionCard,
} from '../_components/VideoPage'
import { VideoTermHint } from '../_components/VideoTermHint'
import { useVideoDetailActions } from '../_components/video-detail-actions'
import { VIDEO_TAB_NEEDS_READY_RENDITION } from '../_components/video-tabs'
import { VideoStreamPlayer } from '../_components/player/VideoStreamPlayer'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
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
  useVideoSubtitles,
  videoSourceHasAudio,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { formatBytes } from '@/lib/utils/mock-data'
import {
  formatBitrate,
  formatResolution,
  formatVideoDuration,
} from '@/lib/utils/video-format'
import type { VideoGlossaryTerm } from '@/lib/videos/glossary'
import { useT } from '@/lib/i18n/translate'

export type VideoOverviewInitialData = {
  video: Models.Video
  renditions: Models.VideoRenditionList
  subtitles: Models.VideoSubtitleList
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
  const { data: subtitlesFromHook } = useVideoSubtitles(projectId, videoId)

  const video = videoFromHook ?? initialData?.video
  const renditions =
    renditionsFromHook?.renditions ?? initialData?.renditions.renditions ?? []
  const subtitles =
    subtitlesFromHook?.subtitles ?? initialData?.subtitles.subtitles ?? []

  if (!video) return null

  const hasRenditions = renditions.length > 0

  return (
    <VideoPage
      title={t('Overview')}
      showVideoId
      toolbar={
        <>
          <MetricChip
            label={t('Resolution')}
            value={formatResolutionAndAspectRatio(video)}
          />
          <MetricChip
            label={t('Duration')}
            value={formatVideoDuration(video.duration)}
            className="hidden @[560px]:flex"
          />
          <MetricChip
            label={t('Source size')}
            value={video.size > 0 ? formatBytes(video.size) : '-'}
            className="hidden @[720px]:flex"
          />
        </>
      }
      actions={<SourcePopover projectId={projectId} video={video} />}
      contentClassName={
        hasRenditions
          ? undefined
          : 'flex min-h-full flex-col items-center justify-center'
      }
    >
      {hasRenditions ? null : (
        <div className="w-full max-w-xl">
          <SetupChecklist
            projectId={projectId}
            video={video}
            renditions={renditions}
            subtitles={subtitles}
          />
        </div>
      )}
      {hasRenditions ? (
        <>
          <SetupChecklist
            projectId={projectId}
            video={video}
            renditions={renditions}
            subtitles={subtitles}
          />
          <VideoStreamPlayer
            projectId={projectId}
            video={video}
            renditions={renditions}
            showDebugTools={false}
            showProcessing={false}
            variant="featured"
          />
        </>
      ) : null}
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

type DetailPath =
  | '/projects/$projectId/videos/$videoId/renditions'
  | '/projects/$projectId/videos/$videoId/subtitles'
  | '/projects/$projectId/videos/$videoId/timeline'
  | '/projects/$projectId/videos/$videoId/streaming'

function formatResolutionAndAspectRatio(video: Models.Video): string {
  const resolution =
    video.width > 0 ? formatResolution(video.width, video.height) : ''
  const ratio = video.aspectRatio?.trim() ?? ''
  if (resolution && ratio) return `${resolution} · ${ratio}`
  return resolution || ratio || '-'
}

function MetricChip({
  label,
  term,
  value,
  to,
  projectId,
  videoId,
  className,
}: {
  label: string
  term?: VideoGlossaryTerm
  value: string
  to?: DetailPath
  projectId?: string
  videoId?: string
  className?: string
}) {
  const body = (
    <>
      <span className="flex items-center gap-1 text-muted-foreground">
        {label}
        {term ? <VideoTermHint term={term} /> : null}
      </span>
      <span className="font-semibold tabular-nums text-foreground">
        {value}
      </span>
    </>
  )
  const chipClassName = cn(
    'flex h-9 items-center gap-2 whitespace-nowrap rounded-md border border-border bg-card/50 px-3 text-[13px]',
    className,
  )
  if (to && projectId && videoId) {
    return (
      <Link
        to={to}
        params={{ projectId, videoId }}
        className={cn(chipClassName, 'transition-colors hover:bg-muted/40')}
      >
        {body}
      </Link>
    )
  }
  return <div className={chipClassName}>{body}</div>
}

type ChecklistStep = {
  id: string
  title: string
  description: string
  term?: VideoGlossaryTerm
  done: boolean
  optional?: boolean
  /** Waiting on an earlier step; shown muted. */
  blocked?: boolean
  action?: ReactNode
}

/** Guides first-time users through the order the API expects. Hidden once streaming works. */
function SetupChecklist({
  projectId,
  video,
  renditions,
  subtitles,
}: {
  projectId: string
  video: Models.Video
  renditions: Models.VideoRendition[]
  subtitles: Models.VideoSubtitle[]
}) {
  const t = useT()
  const { openCreateRenditions, openCreateSubtitle, writeDisabledReason } =
    useVideoDetailActions()

  const hasReady = renditions.some((r) => r.status === 'ready')
  if (hasReady) return null

  const linkButton = (to: DetailPath, label: string) => (
    <Button variant="outline" size="sm" className="h-8 text-[12px]" asChild>
      <Link to={to} params={{ projectId, videoId: video.$id }}>
        {label}
      </Link>
    </Button>
  )

  const steps: ChecklistStep[] = [
    {
      id: 'renditions',
      title: t('Encode renditions'),
      description: t('Pick profiles and an output format such as HLS.'),
      term: 'rendition',
      done: renditions.length > 0,
      action: (
        <VideoActionButton
          size="sm"
          className="h-8 text-[12px]"
          onClick={openCreateRenditions}
          disabledReason={writeDisabledReason}
        >
          {t('Create renditions')}
        </VideoActionButton>
      ),
    },
    {
      id: 'subtitles',
      title: t('Add subtitles'),
      description: t('Upload WebVTT or SRT files per language.'),
      optional: true,
      done: subtitles.length > 0,
      action: (
        <VideoActionButton
          size="sm"
          variant="outline"
          className="h-8 text-[12px]"
          onClick={openCreateSubtitle}
          disabledReason={writeDisabledReason}
        >
          {t('Add subtitle')}
        </VideoActionButton>
      ),
    },
    {
      id: 'timeline',
      title: t('Generate a timeline'),
      description: t('Thumbnails for scrubbing and poster images.'),
      term: 'timeline',
      optional: true,
      done: Boolean(video.previewId),
      action: linkButton(
        '/projects/$projectId/videos/$videoId/timeline',
        t('Open timeline'),
      ),
    },
    {
      id: 'stream',
      title: t('Stream it'),
      description: t('Copy a manifest URL into your player.'),
      term: 'manifest',
      done: hasReady,
      blocked: true,
      action: (
        <VideoActionButton
          variant="outline"
          size="sm"
          className="h-8 text-[12px]"
          disabledReason={VIDEO_TAB_NEEDS_READY_RENDITION}
        >
          {t('View streaming URLs')}
        </VideoActionButton>
      ),
    },
  ]
  const required = steps.filter((step) => !step.optional)
  const doneCount = required.filter((step) => step.done).length
  const nextId = steps.find((step) => !step.done && !step.optional)?.id

  return (
    <VideoSectionCard
      title={t('Get streaming')}
      description={t('{done} of {total} required steps done')
        .replace('{done}', String(doneCount))
        .replace('{total}', String(required.length))}
      bodyClassName="px-0 py-0"
    >
      <ol className="divide-y divide-border">
        {steps.map((step) => {
          const isNext = step.id === nextId
          return (
            <li
              key={step.id}
              className={cn(
                'flex flex-col gap-3 px-6 py-3 sm:flex-row sm:items-center',
                isNext && 'bg-muted/30',
                step.blocked && !step.done && 'opacity-60',
              )}
            >
              <div className="flex min-w-0 flex-1 items-start gap-3">
                {step.done ? (
                  <span className="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white">
                    <Check className="h-3 w-3" />
                  </span>
                ) : (
                  <Circle className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground/60" />
                )}
                <div className="min-w-0">
                  <p
                    className={cn(
                      'flex items-center gap-1.5 text-[13px] font-medium text-foreground',
                      step.done && 'text-muted-foreground line-through',
                    )}
                  >
                    {step.title}
                    {step.optional ? (
                      <span className="text-[11px] font-normal text-muted-foreground no-underline">
                        {t('Optional')}
                      </span>
                    ) : null}
                    {step.term ? <VideoTermHint term={step.term} /> : null}
                  </p>
                  <p className="text-[12px] text-muted-foreground">
                    {step.description}
                  </p>
                </div>
              </div>
              {!step.done && step.action ? (
                <div className="shrink-0 ps-7 sm:ps-0">{step.action}</div>
              ) : null}
            </li>
          )
        })}
      </ol>
    </VideoSectionCard>
  )
}
