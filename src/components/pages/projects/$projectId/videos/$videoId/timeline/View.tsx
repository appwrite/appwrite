import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type KeyboardEvent,
} from 'react'
import { useParams } from '@tanstack/react-router'
import { ImageFormat } from '@appwrite.io/console'
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
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { sdk } from '@/lib/appwrite/sdk'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
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

const PREVIEW_FORMATS: ImageFormat[] = [
  ImageFormat.Webp,
  ImageFormat.Jpeg,
  ImageFormat.Png,
  ImageFormat.Avif,
  ImageFormat.Gif,
]

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
  const [selected, setSelected] = useState<VideoTimelineCue | null>(null)

  useEffect(() => {
    if (timeline) setRequested(false)
  }, [timeline])

  const sheets = useMemo(
    () => new Set((timeline?.cues ?? []).map((cue) => cue.imageUrl)).size,
    [timeline?.cues],
  )
  const previewIds = useMemo(() => {
    const ids = new Set<string>()
    for (const cue of timeline?.cues ?? []) {
      const id = previewIdFromCue(cue)
      if (id) ids.add(id)
    }
    return Array.from(ids)
  }, [timeline?.cues])
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
          <div className="flex items-center gap-3 rounded-xl border border-border bg-card/50 px-5 py-4">
            <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
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
              'Scroll through the thumbnails and select one to see it larger. Each label shows where that frame starts.',
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

            {selected ? (
              <div className="mt-4 flex flex-col gap-4 rounded-lg border border-border bg-muted/30 p-3 sm:flex-row sm:items-center">
                <TimelineSpriteThumb
                  cue={selected}
                  className="rounded-md sm:w-[320px]"
                />
                <dl className="space-y-2 text-[13px]">
                  <div>
                    <dt className="text-[12px] text-muted-foreground">
                      {t('Time range')}
                    </dt>
                    <dd className="font-medium tabular-nums text-foreground">
                      {formatPlaybackTime(selected.start)} -{' '}
                      {formatPlaybackTime(selected.end)}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-[12px] text-muted-foreground">
                      {t('Sprite region')}
                    </dt>
                    <dd className="font-mono text-[12px] text-foreground">
                      #xywh={selected.x},{selected.y},{selected.width},
                      {selected.height}
                    </dd>
                  </div>
                </dl>
              </div>
            ) : null}

            <ThumbnailStrip
              cues={timeline.cues}
              selected={selected}
              onSelect={setSelected}
            />
          </VideoSectionCard>

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

      <PreviewImageCard
        projectId={projectId}
        videoId={videoId}
        previewIds={previewIds}
        defaultPreviewId={video.previewId}
      />
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
  selected,
  onSelect,
}: {
  cues: VideoTimelineCue[]
  selected: VideoTimelineCue | null
  onSelect: (cue: VideoTimelineCue | null) => void
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
    if (!selected) return
    const index = cues.indexOf(selected)
    scrollerRef.current
      ?.querySelector<HTMLElement>(`[data-cue-index="${index}"]`)
      ?.scrollIntoView({
        behavior: 'smooth',
        block: 'nearest',
        inline: 'nearest',
      })
  }, [selected, cues])

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
    const current = selected ? cues.indexOf(selected) : -1
    const next = Math.min(
      cues.length - 1,
      Math.max(0, current + (forward ? 1 : -1)),
    )
    if (next === current) return
    event.preventDefault()
    onSelect(cues[next])
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
        {cues.map((cue, index) => {
          const isSelected = selected === cue
          return (
            <button
              key={`${cue.imageUrl}-${cue.start}`}
              type="button"
              data-cue-index={index}
              onClick={() => onSelect(isSelected ? null : cue)}
              aria-pressed={isSelected}
              aria-label={formatPlaybackTime(cue.start)}
              className={cn(
                'group relative shrink-0 snap-start overflow-hidden rounded-md focus-visible:outline-none',
              )}
            >
              <TimelineSpriteThumb
                cue={cue}
                width={thumbWidth}
                className="transition-opacity group-hover:opacity-90"
              />
              <span
                aria-hidden
                className={cn(
                  'pointer-events-none absolute inset-0 rounded-md ring-inset transition',
                  isSelected
                    ? 'ring-2 ring-foreground'
                    : 'ring-1 ring-border group-hover:ring-foreground/40 group-focus-visible:ring-2 group-focus-visible:ring-ring',
                )}
              />
              <span className="pointer-events-none absolute bottom-1 start-1 rounded bg-black/70 px-1 py-0.5 font-mono text-[10px] tabular-nums text-white">
                {formatPlaybackTime(cue.start)}
              </span>
            </button>
          )
        })}
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

