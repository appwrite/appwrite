import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type KeyboardEvent,
  type ReactNode,
  type RefObject,
} from 'react'
import {
  Activity,
  Captions,
  Film,
  Gauge,
  Loader2,
  Maximize,
  Minimize,
  MoreHorizontal,
  Pause,
  Play,
  RefreshCw,
  SkipBack,
  SkipForward,
  SquareArrowOutUpRight,
  Volume1,
  Volume2,
  VolumeX,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Slider } from '@/components/ui/slider'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { VideoTimelineCue } from '@/lib/react-query/hooks/videos'
import { findTimelineCueAtTime } from '@/lib/videos/timeline-cues'
import { cn } from '@/lib/utils'
import {
  formatBitrate,
  formatPlaybackTime,
  formatQuality,
  formatResolution,
  getQualityTier,
} from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import type { StreamPlayerState } from './useStreamPlayer'
import { TimelineSpriteThumb } from './TimelineSpriteThumb'

const AUTO_LEVEL = '-1'
const SUBTITLES_OFF = '-1'

/** `2160p [4K UHD] 17.63 Mbps`; bitrate is omitted in compact triggers. */
function QualityLabel({
  width,
  height,
  bitrate,
}: {
  width: number
  height: number
  bitrate?: number
}) {
  if (!width || !height) return <>{formatBitrate(bitrate)}</>
  return (
    <span
      className="flex min-w-0 items-center gap-1.5"
      title={formatResolution(width, height)}
    >
      <span className="font-medium tabular-nums">
        {formatQuality(width, height)}
      </span>
      <span className="shrink-0 rounded-sm border border-current/25 px-1 text-[10px] leading-4 opacity-80">
        {getQualityTier(width, height)}
      </span>
      {bitrate ? (
        <span className="truncate tabular-nums text-muted-foreground">
          {formatBitrate(bitrate)}
        </span>
      ) : null}
    </span>
  )
}

/** True when the controls overlay the video (full screen). */
const OverlayContext = createContext(false)

/** Glass chip for controls drawn over the video in full screen. */
const OVERLAY_CHIP_CLASS =
  "border-white/15 bg-black/30 text-white/90 shadow-sm backdrop-blur-md hover:bg-black/50 hover:text-white dark:border-white/15 dark:bg-black/30 dark:text-white/90 dark:hover:bg-black/50 dark:hover:text-white [&_svg:not([class*='text-'])]:text-white/70"

/** Glass surface for popovers opened from the overlay controls. */
const OVERLAY_SURFACE_CLASS =
  'border-white/15 bg-black/50 text-white shadow-2xl backdrop-blur-xl supports-[backdrop-filter]:bg-black/40'

/** Glass surface plus item states for menus and selects. */
const OVERLAY_MENU_CLASS = cn(
  OVERLAY_SURFACE_CLASS,
  '[&_[data-highlighted]]:bg-white/10 [&_[data-highlighted]]:text-white [&_[role=menuitem][data-state=open]]:bg-white/10 [&_[role=menuitem][data-state=open]]:text-white [&_[role=separator]]:bg-white/15 [&_.text-muted-foreground]:text-white/60',
)

/** Glass tooltip; the solid arrow is dropped since it cannot blur. */
const OVERLAY_TOOLTIP_CLASS = cn(OVERLAY_SURFACE_CLASS, 'border')
const OVERLAY_TOOLTIP_OFFSET = 8

function ControlsSeparator() {
  const overlay = useContext(OverlayContext)
  return (
    <div
      className={cn(
        'mx-1 hidden size-1 shrink-0 rounded-full sm:block',
        overlay ? 'bg-white/40' : 'bg-muted-foreground/40',
      )}
      aria-hidden
    />
  )
}
const SCRUB_PREVIEW_WIDTH_PX = 160
/** Keeps the arrow clear of the popover's rounded corners. */
const SCRUB_ARROW_INSET_PX = 10
function seekFractionFromClientX(clientX: number, trackRect: DOMRect): number {
  if (trackRect.width <= 0) return 0
  return Math.min(1, Math.max(0, (clientX - trackRect.left) / trackRect.width))
}

/** The playhead centers on the track ends at 0 and at the full duration. */
function seekThumbCenterX(fraction: number, trackWidth: number): number {
  return fraction * trackWidth
}

