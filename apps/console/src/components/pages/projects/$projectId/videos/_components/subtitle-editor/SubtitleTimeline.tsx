import {
  memo,
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type PointerEvent as ReactPointerEvent,
  type RefObject,
} from 'react'
import {
  AlertCircle,
  AlertTriangle,
  Maximize2,
  Scissors,
  ZoomIn,
  ZoomOut,
} from 'lucide-react'
import { TimelineSpriteThumb } from '../player/TimelineSpriteThumb'
import { usePlaybackClockInstance, usePlaybackValue } from './playback-clock'
import { Button } from '@/components/ui/button'
import { Slider } from '@/components/ui/slider'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'
import type { VideoTimelineCue } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { formatPlayerClock } from '@/lib/utils/video-format'
import {
  stripCueMarkup,
  formatEditorTimestamp,
  SUBTITLE_CUE_ISSUE_LABELS,
  SUBTITLE_MIN_CUE_DURATION,
  subtitleTextDirection,
  type SubtitleCue,
  type SubtitleCueIssue,
} from '@/lib/videos/subtitle-cues'

const MAX_PPS = 400
const DEFAULT_PPS = 80
const RULER_HEIGHT = 22
const FILMSTRIP_HEIGHT = 40
const LANE_HEIGHT = 52
const EDGE_HANDLE_PX = 6
const SNAP_PX = 8
const DRAG_THRESHOLD_PX = 3
const TICK_STEPS = [0.5, 1, 2, 5, 10, 15, 30, 60, 120, 300, 600, 1800]

type DragMode = 'move' | 'start' | 'end'

type DragState = {
  id: string
  mode: DragMode
  x0: number
  start0: number
  end0: number
  moved: boolean
  session: string
}

const roundMs = (seconds: number) => Math.round(seconds * 1000) / 1000

function formatTick(seconds: number, step: number): string {
  if (step >= 1) return formatPlayerClock(seconds)
  const whole = Math.floor(seconds)
  return `${formatPlayerClock(whole)}.${Math.round((seconds - whole) * 10)}`
}

