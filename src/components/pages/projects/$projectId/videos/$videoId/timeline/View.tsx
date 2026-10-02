import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type KeyboardEvent,
} from 'react'
import { useParams } from '@tanstack/react-router'
import {
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  GalleryHorizontal,
  Loader2,
} from 'lucide-react'
import { toast } from 'sonner'
import {
  VideoActionButton,
  VideoPage,
  VideoSectionCard,
} from '../../_components/VideoPage'
import { VideoUrlRow } from '../../_components/VideoUrlRow'
import { useVideoDetailActions } from '../../_components/video-detail-actions'
import { TimelineSpriteThumb } from '../../_components/player/TimelineSpriteThumb'
import { CodeBlock } from '@/components/global/shared/CodeBlock'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Button } from '@/components/ui/button'
import { Slider } from '@/components/ui/slider'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  isVideoMetadataReady,
  useCreateVideoTimeline,
  useProjectVideo,
  useVideoTimeline,
  type VideoTimelineCue,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { formatPlaybackTime } from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'

function publicUrl(url: string): string {
  try {
    const parsed = new URL(url)
    parsed.searchParams.delete('mode')
    return parsed.toString()
  } catch {
    return url
  }
}

export function View() {
  const t = useT()
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId: string
  }
  const { writeDisabledReason } = useVideoDetailActions()
  const { data: video } = useProjectVideo(projectId, videoId)
  const [requested, setRequested] = useState(false)
  const timelineQuery = useVideoTimeline(projectId, videoId, {
    pollWhileMissing: requested,
  })
  const createTimeline = useCreateVideoTimeline(projectId, videoId)
  const timeline = timelineQuery.data

  useEffect(() => {
    if (timeline) setRequested(false)
  }, [timeline])

  const sheets = useMemo(
    () => new Set((timeline?.cues ?? []).map((cue) => cue.imageUrl)).size,
    [timeline?.cues],
  )
  const [chosenFrame, setChosenFrame] = useState<number | null>(null)
  const defaultFrame = useMemo(() => {
    const index = (timeline?.cues ?? []).findIndex(
      (cue) => previewIdFromCue(cue) === video?.previewId,
    )
    return Math.max(0, index)
  }, [timeline?.cues, video?.previewId])
  const selectedFrame = Math.min(
    chosenFrame ?? defaultFrame,
    Math.max(0, (timeline?.cues.length ?? 1) - 1),
  )
  const interval =
    timeline && timeline.cues.length > 0
      ? timeline.cues[0].end - timeline.cues[0].start
      : 0

  if (!video) return null

  // Probed audio-only files report a duration but no frame size; the worker produces no WebVTT for them.
  const audioOnly = video.duration > 0 && !isVideoMetadataReady(video)
  const generateDisabledReason =
    writeDisabledReason ??
    (audioOnly ? t('Audio-only videos have no frames for a timeline.') : null)
  const generating = requested || createTimeline.isPending

  const generate = () =>
    createTimeline.mutate(undefined, {
      onSuccess: () => {
        setRequested(true)
        toast.success(t('Timeline generation started'))
      },
      onError: (error) =>
        toast.error(getErrorMessage(error) || t('Failed to generate timeline')),
    })

  return (
    <VideoPage
      title={t('Timeline')}
      term="timeline"
      createLabel={timeline ? t('Regenerate timeline') : t('Generate timeline')}
      onCreate={generate}
      createDisabled={generating || Boolean(generateDisabledReason)}
      createDisabledTooltip={generateDisabledReason ?? undefined}
    >
      {timelineQuery.isLoading ? (
        <p className="text-[13px] text-muted-foreground">
          {t('Loading timeline...')}
        </p>
      ) : !timeline ? (
        generating ? (
          <div className="flex items-start gap-3 rounded-xl border border-border bg-card/50 px-5 py-4">
            <Loader2 className="mt-0.5 h-4 w-4 shrink-0 animate-spin text-muted-foreground" />
            <div>
              <p className="text-[14px] font-medium text-foreground">
                {t('Generating timeline')}
              </p>
              <p className="text-[13px] text-muted-foreground">
                {t(
                  'Sampling frames into sprite sheets. Thumbnails appear here when ready.',
                )}
              </p>
            </div>
          </div>
        ) : (
          <EmptyState
            icon={GalleryHorizontal}
            title={t('No timeline yet')}
            description={t(
              'Generate a timeline to get scrubbing thumbnails and a preview image for this video.',
            )}
            isEmpty
            hasFilters={false}
            variant="card"
            action={
              <VideoActionButton
                size="sm"
                className="h-9 text-[13px]"
                onClick={generate}
                disabledReason={generateDisabledReason}
              >
                {t('Generate timeline')}
              </VideoActionButton>
            }
          />
        )
      ) : (
        <>
          <VideoSectionCard
            title={t('Thumbnails')}
            term="sprite"
            description={t(
              'Scroll through the thumbnails and hover one to see its time range and sprite region. Select one to use it as the preview image.',
            )}
          >
            <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-4">
              {[
                { label: t('Thumbnails'), value: String(timeline.cues.length) },
                { label: t('Sprite sheets'), value: String(sheets) },
                {
                  label: t('Interval'),
                  value: interval > 0 ? `${interval.toFixed(1)}s` : '-',
                },
                {
                  label: t('Thumbnail size'),
                  value: timeline.cues[0]
                    ? `${timeline.cues[0].width}×${timeline.cues[0].height}`
                    : '-',
                },
              ].map((stat) => (
                <div key={stat.label} className="bg-card px-4 py-3">
                  <dt className="text-[12px] text-muted-foreground">
                    {stat.label}
                  </dt>
                  <dd className="mt-0.5 text-[14px] font-semibold tabular-nums text-foreground">
                    {stat.value}
                  </dd>
                </div>
              ))}
            </dl>

            <ThumbnailStrip
              cues={timeline.cues}
              selectedIndex={selectedFrame}
              onSelect={setChosenFrame}
            />
          </VideoSectionCard>

          <PreviewImageCard
            cues={timeline.cues}
            selectedIndex={selectedFrame}
            onSelect={setChosenFrame}
          />

          <VideoSectionCard
            title={t('Timeline file')}
            term="timeline"
            description={t(
              'A WebVTT file that maps time ranges to sprite sheet regions. Point your player thumbnail option at this URL.',
            )}
            bodyClassName="space-y-3"
          >
            <div className="divide-y divide-border">
              <VideoUrlRow
                label={t('Timeline URL')}
                url={publicUrl(timeline.url)}
              />
            </div>
            <TimelineFilePreview vtt={timeline.vtt} />
          </VideoSectionCard>
        </>
      )}

      {timeline ? null : (
        <PreviewImageCard cues={null} selectedIndex={0} onSelect={() => {}} />
      )}
    </VideoPage>
  )
}