function clampScrubPopoverLeft(
  markerX: number,
  trackWidth: number,
  popoverWidth: number,
): number {
  const half = popoverWidth / 2
  const edgePad = 4
  return Math.min(
    trackWidth - half - edgePad,
    Math.max(half + edgePad, markerX),
  )
}

type ScrubPreview = {
  time: number
  x: number
  cue: VideoTimelineCue
}

export type PlaybackOutput = 'hls' | 'dash' | 'cmaf' | 'source'

type OutputOption = {
  id: PlaybackOutput
  label: string
  disabledReason?: string
}

type VideoStreamPlayerControlsProps = {
  videoRef: RefObject<HTMLVideoElement | null>
  output: PlaybackOutput
  outputOptions: OutputOption[]
  onOutputChange: (output: PlaybackOutput) => void
  playerState: StreamPlayerState
  onSelectLevel: (index: number) => void
  onSelectSubtitleTrack: (index: number) => void
  onReload: () => void
  hasNewRenditions: boolean
  isLoading?: boolean
  scrubCues?: VideoTimelineCue[]
  fullscreen: boolean
  onToggleFullscreen: () => void
  onOpenInspector?: () => void
  /** Fullscreen element; overlays must portal into it to stay visible. */
  portalContainer?: HTMLElement
  className?: string
}

const PortalContainerContext = createContext<HTMLElement | undefined>(undefined)

function IconControl({
  label,
  onClick,
  children,
}: {
  label: string
  onClick: () => void
  children: ReactNode
}) {
  const portalContainer = useContext(PortalContainerContext)
  const overlay = useContext(OverlayContext)
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="icon"
          className={cn('h-8 w-8 shrink-0', overlay && OVERLAY_CHIP_CLASS)}
          onClick={onClick}
          aria-label={label}
        >
          {children}
        </Button>
      </TooltipTrigger>
      <TooltipContent
        className={cn('text-[12px]', overlay && OVERLAY_TOOLTIP_CLASS)}
        arrow={!overlay}
        sideOffset={overlay ? OVERLAY_TOOLTIP_OFFSET : undefined}
        container={portalContainer}
      >
        {label}
      </TooltipContent>
    </Tooltip>
  )
}

function VolumeControl({
  videoRef,
  loadStartedAt,
}: {
  videoRef: RefObject<HTMLVideoElement | null>
  loadStartedAt: number | null | undefined
}) {
  const t = useT()
  const overlay = useContext(OverlayContext)
  const [muted, setMuted] = useState(false)
  const [volume, setVolume] = useState(1)

  useEffect(() => {
    const video = videoRef.current
    if (!video) return
    const sync = () => {
      setMuted(video.muted)
      setVolume(video.volume)
    }
    video.addEventListener('volumechange', sync)
    sync()
    return () => video.removeEventListener('volumechange', sync)
  }, [videoRef, loadStartedAt])

  const silent = muted || volume === 0
  const Icon = silent ? VolumeX : volume < 0.5 ? Volume1 : Volume2

  return (
    <div className="flex shrink-0 items-center gap-2">
      <IconControl
        label={silent ? t('Unmute') : t('Mute')}
        onClick={() => {
          const video = videoRef.current
          if (!video) return
          if (silent) {
            video.muted = false
            if (video.volume === 0) video.volume = 1
          } else {
            video.muted = true
          }
        }}
      >
        <Icon className="h-3.5 w-3.5" />
      </IconControl>
      <Slider
        className={cn(
          'hidden w-20 @lg:flex',
          overlay &&
            '[&_[data-slot=slider-range]]:bg-white [&_[data-slot=slider-track]]:bg-white/25',
        )}
        min={0}
        max={1}
        step={0.05}
        value={[silent ? 0 : volume]}
        aria-label={t('Volume')}
        onValueChange={([next]) => {
          const video = videoRef.current
          if (!video || next == null) return
          video.volume = next
          video.muted = next === 0
        }}
      />
    </div>
  )
}