export function SubtitleTimeline({
  cues,
  issues,
  selectedIds,
  duration,
  spriteCues,
  videoRef,
  languageCode,
  onSelect,
  onRetime,
  splitMode,
  onSplit,
  className,
}: {
  className?: string
  cues: SubtitleCue[]
  issues: Map<string, SubtitleCueIssue[]>
  selectedIds: ReadonlySet<string>
  duration: number
  languageCode: string
  spriteCues?: VideoTimelineCue[]
  videoRef: RefObject<HTMLVideoElement | null>
  onSelect: (id: string, additive: boolean) => void
  onRetime: (id: string, start: number, end: number, session: string) => void
  /** Split tool: a cut line follows the pointer and a click splits the cue under it. */
  splitMode: boolean
  onSplit: (id: string, time: number, keepSplitting: boolean) => void
}) {
  const t = useT()
  const clock = usePlaybackClockInstance()
  const scrollerRef = useRef<HTMLDivElement | null>(null)
  const contentRef = useRef<HTMLDivElement | null>(null)
  const [viewport, setViewport] = useState({ left: 0, width: 0 })
  const lastCueEnd = cues.reduce((max, cue) => Math.max(max, cue.end), 0)
  // Without a known duration, leave room after the last cue.
  const totalSeconds = duration > 0 ? duration : Math.max(lastCueEnd + 5, 10)
  const fitPps = viewport.width > 0 ? viewport.width / totalSeconds : 1
  const minPps = Math.min(fitPps, DEFAULT_PPS)
  // 'auto' fills short videos and uses the default zoom for long ones; 'fit'
  // keeps tracking the viewport and duration until the user zooms manually.
  const [zoom, setZoom] = useState<'auto' | 'fit' | number>('auto')
  const fitMode = zoom === 'fit' || (zoom === 'auto' && fitPps >= DEFAULT_PPS)
  const pps = Math.min(
    MAX_PPS,
    Math.max(minPps, fitMode ? fitPps : typeof zoom === 'number' ? zoom : DEFAULT_PPS),
  )
  const contentWidth = Math.max(totalSeconds * pps, viewport.width)
  const anchorRef = useRef<{ time: number; x: number } | null>(null)
  const resetScrollRef = useRef(false)

  const updateViewport = useCallback(() => {
    const el = scrollerRef.current
    if (!el) return
    setViewport((prev) =>
      prev.left === el.scrollLeft && prev.width === el.clientWidth
        ? prev
        : { left: el.scrollLeft, width: el.clientWidth },
    )
  }, [])

  useEffect(() => {
    const el = scrollerRef.current
    if (!el) return
    updateViewport()
    const observer = new ResizeObserver(updateViewport)
    observer.observe(el)
    return () => observer.disconnect()
  }, [updateViewport])

  const zoomTo = useCallback(
    (next: number, anchorX?: number) => {
      const el = scrollerRef.current
      if (!el) return
      const x = anchorX ?? el.clientWidth / 2
      anchorRef.current = { time: (el.scrollLeft + x) / pps, x }
      setZoom(Math.min(MAX_PPS, Math.max(minPps, next)))
    },
    [pps, minPps],
  )

  const fitToView = () => {
    resetScrollRef.current = true
    setZoom('fit')
  }

  useLayoutEffect(() => {
    const el = scrollerRef.current
    if (!el) return
    if (resetScrollRef.current) {
      resetScrollRef.current = false
      anchorRef.current = null
      el.scrollLeft = 0
      updateViewport()
      return
    }
    const anchor = anchorRef.current
    if (!anchor) return
    anchorRef.current = null
    el.scrollLeft = Math.max(0, anchor.time * pps - anchor.x)
    updateViewport()
  }, [pps, zoom, updateViewport])

  useEffect(() => {
    const el = scrollerRef.current
    if (!el) return
    const onWheel = (event: WheelEvent) => {
      if (!event.ctrlKey && !event.metaKey) return
      event.preventDefault()
      const rect = el.getBoundingClientRect()
      zoomTo(pps * Math.exp(-event.deltaY * 0.002), event.clientX - rect.left)
    }
    el.addEventListener('wheel', onWheel, { passive: false })
    return () => el.removeEventListener('wheel', onWheel)
  }, [pps, zoomTo])

  const [splitHover, setSplitHover] = useState<number | null>(null)
  useEffect(() => {
    if (!splitMode) setSplitHover(null)
  }, [splitMode])
  const splitCueAt = (time: number) =>
    cues.find(
      (cue) =>
        time - cue.start >= SUBTITLE_MIN_CUE_DURATION &&
        cue.end - time >= SUBTITLE_MIN_CUE_DURATION,
    )
  const splitCandidate = splitHover === null ? undefined : splitCueAt(splitHover)

  const visibleStart = Math.max(0, viewport.left / pps - 5)
  const visibleEnd = (viewport.left + viewport.width) / pps + 5

  const timeAt = (clientX: number) => {
    const rect = contentRef.current?.getBoundingClientRect()
    if (!rect) return 0
    return Math.max(0, Math.min(totalSeconds, (clientX - rect.left) / pps))
  }

  const seek = (time: number) => {
    const el = videoRef.current
    if (!el) return
    el.currentTime = Math.min(time, el.duration || time)
  }

  const scrubbingRef = useRef(false)
  const handleScrubDown = (event: ReactPointerEvent<HTMLDivElement>) => {
    if (event.button !== 0) return
    scrubbingRef.current = true
    event.currentTarget.setPointerCapture(event.pointerId)
    seek(timeAt(event.clientX))
  }
  const handleScrubMove = (event: ReactPointerEvent<HTMLDivElement>) => {
    if (scrubbingRef.current) seek(timeAt(event.clientX))
  }
  const handleScrubUp = () => {
    scrubbingRef.current = false
  }

  const dragRef = useRef<DragState | null>(null)
  const snapPoints = useCallback(
    (excludeId: string) => {
      const points = [clock.time]
      for (const cue of cues) {
        if (cue.id === excludeId) continue
        if (cue.end < visibleStart || cue.start > visibleEnd) continue
        points.push(cue.start, cue.end)
      }
      return points
    },
    [clock, cues, visibleStart, visibleEnd],
  )

  const snap = (time: number, points: number[], bypass: boolean) => {
    if (bypass) return time
    const tolerance = SNAP_PX / pps
    let best = time
    let bestDistance = tolerance
    for (const point of points) {
      const distance = Math.abs(point - time)
      if (distance < bestDistance) {
        best = point
        bestDistance = distance
      }
    }
    return best
  }

  const splitTimeAt = (event: ReactPointerEvent) =>
    roundMs(snap(timeAt(event.clientX), [clock.time], event.altKey))

  const handleCueDown = (
    event: ReactPointerEvent<HTMLDivElement>,
    cue: SubtitleCue,
  ) => {
    if (event.button !== 0) return
    event.stopPropagation()
    const handle = (event.target as HTMLElement).closest<HTMLElement>('[data-edge]')
    const mode: DragMode =
      handle?.dataset.edge === 'start'
        ? 'start'
        : handle?.dataset.edge === 'end'
          ? 'end'
          : 'move'
    // Capturing on the handle keeps its resize cursor for the whole drag.
    ;(handle ?? event.currentTarget).setPointerCapture(event.pointerId)
    dragRef.current = {
      id: cue.id,
      mode,
      x0: event.clientX,
      start0: cue.start,
      end0: cue.end,
      moved: false,
      session: `drag:${cue.id}:${Date.now()}`,
    }
    const additive = event.shiftKey || event.metaKey || event.ctrlKey
    if (additive || !selectedIds.has(cue.id)) onSelect(cue.id, additive)
  }

  const handleCueMove = (event: ReactPointerEvent<HTMLDivElement>) => {
    const drag = dragRef.current
    if (!drag) return
    const dx = event.clientX - drag.x0
    if (!drag.moved && Math.abs(dx) < DRAG_THRESHOLD_PX) return
    drag.moved = true
    const dt = dx / pps
    const length = drag.end0 - drag.start0
    const points = snapPoints(drag.id)
    const bypass = event.altKey
    let start = drag.start0
    let end = drag.end0
    if (drag.mode === 'move') {
      start = snap(drag.start0 + dt, points, bypass)
      if (start === drag.start0 + dt) {
        start = snap(drag.end0 + dt, points, bypass) - length
      }
      start = Math.max(0, Math.min(totalSeconds - length, start))
      end = start + length
    } else if (drag.mode === 'start') {
      start = Math.max(
        0,
        Math.min(
          drag.end0 - SUBTITLE_MIN_CUE_DURATION,
          snap(drag.start0 + dt, points, bypass),
        ),
      )
    } else {
      end = Math.min(
        totalSeconds,
        Math.max(
          drag.start0 + SUBTITLE_MIN_CUE_DURATION,
          snap(drag.end0 + dt, points, bypass),
        ),
      )
    }
    onRetime(drag.id, roundMs(start), roundMs(end), drag.session)
  }

  const handleCueUp = () => {
    dragRef.current = null
  }

  const tickStep =
    TICK_STEPS.find((step) => step * pps >= 72) ??
    TICK_STEPS[TICK_STEPS.length - 1]
  const ticks = useMemo(() => {
    const list: number[] = []
    const first = Math.floor(visibleStart / tickStep) * tickStep
    for (let time = first; time <= Math.min(visibleEnd, totalSeconds); time += tickStep) {
      list.push(roundMs(time))
    }
    return list
  }, [visibleStart, visibleEnd, tickStep, totalSeconds])

  const visibleCues = cues.filter(
    (cue) => cue.end >= visibleStart && cue.start <= visibleEnd,
  )
  const hasFilmstrip = (spriteCues?.length ?? 0) > 0
  const lanesTop = RULER_HEIGHT + (hasFilmstrip ? FILMSTRIP_HEIGHT : 0)

  const zoomSlider = Math.log(pps / minPps) / Math.log(MAX_PPS / minPps)
  const textDir = subtitleTextDirection(languageCode)
  const issueLabel = (list: SubtitleCueIssue[] | undefined) =>
    list?.length
      ? list.map((issue) => t(SUBTITLE_CUE_ISSUE_LABELS[issue.kind])).join('\n')
      : undefined

  return (
    <div className={cn('flex shrink-0 flex-col border-t border-border bg-background', className)}>
      <div data-subtitle-keep-selection className="flex items-center gap-2 px-3 py-1.5">
        <span className="shrink-0 text-[12px] font-medium text-muted-foreground">
          {t('Timeline')}
        </span>
        <span className="hidden min-w-0 truncate text-[11px] text-muted-foreground/70 xl:inline">
          {splitMode
            ? t('Click a cue to split it at the pointer. Hold Shift to keep splitting, press Esc to stop.')
            : t('Drag cues to move them, drag their edges to retime. Hold Alt to turn off snapping.')}
        </span>
        <div className="ms-auto flex shrink-0 items-center gap-1">
          <ZoomButton
            label={t('Zoom out')}
            icon={ZoomOut}
            onClick={() => zoomTo(pps / 1.5)}
          />
          <Slider
            aria-label={t('Zoom')}
            className="w-20 sm:w-28"
            min={0}
            max={1}
            step={0.01}
            value={[Number.isFinite(zoomSlider) ? zoomSlider : 0]}
            onValueChange={([value]) =>
              zoomTo(minPps * Math.pow(MAX_PPS / minPps, value ?? 0))
            }
          />
          <ZoomButton
            label={t('Zoom in')}
            icon={ZoomIn}
            onClick={() => zoomTo(pps * 1.5)}
          />
          <ZoomButton
            label={t('Fit to view')}
            icon={Maximize2}
            active={fitMode}
            onClick={fitToView}
          />
        </div>
      </div>
      <div
        ref={scrollerRef}
        dir="ltr"
        onScroll={updateViewport}
        className="relative overflow-x-auto overflow-y-hidden border-t border-border"
        style={{ height: lanesTop + LANE_HEIGHT + 12 }}
      >
        <div
          ref={contentRef}
          className="relative h-full cursor-ew-resize select-none overflow-hidden"
          style={{ width: contentWidth }}
          onPointerDown={handleScrubDown}
          onPointerMove={handleScrubMove}
          onPointerUp={handleScrubUp}
          onPointerCancel={handleScrubUp}
        >
          <div
            data-subtitle-keep-selection
            className="absolute inset-x-0 top-0 border-b border-border bg-muted/40"
            style={{ height: RULER_HEIGHT }}
          >
            {ticks.map((time) => (
              <div
                key={time}
                className="absolute top-0 h-full border-s border-border"
                style={{ left: time * pps }}
              >
                <span className="absolute start-1 top-0.5 font-mono text-[10px] tabular-nums text-muted-foreground">
                  {formatTick(time, tickStep)}
                </span>
              </div>
            ))}
            {visibleCues.map((cue) => {
              const severity = issueSeverity(issues.get(cue.id))
              if (!severity) return null
              return (
                <span
                  key={cue.id}
                  aria-hidden
                  className={cn(
                    'pointer-events-none absolute bottom-0 h-1 rounded-full',
                    severity === 'error' ? 'bg-destructive' : 'bg-amber-500',
                  )}
                  style={{
                    left: cue.start * pps,
                    width: Math.max(4, (cue.end - cue.start) * pps),
                  }}
                />
              )
            })}
          </div>
          {hasFilmstrip ? (
            <Filmstrip
              spriteCues={spriteCues!}
              pps={pps}
              visibleStart={visibleStart}
              visibleEnd={visibleEnd}
            />
          ) : null}
          <div
            className="absolute inset-x-0 bottom-0"
            style={{ top: lanesTop }}
          >
            {visibleCues.map((cue) => (
              <CueBlock
                key={cue.id}
                cue={cue}
                pps={pps}
                selected={selectedIds.has(cue.id)}
                splitTarget={splitCandidate?.id === cue.id}
                severity={issueSeverity(issues.get(cue.id))}
                issueLabel={issueLabel(issues.get(cue.id))}
                textDir={textDir}
                lang={languageCode || undefined}
                onPointerDown={handleCueDown}
                onPointerMove={handleCueMove}
                onPointerUp={handleCueUp}
                onDoubleClick={() => seek(cue.start)}
              />
            ))}
          </div>
          {duration > 0 && contentWidth > duration * pps ? (
            <div
              aria-hidden
              className="pointer-events-none absolute inset-y-0 end-0 z-[15] border-s border-border bg-background/80 bg-[repeating-linear-gradient(135deg,transparent_0_6px,var(--color-border)_6px_7px)] opacity-90"
              style={{ left: duration * pps }}
            />
          ) : null}
          {splitMode ? (
            <div
              data-subtitle-keep-selection
              className="absolute inset-0 z-[25] cursor-crosshair"
              onPointerMove={(event) => setSplitHover(splitTimeAt(event))}
              onPointerLeave={(event) => {
                if (event.pointerType === 'mouse') setSplitHover(null)
              }}
              onPointerDown={(event) => {
                if (event.button !== 0) return
                event.stopPropagation()
                const time = splitTimeAt(event)
                const target = splitCueAt(time)
                if (target) onSplit(target.id, time, event.shiftKey)
                setSplitHover(time)
              }}
            >
              {splitHover !== null ? (
                <SplitCursor
                  x={splitHover * pps}
                  time={splitHover}
                  valid={!!splitCandidate}
                  knobTop={lanesTop + LANE_HEIGHT / 2 - 10}
                />
              ) : null}
            </div>
          ) : null}
          <Playhead
            pps={pps}
            scrollerRef={scrollerRef}
            onPointerDown={handleScrubDown}
            onPointerMove={handleScrubMove}
            onPointerUp={handleScrubUp}
          />
        </div>
      </div>
    </div>
  )
}