const STRIP_MAX_WIDTH = 280
const STRIP_MAX_HEIGHT = 240

const STRIP_GAP = 8

function stripBaseWidth(cue: VideoTimelineCue | undefined): number {
  if (!cue || cue.width <= 0 || cue.height <= 0) return STRIP_MAX_WIDTH
  return Math.min(STRIP_MAX_WIDTH, STRIP_MAX_HEIGHT * (cue.width / cue.height))
}

/** Stretches thumbnails so a whole number of them fills the strip edge to edge. */
function fitStrip(containerWidth: number, baseWidth: number) {
  if (containerWidth <= 0) return { perPage: 1, width: baseWidth }
  const perPage = Math.max(
    1,
    Math.floor((containerWidth + STRIP_GAP) / (baseWidth + STRIP_GAP)),
  )
  return {
    perPage,
    width: (containerWidth - STRIP_GAP * (perPage - 1)) / perPage,
  }
}

function ThumbnailStrip({
  cues,
  selectedIndex,
  onSelect,
}: {
  cues: VideoTimelineCue[]
  selectedIndex: number
  onSelect: (index: number) => void
}) {
  const t = useT()
  const scrollerRef = useRef<HTMLDivElement | null>(null)
  const [canScrollStart, setCanScrollStart] = useState(false)
  const [canScrollEnd, setCanScrollEnd] = useState(false)
  const [containerWidth, setContainerWidth] = useState(0)
  const { perPage, width: thumbWidth } = fitStrip(
    containerWidth,
    stripBaseWidth(cues[0]),
  )

  const updateEdges = useCallback(() => {
    const el = scrollerRef.current
    if (!el) return
    setContainerWidth(el.clientWidth)
    // RTL scrollLeft runs from 0 toward negative values.
    const offset = Math.abs(el.scrollLeft)
    setCanScrollStart(offset > 1)
    setCanScrollEnd(offset + el.clientWidth < el.scrollWidth - 1)
  }, [])

  useEffect(() => {
    const el = scrollerRef.current
    if (!el) return
    updateEdges()
    const observer = new ResizeObserver(updateEdges)
    observer.observe(el)
    return () => observer.disconnect()
  }, [updateEdges, cues.length])

  useEffect(() => {
    const el = scrollerRef.current
    const thumb = el?.querySelector<HTMLElement>(
      `[data-cue-index="${selectedIndex}"]`,
    )
    if (!el || !thumb) return
    const view = el.getBoundingClientRect()
    const rect = thumb.getBoundingClientRect()
    const delta =
      rect.left < view.left
        ? rect.left - view.left
        : rect.right > view.right
          ? rect.right - view.right
          : 0
    if (delta !== 0) el.scrollBy({ left: delta, behavior: 'smooth' })
  }, [selectedIndex])

  const scrollByPage = (direction: 1 | -1) => {
    const el = scrollerRef.current
    if (!el) return
    const rtl = getComputedStyle(el).direction === 'rtl'
    el.scrollBy({
      left: direction * (rtl ? -1 : 1) * perPage * (thumbWidth + STRIP_GAP),
      behavior: 'smooth',
    })
  }

  const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return
    const rtl = getComputedStyle(event.currentTarget).direction === 'rtl'
    const forward = (event.key === 'ArrowRight') !== rtl
    const current = Number(
      (document.activeElement as HTMLElement | null)?.dataset.cueIndex ?? -1,
    )
    const next = Math.min(
      cues.length - 1,
      Math.max(0, current + (forward ? 1 : -1)),
    )
    if (next === current) return
    event.preventDefault()
    const target = event.currentTarget.querySelector<HTMLElement>(
      `[data-cue-index="${next}"]`,
    )
    target?.focus({ preventScroll: true })
    target?.scrollIntoView({
      behavior: 'smooth',
      block: 'nearest',
      inline: 'nearest',
    })
  }

  return (
    <div className="relative mt-3">
      <div
        ref={scrollerRef}
        onScroll={updateEdges}
        onKeyDown={handleKeyDown}
        className="flex snap-x snap-mandatory overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        style={{ gap: STRIP_GAP }}
      >
        {cues.map((cue, index) => (
          <Tooltip key={`${cue.imageUrl}-${cue.start}`}>
            <TooltipTrigger asChild>
              <button
                type="button"
                data-cue-index={index}
                aria-label={formatPlaybackTime(cue.start)}
                aria-pressed={index === selectedIndex}
                onClick={() => onSelect(index)}
                className="group relative shrink-0 snap-start overflow-hidden rounded-md focus-visible:outline-none"
              >
                <TimelineSpriteThumb
                  cue={cue}
                  width={thumbWidth}
                  className="transition-opacity group-hover:opacity-90"
                />
                <span
                  aria-hidden
                  className={cn(
                    'pointer-events-none absolute inset-0 rounded-md ring-inset transition group-focus-visible:ring-2 group-focus-visible:ring-ring',
                    index === selectedIndex
                      ? 'ring-2 ring-foreground'
                      : 'ring-1 ring-border group-hover:ring-foreground/40',
                  )}
                />
                <span className="pointer-events-none absolute bottom-1 start-1 rounded bg-black/70 px-1 py-0.5 font-mono text-[10px] tabular-nums text-white">
                  {formatPlaybackTime(cue.start)}
                </span>
              </button>
            </TooltipTrigger>
            <TooltipContent sideOffset={6} className="text-[12px]">
              <dl className="space-y-1.5">
                <div>
                  <dt className="opacity-70">{t('Time range')}</dt>
                  <dd className="font-medium tabular-nums">
                    {formatPlaybackTime(cue.start)} -{' '}
                    {formatPlaybackTime(cue.end)}
                  </dd>
                </div>
                <div>
                  <dt className="opacity-70">{t('Sprite region')}</dt>
                  <dd className="font-mono">
                    #xywh={cue.x},{cue.y},{cue.width},{cue.height}
                  </dd>
                </div>
              </dl>
            </TooltipContent>
          </Tooltip>
        ))}
      </div>
      {canScrollStart ? (
        <StripArrow
          side="start"
          label={t('Previous')}
          onClick={() => scrollByPage(-1)}
        />
      ) : null}
      {canScrollEnd ? (
        <StripArrow
          side="end"
          label={t('Next')}
          onClick={() => scrollByPage(1)}
        />
      ) : null}
    </div>
  )
}