/** Builds a `getPreview` URL with optional resize and format. */
function PreviewImageCard({
  projectId,
  videoId,
  previewIds,
  defaultPreviewId,
}: {
  projectId: string
  videoId: string
  previewIds: string[]
  defaultPreviewId: string
}) {
  const t = useT()
  const [width, setWidth] = useState('1280')
  const [height, setHeight] = useState('')
  const [format, setFormat] = useState<ImageFormat>(ImageFormat.Webp)
  const [chosenPreviewId, setChosenPreviewId] = useState<string | null>(null)

  const options = useMemo(
    () =>
      defaultPreviewId && !previewIds.includes(defaultPreviewId)
        ? [defaultPreviewId, ...previewIds]
        : previewIds,
    [defaultPreviewId, previewIds],
  )
  const previewId =
    (chosenPreviewId && options.includes(chosenPreviewId)
      ? chosenPreviewId
      : null) ??
    (defaultPreviewId || options[0] || '')

  const url = useMemo(() => {
    if (!previewId) return ''
    const w = Number(width)
    const h = Number(height)
    return String(
      sdk.forProject(projectId).videos.getPreview({
        videoId,
        previewId,
        width: w > 0 ? w : undefined,
        height: h > 0 ? h : undefined,
        output: format,
      }),
    )
  }, [projectId, videoId, previewId, width, height, format])

  return (
    <VideoSectionCard
      title={t('Preview image')}
      term="preview"
      description={t(
        'An image taken from the timeline. Use it as a poster or thumbnail. Set a size and format to get a ready-to-use URL.',
      )}
    >
      {!previewId ? (
        <p className="text-[13px] text-muted-foreground">
          {t('Generate a timeline to create a preview image.')}
        </p>
      ) : (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_280px]">
          <div className="space-y-3">
            {options.length > 1 ? (
              <div className="space-y-1.5">
                <Label className="text-[12px]">{t('Sprite sheet')}</Label>
                <div className="grid grid-cols-[repeat(auto-fill,minmax(96px,1fr))] gap-2">
                  {options.map((id, index) => {
                    const isSelected = id === previewId
                    return (
                      <button
                        key={id}
                        type="button"
                        onClick={() => setChosenPreviewId(id)}
                        aria-pressed={isSelected}
                        aria-label={`${t('Sprite sheet')} ${index + 1}`}
                        className={cn(
                          'relative overflow-hidden rounded-md bg-muted ring-1 ring-border transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                          isSelected
                            ? 'ring-2 ring-foreground'
                            : 'hover:ring-foreground/40',
                        )}
                      >
                        <img
                          src={withAdminMode(
                            String(
                              sdk.forProject(projectId).videos.getPreview({
                                videoId,
                                previewId: id,
                                width: 240,
                              }),
                            ),
                          )}
                          alt=""
                          loading="lazy"
                          className="aspect-video w-full object-cover"
                        />
                        <span className="pointer-events-none absolute bottom-1 start-1 rounded bg-black/70 px-1.5 py-0.5 font-mono text-[10px] tabular-nums text-white">
                          {index + 1}
                        </span>
                      </button>
                    )
                  })}
                </div>
              </div>
            ) : null}
            <div className="grid grid-cols-3 gap-3">
              <div className="space-y-1.5">
                <Label htmlFor="preview-width" className="text-[12px]">
                  {t('Width (px)')}
                </Label>
                <Input
                  id="preview-width"
                  type="number"
                  min={0}
                  value={width}
                  onChange={(event) => setWidth(event.target.value)}
                  placeholder={t('Original')}
                  className="h-9 text-[13px]"
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="preview-height" className="text-[12px]">
                  {t('Height (px)')}
                </Label>
                <Input
                  id="preview-height"
                  type="number"
                  min={0}
                  value={height}
                  onChange={(event) => setHeight(event.target.value)}
                  placeholder={t('Auto')}
                  className="h-9 text-[13px]"
                />
              </div>
              <div className="space-y-1.5">
                <Label className="text-[12px]">{t('Format')}</Label>
                <Select
                  value={format}
                  onValueChange={(value) => setFormat(value as ImageFormat)}
                >
                  <SelectTrigger className="h-9 text-[13px]">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {PREVIEW_FORMATS.map((option) => (
                      <SelectItem
                        key={option}
                        value={option}
                        className="text-[13px] uppercase"
                      >
                        {option}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className="divide-y divide-border">
              <VideoUrlRow label={t('Preview URL')} url={url} />
            </div>
          </div>
          <div className="overflow-hidden rounded-lg border border-border bg-muted">
            <img
              src={withAdminMode(url)}
              alt={t('Preview image')}
              className="aspect-video w-full object-contain"
              loading="lazy"
            />
          </div>
        </div>
      )}
    </VideoSectionCard>
  )
}