function issueSeverity(issues: SubtitleCueIssue[] | undefined) {
  if (!issues?.length) return null
  return issues.some((issue) => issue.severity === 'error') ? 'error' : 'warning'
}

const CueBlock = memo(function CueBlock({
  cue,
  pps,
  selected,
  splitTarget,
  severity,
  issueLabel,
  textDir,
  lang,
  onPointerDown,
  onPointerMove,
  onPointerUp,
  onDoubleClick,
}: {
  cue: SubtitleCue
  pps: number
  selected: boolean
  splitTarget: boolean
  severity: 'error' | 'warning' | null
  issueLabel: string | undefined
  textDir: 'rtl' | 'auto'
  lang: string | undefined
  onPointerDown: (event: ReactPointerEvent<HTMLDivElement>, cue: SubtitleCue) => void
  onPointerMove: (event: ReactPointerEvent<HTMLDivElement>) => void
  onPointerUp: () => void
  onDoubleClick: () => void
}) {
  const width = Math.max(4, (cue.end - cue.start) * pps)
  const edge = Math.min(EDGE_HANDLE_PX, width / 3)
  return (
    <div
      data-cue-id={cue.id}
      data-subtitle-keep-selection
      role="button"
      tabIndex={-1}
      aria-pressed={selected}
      onPointerDown={(event) => onPointerDown(event, cue)}
      onPointerMove={onPointerMove}
      onPointerUp={onPointerUp}
      onPointerCancel={onPointerUp}
      onDoubleClick={onDoubleClick}
      title={issueLabel}
      className={cn(
        'group absolute top-1.5 bottom-1.5 cursor-grab overflow-hidden rounded-md border px-1.5 py-1 active:cursor-grabbing',
        selected
          ? 'z-10 border-foreground bg-foreground/15'
          : 'border-border bg-secondary hover:border-foreground/40',
        severity === 'error' &&
          (selected
            ? 'bg-destructive/20 ring-1 ring-destructive'
            : 'border-destructive bg-destructive/10'),
        severity === 'warning' &&
          (selected
            ? 'bg-amber-500/20 ring-1 ring-amber-500'
            : 'border-amber-500 bg-amber-500/10'),
        splitTarget && 'z-10 border-sky-500 bg-sky-500/15 ring-2 ring-sky-500/60',
      )}
      style={{ left: cue.start * pps, width }}
    >
      {severity && width >= 28 ? (
        severity === 'error' ? (
          <AlertCircle className="pointer-events-none absolute end-1 top-1 h-3 w-3 text-destructive" />
        ) : (
          <AlertTriangle className="pointer-events-none absolute end-1 top-1 h-3 w-3 text-amber-500" />
        )
      ) : null}
      <span
        data-edge="start"
        className="absolute inset-y-0 left-0 z-10 cursor-w-resize hover:bg-foreground/25"
        style={{ width: edge }}
      />
      <span
        data-edge="end"
        className="absolute inset-y-0 right-0 z-10 cursor-e-resize hover:bg-foreground/25"
        style={{ width: edge }}
      />
      <p
        dir={textDir}
        lang={lang}
        className={cn(
          'pointer-events-none line-clamp-2 whitespace-pre-line text-[11px] leading-tight text-foreground [unicode-bidi:plaintext]',
          severity && width >= 28 && 'pe-3',
        )}
      >
        {stripCueMarkup(cue.text)}
      </p>
    </div>
  )
})