function StripArrow({
  side,
  label,
  onClick,
}: {
  side: 'start' | 'end'
  label: string
  onClick: () => void
}) {
  const Icon = side === 'start' ? ChevronLeft : ChevronRight
  return (
    <Button
      type="button"
      variant="outline"
      size="icon"
      aria-label={label}
      onClick={onClick}
      className={cn(
        'absolute top-1/2 h-8 w-8 -translate-y-1/2 rounded-full bg-background/90 shadow-md backdrop-blur',
        side === 'start' ? 'start-2' : 'end-2',
      )}
    >
      <Icon className="h-4 w-4 rtl:rotate-180" />
    </Button>
  )
}

const TIMELINE_PREVIEW_LINES = 10

function TimelineFilePreview({ vtt }: { vtt: string }) {
  const t = useT()
  const [expanded, setExpanded] = useState(false)
  const lines = useMemo(() => vtt.replace(/\s+$/, '').split('\n'), [vtt])
  const collapsible = lines.length > TIMELINE_PREVIEW_LINES
  const truncated = collapsible && !expanded
  const visible = truncated ? lines.slice(0, TIMELINE_PREVIEW_LINES) : lines

  return (
    <div className="space-y-2">
      <div
        className={cn(
          truncated &&
            '[mask-image:linear-gradient(to_bottom,black_30%,transparent_95%)]',
        )}
      >
        <CodeBlock
          code={visible.join('\n')}
          language="plaintext"
          showCopy={!truncated}
          copyInside
        />
      </div>
      {collapsible ? (
        <div className="flex justify-center">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 gap-1.5 text-[12px]"
            onClick={() => setExpanded((value) => !value)}
            aria-expanded={expanded}
          >
            <ChevronDown
              className={cn(
                'h-3.5 w-3.5 transition-transform',
                expanded && 'rotate-180',
              )}
            />
            {expanded ? t('Show less') : t('Show more')}
            {expanded ? null : (
              <span className="tabular-nums text-muted-foreground/70">
                ({lines.length - TIMELINE_PREVIEW_LINES})
              </span>
            )}
          </Button>
        </div>
      ) : null}
    </div>
  )
}