function PlaybackFormatSelect({
  value,
  options,
  onChange,
  open,
  onOpenChange,
}: {
  value: PlaybackOutput
  options: OutputOption[]
  onChange: (output: PlaybackOutput) => void
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const t = useT()
  const portalContainer = useContext(PortalContainerContext)
  const overlay = useContext(OverlayContext)

  return (
    <Select
      value={value}
      onValueChange={(next) => onChange(next as PlaybackOutput)}
      open={open}
      onOpenChange={onOpenChange}
    >
      <SelectTrigger
        size="sm"
        className={cn(
          'h-8 w-[9.5rem] text-[12px]',
          overlay && OVERLAY_CHIP_CLASS,
        )}
        aria-label={t('Stream format')}
      >
        <SelectValue />
      </SelectTrigger>
      <SelectContent
        container={portalContainer}
        className={cn(overlay && OVERLAY_MENU_CLASS)}
      >
        {options.map((option) => (
          <SelectItem
            key={option.id}
            value={option.id}
            disabled={Boolean(option.disabledReason)}
            className="text-[12px]"
          >
            <span className="flex flex-col">
              <span>{option.label}</span>
              {option.disabledReason ? (
                <span className="text-[11px] text-muted-foreground">
                  {option.disabledReason}
                </span>
              ) : null}
            </span>
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}

export function VideoStreamPlayerControls({
  videoRef,
  output,
  outputOptions,
  onOutputChange,
  playerState,
  onSelectLevel,
  onSelectSubtitleTrack,
  onReload,
  hasNewRenditions,
  isLoading = false,
  scrubCues,
  fullscreen,
  onToggleFullscreen,
  onOpenInspector,
  portalContainer,
  className,
}: VideoStreamPlayerControlsProps) {
  const t = useT()
  const seekTrackRef = useRef<HTMLDivElement | null>(null)
  const seekDraggingRef = useRef(false)
  const [trackWidth, setTrackWidth] = useState(0)
  const [playing, setPlaying] = useState(false)
  const [currentTime, setCurrentTime] = useState(0)
  const [duration, setDuration] = useState(0)
  const [seeking, setSeeking] = useState(false)
  const [seekValue, setSeekValue] = useState(0)
  const [scrubPreview, setScrubPreview] = useState<ScrubPreview | null>(null)
  // Overlay controls re-enable pointer events, so Radix no longer closes one menu when another opens.
  const [openMenu, setOpenMenu] = useState<
    'format' | 'quality' | 'subtitles' | 'more' | null
  >(null)
  const menuState = (menu: NonNullable<typeof openMenu>) => ({
    open: openMenu === menu,
    onOpenChange: (open: boolean) =>
      setOpenMenu((current) =>
        open ? menu : current === menu ? null : current,
      ),
  })

  const hasScrubCues = (scrubCues?.length ?? 0) > 0

  useEffect(() => {
    const el = seekTrackRef.current
    if (!el) return
    const update = () => setTrackWidth(el.getBoundingClientRect().width)
    update()
    const observer = new ResizeObserver(update)
    observer.observe(el)
    return () => observer.disconnect()
  }, [])

  const resolveScrubPreview = useCallback(
    (clientX: number): ScrubPreview | null => {
      if (!hasScrubCues || !scrubCues) return null
      const track = seekTrackRef.current
      if (!track || !(duration > 0)) return null

      const rect = track.getBoundingClientRect()
      if (rect.width <= 0) return null

      const fraction = seekFractionFromClientX(clientX, rect)
      const time = fraction * duration
      const cue = findTimelineCueAtTime(scrubCues, time)
      if (!cue) return null

      const x = seekThumbCenterX(fraction, rect.width)
      return { time, x, cue }
    },
    [duration, hasScrubCues, scrubCues],
  )

  const syncFromVideo = useCallback(() => {
    const video = videoRef.current
    if (!video) return
    setPlaying(!video.paused && !video.ended)
    if (!seeking) {
      setCurrentTime(video.currentTime)
      setSeekValue(video.currentTime)
    }
    if (Number.isFinite(video.duration)) {
      setDuration(video.duration)
    }
  }, [videoRef, seeking])

  useEffect(() => {
    const video = videoRef.current
    if (!video) return

    const events = [
      'play',
      'pause',
      'timeupdate',
      'loadedmetadata',
      'durationchange',
      'ended',
    ] as const

    for (const event of events) {
      video.addEventListener(event, syncFromVideo)
    }
    syncFromVideo()

    return () => {
      for (const event of events) {
        video.removeEventListener(event, syncFromVideo)
      }
    }
  }, [videoRef, syncFromVideo, playerState.loadStartedAt])

  const togglePlay = () => {
    const video = videoRef.current
    if (!video) return
    if (video.paused) void video.play()
    else video.pause()
  }

  const goToStart = () => {
    const video = videoRef.current
    if (!video) return
    video.currentTime = 0
    syncFromVideo()
  }

  const goToEnd = () => {
    const video = videoRef.current
    if (!video || !Number.isFinite(video.duration)) return
    video.currentTime = video.duration
    syncFromVideo()
  }

  const seekFromClientX = useCallback(
    (clientX: number) => {
      const track = seekTrackRef.current
      if (!track || !(duration > 0)) return
      const rect = track.getBoundingClientRect()
      const fraction = seekFractionFromClientX(clientX, rect)
      const time = fraction * duration
      setSeeking(true)
      setSeekValue(time)
      if (hasScrubCues && scrubCues) {
        const cue = findTimelineCueAtTime(scrubCues, time)
        if (cue) {
          setScrubPreview({
            time,
            x: seekThumbCenterX(fraction, rect.width),
            cue,
          })
          return
        }
      }
      if (!seekDraggingRef.current) return
      setScrubPreview(null)
    },
    [duration, hasScrubCues, scrubCues],
  )

  const updateScrubPreviewFromClientX = (clientX: number) => {
    if (seekDraggingRef.current) return
    setScrubPreview(resolveScrubPreview(clientX))
  }

  const commitSeek = () => {
    const video = videoRef.current
    if (video && Number.isFinite(seekValue)) {
      video.currentTime = seekValue
    }
    setSeeking(false)
    seekDraggingRef.current = false
    setScrubPreview(null)
    syncFromVideo()
  }

  const showQuality =
    (playerState.engine === 'shaka' || playerState.engine === 'hls.js') &&
    playerState.levels.length > 0

  const currentLevelValue =
    playerState.stats && !playerState.stats.autoLevelEnabled
      ? String(playerState.stats.currentLevel)
      : AUTO_LEVEL

  const autoActiveLevel =
    playerState.stats?.autoLevelEnabled && playerState.stats.currentLevel >= 0
      ? playerState.levels[playerState.stats.currentLevel]
      : undefined
  const autoActiveLevelLabel =
    autoActiveLevel?.width && autoActiveLevel.height
      ? formatQuality(autoActiveLevel.width, autoActiveLevel.height)
      : null
  const currentLevel =
    currentLevelValue === AUTO_LEVEL
      ? undefined
      : playerState.levels[Number(currentLevelValue)]

  const displayPlayheadTime = seeking ? seekValue : currentTime
  const playedFraction =
    duration > 0 ? Math.min(1, Math.max(0, displayPlayheadTime / duration)) : 0

  const playheadX =
    trackWidth > 0 ? seekThumbCenterX(playedFraction, trackWidth) : 0

  const scrubHoverActive =
    scrubPreview != null &&
    !seeking &&
    Math.abs(scrubPreview.x - playheadX) > 1.5

  const scrubPopoverLeft =
    scrubPreview && trackWidth > 0
      ? clampScrubPopoverLeft(
          scrubPreview.x,
          trackWidth,
          SCRUB_PREVIEW_WIDTH_PX,
        )
      : 0

  const onSeekKeyDown = (event: KeyboardEvent) => {
    if (!(duration > 0)) return
    const step = Math.max(0.5, duration / 100)
    let next = displayPlayheadTime
    if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') {
      next = Math.max(0, displayPlayheadTime - step)
    } else if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
      next = Math.min(duration, displayPlayheadTime + step)
    } else if (event.key === 'Home') {
      next = 0
    } else if (event.key === 'End') {
      next = duration
    } else {
      return
    }
    event.preventDefault()
    setSeeking(true)
    setSeekValue(next)
    const video = videoRef.current
    if (video) video.currentTime = next
    setSeeking(false)
    syncFromVideo()
  }

  return (
    <PortalContainerContext.Provider value={portalContainer}>
      <OverlayContext.Provider value={fullscreen}>
        <div
          className={cn('@container relative shrink-0 pt-3', className)}
          onPointerDownCapture={(event) => {
            // Select and menu triggers cancel pointerdown, so focus would stay on the previous control.
            const active = document.activeElement
            if (
              active instanceof HTMLElement &&
              event.target instanceof Node &&
              !active.contains(event.target)
            ) {
              active.blur()
            }
          }}
        >
          <div className="space-y-2.5">
            <div
              ref={seekTrackRef}
              role="slider"
              tabIndex={duration > 0 ? 0 : -1}
              aria-label={t('Seek')}
              aria-valuemin={0}
              aria-valuemax={duration > 0 ? duration : 0}
              aria-valuenow={displayPlayheadTime}
              aria-disabled={!(duration > 0)}
              className={cn(
                'relative z-0 h-8 w-full touch-none select-none overflow-visible',
                duration > 0
                  ? 'cursor-pointer'
                  : 'cursor-not-allowed opacity-40',
              )}
              onKeyDown={onSeekKeyDown}
              onPointerDown={(event) => {
                if (!(duration > 0)) return
                seekDraggingRef.current = true
                event.currentTarget.setPointerCapture(event.pointerId)
                seekFromClientX(event.clientX)
              }}
              onPointerMove={(event) => {
                if (seekDraggingRef.current) {
                  seekFromClientX(event.clientX)
                  return
                }
                if (hasScrubCues) {
                  updateScrubPreviewFromClientX(event.clientX)
                }
              }}
              onPointerUp={(event) => {
                if (!seekDraggingRef.current) return
                event.currentTarget.releasePointerCapture(event.pointerId)
                commitSeek()
              }}
              onPointerCancel={() => {
                if (!seekDraggingRef.current) return
                commitSeek()
              }}
              onPointerLeave={() => {
                if (!seekDraggingRef.current) setScrubPreview(null)
              }}
            >
              <div
                aria-hidden
                className={cn(
                  'pointer-events-none absolute inset-x-0 top-1/2 h-1.5 -translate-y-1/2 rounded-full',
                  fullscreen ? 'bg-white/25 backdrop-blur-md' : 'bg-border',
                )}
              />
              <div
                aria-hidden
                className={cn(
                  'pointer-events-none absolute left-0 top-1/2 h-1.5 -translate-y-1/2 rounded-full',
                  fullscreen ? 'bg-white' : 'bg-foreground/60',
                )}
                style={{ width: `${playedFraction * 100}%` }}
              />

              {scrubHoverActive && scrubPreview ? (
                <div
                  aria-hidden
                  className="pointer-events-none absolute top-1/2 z-10 h-4 w-px -translate-x-1/2 -translate-y-1/2 bg-foreground/45"
                  style={{ left: scrubPreview.x }}
                />
              ) : null}

              {duration > 0 ? (
                <div
                  aria-hidden
                  className="pointer-events-none absolute top-1/2 z-20 -translate-x-1/2 -translate-y-1/2"
                  style={{ left: playheadX }}
                >
                  <div
                    className={cn(
                      'size-3 rounded-full border shadow-sm',
                      fullscreen
                        ? 'border-white/40 bg-white'
                        : 'border-border bg-foreground',
                    )}
                  />
                </div>
              ) : null}

              {scrubPreview ? (
                <div
                  className="pointer-events-none absolute bottom-full z-30 mb-2 w-0"
                  style={{ left: scrubPopoverLeft }}
                >
                  <div
                    className="absolute bottom-0 left-0 -translate-x-1/2"
                    style={{ width: SCRUB_PREVIEW_WIDTH_PX }}
                  >
                    <div
                      className={cn(
                        'overflow-hidden rounded-md border border-border bg-popover shadow-md',
                        fullscreen &&
                          `${OVERLAY_SURFACE_CLASS} [&_p]:text-white/80`,
                      )}
                    >
                      <TimelineSpriteThumb
                        cue={scrubPreview.cue}
                        width={SCRUB_PREVIEW_WIDTH_PX}
                      />
                      <p className="px-2 py-1 text-center text-[11px] tabular-nums text-muted-foreground">
                        {formatPlaybackTime(scrubPreview.time)}
                      </p>
                    </div>
                    <div
                      className={cn(
                        'size-2 -translate-x-1/2 -translate-y-1/2 rotate-45 border-b border-r border-border bg-popover',
                        fullscreen && 'hidden',
                      )}
                      style={{
                        marginLeft: Math.min(
                          SCRUB_PREVIEW_WIDTH_PX - SCRUB_ARROW_INSET_PX,
                          Math.max(
                            SCRUB_ARROW_INSET_PX,
                            SCRUB_PREVIEW_WIDTH_PX / 2 +
                              scrubPreview.x -
                              scrubPopoverLeft,
                          ),
                        ),
                      }}
                    />
                  </div>
                </div>
              ) : null}
            </div>

            <div className="flex flex-wrap items-center gap-x-2 gap-y-2">
              <Tooltip>
                <TooltipTrigger asChild>
                  <Button
                    type="button"
                    variant="secondary"
                    size="icon"
                    className={cn(
                      'h-8 w-8 shrink-0',
                      fullscreen &&
                        'border-white/20 bg-white/20 text-white backdrop-blur-md hover:bg-white/30',
                    )}
                    onClick={togglePlay}
                    aria-label={playing ? t('Pause') : t('Play')}
                  >
                    {isLoading ? (
                      <Loader2
                        className={cn(
                          'h-4 w-4 animate-spin',
                          fullscreen
                            ? 'text-white/70'
                            : 'text-muted-foreground',
                        )}
                      />
                    ) : playing ? (
                      <Pause className="h-4 w-4" />
                    ) : (
                      <Play className="h-4 w-4" />
                    )}
                  </Button>
                </TooltipTrigger>
                <TooltipContent
                  className={cn(
                    'text-[12px]',
                    fullscreen && OVERLAY_TOOLTIP_CLASS,
                  )}
                  arrow={!fullscreen}
                  sideOffset={fullscreen ? OVERLAY_TOOLTIP_OFFSET : undefined}
                  container={portalContainer}
                >
                  {playing ? t('Pause') : t('Play')}
                </TooltipContent>
              </Tooltip>

              <IconControl label={t('Go to start')} onClick={goToStart}>
                <SkipBack className="h-3.5 w-3.5" />
              </IconControl>
              <IconControl label={t('Go to end')} onClick={goToEnd}>
                <SkipForward className="h-3.5 w-3.5" />
              </IconControl>

              <ControlsSeparator />

              <VolumeControl
                videoRef={videoRef}
                loadStartedAt={playerState.loadStartedAt}
              />

              <ControlsSeparator />

              <span
                className={cn(
                  'shrink-0 text-[12px] tabular-nums',
                  fullscreen
                    ? 'text-white [text-shadow:0_1px_3px_rgb(0_0_0/0.6)]'
                    : 'text-muted-foreground',
                )}
              >
                {formatPlaybackTime(currentTime)}
                <span
                  className={
                    fullscreen ? 'text-white/60' : 'text-muted-foreground/60'
                  }
                >
                  {' / '}
                </span>
                {duration > 0 ? formatPlaybackTime(duration) : '--:--'}
              </span>

              <div className="hidden items-center gap-2 @5xl:flex">
                <ControlsSeparator />

                <PlaybackFormatSelect
                  value={output}
                  options={outputOptions}
                  onChange={onOutputChange}
                  {...menuState('format')}
                />

                {showQuality ? (
                  <Select
                    value={currentLevelValue}
                    onValueChange={(value) => onSelectLevel(Number(value))}
                    {...menuState('quality')}
                  >
                    <SelectTrigger
                      size="sm"
                      className={cn(
                        'h-8 w-[min(100%,11rem)] text-[12px]',
                        fullscreen && OVERLAY_CHIP_CLASS,
                      )}
                      aria-label={t('Quality')}
                    >
                      <SelectValue>
                        {currentLevel ? (
                          <QualityLabel
                            width={currentLevel.width}
                            height={currentLevel.height}
                          />
                        ) : (
                          <>
                            {t('Auto quality')}
                            {autoActiveLevelLabel ? (
                              <span className="text-muted-foreground">
                                {' · '}
                                {autoActiveLevelLabel}
                              </span>
                            ) : null}
                          </>
                        )}
                      </SelectValue>
                    </SelectTrigger>
                    <SelectContent
                      container={portalContainer}
                      className={cn(fullscreen && OVERLAY_MENU_CLASS)}
                    >
                      <SelectItem value={AUTO_LEVEL} className="text-[12px]">
                        {t('Auto quality')}
                        {autoActiveLevelLabel ? (
                          <span className="text-muted-foreground">
                            {' · '}
                            {autoActiveLevelLabel}
                          </span>
                        ) : null}
                      </SelectItem>
                      {playerState.levels.map((level) => (
                        <SelectItem
                          key={level.index}
                          value={String(level.index)}
                          className="text-[12px]"
                        >
                          <QualityLabel
                            width={level.width}
                            height={level.height}
                            bitrate={level.bitrate}
                          />
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                ) : null}

                {playerState.subtitleTracks.length > 0 ? (
                  <Select
                    value={String(playerState.currentSubtitleTrack)}
                    onValueChange={(value) =>
                      onSelectSubtitleTrack(Number(value))
                    }
                    {...menuState('subtitles')}
                  >
                    <SelectTrigger
                      size="sm"
                      className={cn(
                        'h-8 w-[min(100%,10rem)] text-[12px]',
                        fullscreen && OVERLAY_CHIP_CLASS,
                      )}
                      aria-label={t('Subtitles')}
                    >
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent
                      container={portalContainer}
                      className={cn(fullscreen && OVERLAY_MENU_CLASS)}
                    >
                      <SelectItem value={SUBTITLES_OFF} className="text-[12px]">
                        {t('Subtitles off')}
                      </SelectItem>
                      {playerState.subtitleTracks.map((track) => (
                        <SelectItem
                          key={track.id}
                          value={String(track.id)}
                          className="text-[12px]"
                        >
                          {track.name}
                          {track.lang ? ` (${track.lang})` : ''}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                ) : null}

                {onOpenInspector ? (
                  <>
                    <ControlsSeparator />
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className={cn(
                        'h-8 shrink-0 gap-1.5 text-[12px]',
                        fullscreen && OVERLAY_CHIP_CLASS,
                      )}
                      onClick={onOpenInspector}
                    >
                      <Activity className="h-3.5 w-3.5" />
                      {t('Inspector')}
                      <SquareArrowOutUpRight className="h-3 w-3 text-muted-foreground" />
                    </Button>
                  </>
                ) : null}

                {hasNewRenditions ? (
                  <span
                    className={cn(
                      'text-[12px]',
                      fullscreen ? 'text-white/80' : 'text-muted-foreground',
                    )}
                  >
                    {t('New renditions are ready.')}
                  </span>
                ) : null}
              </div>

              <div className="ms-auto flex shrink-0 items-center gap-2">
                <ControlsSeparator />
                <div className="hidden @5xl:flex">
                  <IconControl label={t('Reload stream')} onClick={onReload}>
                    <RefreshCw className="h-3.5 w-3.5" />
                  </IconControl>
                </div>
                <DropdownMenu modal={false} {...menuState('more')}>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <DropdownMenuTrigger asChild>
                        <Button
                          type="button"
                          variant="outline"
                          size="icon"
                          className={cn(
                            'relative h-8 w-8 shrink-0 @5xl:hidden',
                            fullscreen && OVERLAY_CHIP_CLASS,
                          )}
                          aria-label={t('More options')}
                        >
                          <MoreHorizontal className="h-3.5 w-3.5" />
                          {hasNewRenditions ? (
                            <span
                              aria-hidden
                              className="absolute end-1 top-1 size-1.5 rounded-full bg-primary"
                            />
                          ) : null}
                        </Button>
                      </DropdownMenuTrigger>
                    </TooltipTrigger>
                    <TooltipContent
                      className={cn(
                        'text-[12px]',
                        fullscreen && OVERLAY_TOOLTIP_CLASS,
                      )}
                      arrow={!fullscreen}
                      sideOffset={
                        fullscreen ? OVERLAY_TOOLTIP_OFFSET : undefined
                      }
                      container={portalContainer}
                    >
                      {t('More options')}
                    </TooltipContent>
                  </Tooltip>
                  <DropdownMenuContent
                    align="end"
                    side="top"
                    container={portalContainer}
                    className={cn(
                      'min-w-[13rem]',
                      fullscreen && OVERLAY_MENU_CLASS,
                    )}
                  >
                    <DropdownMenuSub>
                      <DropdownMenuSubTrigger className="text-[13px]">
                        <MenuItemIcon icon={Film} />
                        <span className="min-w-0 flex-1">
                          {t('Stream format')}
                        </span>
                        <span className="text-[12px] text-muted-foreground">
                          {outputOptions.find((option) => option.id === output)
                            ?.label ?? output}
                        </span>
                      </DropdownMenuSubTrigger>
                      <DropdownMenuSubContent
                        className={cn(fullscreen && OVERLAY_MENU_CLASS)}
                      >
                        <DropdownMenuRadioGroup
                          value={output}
                          onValueChange={(next) =>
                            onOutputChange(next as PlaybackOutput)
                          }
                        >
                          {outputOptions.map((option) => (
                            <DropdownMenuRadioItem
                              key={option.id}
                              value={option.id}
                              disabled={Boolean(option.disabledReason)}
                              className="text-[13px]"
                            >
                              <span className="flex flex-col">
                                <span>{option.label}</span>
                                {option.disabledReason ? (
                                  <span className="text-[11px] text-muted-foreground">
                                    {option.disabledReason}
                                  </span>
                                ) : null}
                              </span>
                            </DropdownMenuRadioItem>
                          ))}
                        </DropdownMenuRadioGroup>
                      </DropdownMenuSubContent>
                    </DropdownMenuSub>

                    {showQuality ? (
                      <DropdownMenuSub>
                        <DropdownMenuSubTrigger className="text-[13px]">
                          <MenuItemIcon icon={Gauge} />
                          <span className="min-w-0 flex-1">{t('Quality')}</span>
                          <span className="text-[12px] text-muted-foreground">
                            {currentLevel ? (
                              <QualityLabel
                                width={currentLevel.width}
                                height={currentLevel.height}
                              />
                            ) : (
                              (autoActiveLevelLabel ?? t('Auto quality'))
                            )}
                          </span>
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent
                          className={cn(fullscreen && OVERLAY_MENU_CLASS)}
                        >
                          <DropdownMenuRadioGroup
                            value={currentLevelValue}
                            onValueChange={(value) =>
                              onSelectLevel(Number(value))
                            }
                          >
                            <DropdownMenuRadioItem
                              value={AUTO_LEVEL}
                              className="text-[13px]"
                            >
                              {t('Auto quality')}
                              {autoActiveLevelLabel ? (
                                <span className="text-muted-foreground">
                                  {' · '}
                                  {autoActiveLevelLabel}
                                </span>
                              ) : null}
                            </DropdownMenuRadioItem>
                            {playerState.levels.map((level) => (
                              <DropdownMenuRadioItem
                                key={level.index}
                                value={String(level.index)}
                                className="text-[13px]"
                              >
                                <QualityLabel
                                  width={level.width}
                                  height={level.height}
                                  bitrate={level.bitrate}
                                />
                              </DropdownMenuRadioItem>
                            ))}
                          </DropdownMenuRadioGroup>
                        </DropdownMenuSubContent>
                      </DropdownMenuSub>
                    ) : null}

                    {playerState.subtitleTracks.length > 0 ? (
                      <DropdownMenuSub>
                        <DropdownMenuSubTrigger className="text-[13px]">
                          <MenuItemIcon icon={Captions} />
                          <span className="min-w-0 flex-1">
                            {t('Subtitles')}
                          </span>
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent
                          className={cn(fullscreen && OVERLAY_MENU_CLASS)}
                        >
                          <DropdownMenuRadioGroup
                            value={String(playerState.currentSubtitleTrack)}
                            onValueChange={(value) =>
                              onSelectSubtitleTrack(Number(value))
                            }
                          >
                            <DropdownMenuRadioItem
                              value={SUBTITLES_OFF}
                              className="text-[13px]"
                            >
                              {t('Subtitles off')}
                            </DropdownMenuRadioItem>
                            {playerState.subtitleTracks.map((track) => (
                              <DropdownMenuRadioItem
                                key={track.id}
                                value={String(track.id)}
                                className="text-[13px]"
                              >
                                {track.name}
                                {track.lang ? ` (${track.lang})` : ''}
                              </DropdownMenuRadioItem>
                            ))}
                          </DropdownMenuRadioGroup>
                        </DropdownMenuSubContent>
                      </DropdownMenuSub>
                    ) : null}

                    <DropdownMenuSeparator />

                    {onOpenInspector ? (
                      <DropdownMenuItem
                        className="text-[13px]"
                        onSelect={onOpenInspector}
                      >
                        <MenuItemContent icon={Activity}>
                          {t('Inspector')}
                        </MenuItemContent>
                        <SquareArrowOutUpRight className="h-3 w-3 text-muted-foreground" />
                      </DropdownMenuItem>
                    ) : null}
                    <DropdownMenuItem
                      className="text-[13px]"
                      onSelect={onReload}
                    >
                      <MenuItemIcon icon={RefreshCw} />
                      <span className="flex min-w-0 flex-1 flex-col">
                        <span>{t('Reload stream')}</span>
                        {hasNewRenditions ? (
                          <span className="text-[11px] text-muted-foreground">
                            {t('New renditions are ready.')}
                          </span>
                        ) : null}
                      </span>
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
                <IconControl
                  label={fullscreen ? t('Exit full screen') : t('Full screen')}
                  onClick={onToggleFullscreen}
                >
                  {fullscreen ? (
                    <Minimize className="h-3.5 w-3.5" />
                  ) : (
                    <Maximize className="h-3.5 w-3.5" />
                  )}
                </IconControl>
              </div>
            </div>
          </div>
        </div>
      </OverlayContext.Provider>
    </PortalContainerContext.Provider>
  )
}