function Filmstrip({
  spriteCues,
  pps,
  visibleStart,
  visibleEnd,
}: {
  spriteCues: VideoTimelineCue[]
  pps: number
  visibleStart: number
  visibleEnd: number
}) {
  const first = spriteCues[0]
  const aspect = first && first.height > 0 ? first.width / first.height : 16 / 9
  const thumbWidth = (FILMSTRIP_HEIGHT - 4) * aspect
  const slot = first ? Math.max(0.001, (first.end - first.start) * pps) : 1
  const every = Math.max(1, Math.ceil(thumbWidth / slot))
  return (
    <div
      aria-hidden
      className="pointer-events-none absolute inset-x-0 overflow-hidden border-b border-border bg-muted/20"
      style={{ top: RULER_HEIGHT, height: FILMSTRIP_HEIGHT }}
    >
      {spriteCues.map((cue, index) => {
        if (index % every !== 0) return null
        if (cue.end < visibleStart || cue.start > visibleEnd) return null
        return (
          <div
            key={`${cue.imageUrl}-${cue.start}`}
            className="absolute top-0.5 overflow-hidden rounded-sm opacity-80"
            style={{ left: cue.start * pps }}
          >
            <TimelineSpriteThumb cue={cue} width={thumbWidth} />
          </div>
        )
      })}
    </div>
  )
}