function previewIdFromCue(cue: VideoTimelineCue): string | null {
  try {
    const segments = new URL(cue.imageUrl).pathname.split('/')
    const index = segments.lastIndexOf('previews')
    return index >= 0 ? (segments[index + 1] ?? null) || null : null
  } catch {
    return null
  }
}

const PREVIEW_SAVE_UNAVAILABLE =
  "Saving a preview frame isn't available yet. The Videos API can't set a video's poster frame."

function PreviewImageCard({
  cues,
  selectedIndex,
  onSelect,
}: {
  cues: VideoTimelineCue[] | null
  selectedIndex: number
  onSelect: (index: number) => void
}) {
  const t = useT()
  const cue = cues?.[selectedIndex]

  return (
    <VideoSectionCard
      title={t('Preview image')}
      term="preview"
      description={t(
        'The frame shown before playback starts. Select a thumbnail above or scrub through the video to choose it.',
      )}
    >
      {!cues || !cue ? (
        <p className="text-[13px] text-muted-foreground">
          {t('Generate a timeline to choose a preview image.')}
        </p>
      ) : (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_280px]">
          <div className="overflow-hidden rounded-lg border border-border bg-muted">
            <TimelineSpriteThumb cue={cue} className="w-full" />
          </div>
          <div className="flex flex-col gap-4">
            <div>
              <p className="text-[12px] text-muted-foreground">
                {t('Selected frame')}
              </p>
              <p className="mt-0.5 font-mono text-[20px] font-semibold tabular-nums text-foreground">
                {formatPlaybackTime(cue.start)}
              </p>
              <p className="text-[12px] tabular-nums text-muted-foreground">
                {selectedIndex + 1} / {cues.length}
              </p>
            </div>
            <div className="flex items-center gap-2">
              <Button
                type="button"
                variant="outline"
                size="icon"
                className="h-8 w-8 shrink-0"
                aria-label={t('Previous frame')}
                disabled={selectedIndex === 0}
                onClick={() => onSelect(selectedIndex - 1)}
              >
                <ChevronLeft className="h-4 w-4 rtl:rotate-180" />
              </Button>
              <Slider
                aria-label={t('Preview frame')}
                min={0}
                max={Math.max(0, cues.length - 1)}
                step={1}
                value={[selectedIndex]}
                onValueChange={([value]) => onSelect(value ?? 0)}
                className="flex-1"
              />
              <Button
                type="button"
                variant="outline"
                size="icon"
                className="h-8 w-8 shrink-0"
                aria-label={t('Next frame')}
                disabled={selectedIndex >= cues.length - 1}
                onClick={() => onSelect(selectedIndex + 1)}
              >
                <ChevronRight className="h-4 w-4 rtl:rotate-180" />
              </Button>
            </div>
            <div className="mt-auto">
              <VideoActionButton
                size="sm"
                className="h-9 w-full text-[13px]"
                disabledReason={PREVIEW_SAVE_UNAVAILABLE}
              >
                {t('Set as preview')}
              </VideoActionButton>
            </div>
          </div>
        </div>
      )}
    </VideoSectionCard>
  )
}