function Playhead({
  pps,
  scrollerRef,
  onPointerDown,
  onPointerMove,
  onPointerUp,
}: {
  pps: number
  scrollerRef: RefObject<HTMLDivElement | null>
  onPointerDown: (event: ReactPointerEvent<HTMLDivElement>) => void
  onPointerMove: (event: ReactPointerEvent<HTMLDivElement>) => void
  onPointerUp: () => void
}) {
  const x = usePlaybackValue((clock) => Math.round(clock.time * pps))

  // Follow the playhead when time changes, but not when the user scrolls.
  useEffect(() => {
    const el = scrollerRef.current
    if (!el) return
    const margin = 48
    if (x < el.scrollLeft || x > el.scrollLeft + el.clientWidth - margin) {
      el.scrollLeft = Math.max(0, x - margin)
    }
  }, [x, scrollerRef])

  return (
    <div
      aria-hidden
      data-subtitle-keep-selection
      className="group absolute inset-y-0 z-20 flex w-3 cursor-ew-resize justify-center touch-none"
      style={{ left: x - 6 }}
      onPointerDown={(event) => {
        event.stopPropagation()
        onPointerDown(event)
      }}
      onPointerMove={onPointerMove}
      onPointerUp={onPointerUp}
      onPointerCancel={onPointerUp}
    >
      <span className="pointer-events-none h-full w-px bg-red-500 group-hover:w-0.5" />
      <span className="pointer-events-none absolute top-0 h-2.5 w-2.5 rounded-full bg-red-500" />
    </div>
  )
}

function SplitCursor({
  x,
  time,
  valid,
  knobTop,
}: {
  x: number
  time: number
  valid: boolean
  knobTop: number
}) {
  return (
    <div
      aria-hidden
      className="pointer-events-none absolute inset-y-0 flex w-0 justify-center"
      style={{ left: x }}
    >
      <span
        className={cn(
          'h-full border-s-2 border-dashed',
          valid ? 'border-sky-500' : 'border-muted-foreground/50',
        )}
      />
      <span
        className={cn(
          'absolute top-0.5 whitespace-nowrap rounded px-1 font-mono text-[10px] tabular-nums text-white',
          valid ? 'bg-sky-500' : 'bg-muted-foreground',
        )}
      >
        {formatEditorTimestamp(time)}
      </span>
      <span
        className={cn(
          'absolute flex h-5 w-5 items-center justify-center rounded-full text-white shadow-sm',
          valid ? 'bg-sky-500' : 'bg-muted-foreground',
        )}
        style={{ top: knobTop }}
      >
        <Scissors className="h-3 w-3" />
      </span>
    </div>
  )
}

function ZoomButton({
  label,
  icon: Icon,
  onClick,
  active,
}: {
  label: string
  icon: typeof ZoomIn
  onClick: () => void
  active?: boolean
}) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className={cn('h-7 w-7', active && 'bg-accent text-accent-foreground')}
          aria-label={label}
          aria-pressed={active}
          onClick={onClick}
        >
          <Icon className="h-3.5 w-3.5" />
        </Button>
      </TooltipTrigger>
      <TooltipContent className="text-[12px]">{label}</TooltipContent>
    </Tooltip>
  )
}
