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
  Loader2,
  Maximize,
  Minimize,
  Pause,
  Play,
  RefreshCw,
  SquareArrowOutUpRight,
  Volume1,
  Volume2,
  VolumeX,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Slider } from '@/components/ui/slider'
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
  formatResolution,
} from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import type { StreamPlayerState } from './useStreamPlayer'
import { TimelineSpriteThumb } from './TimelineSpriteThumb'

const AUTO_LEVEL = '-1'
const SUBTITLES_OFF = '-1'

function ControlsSeparator() {
  return (
    <div
      className="mx-0.5 hidden h-4 w-px shrink-0 bg-border sm:block"
      aria-hidden
    />
  )
}
const SCRUB_PREVIEW_WIDTH_PX = 160
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
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="icon"
          className="h-8 w-8 shrink-0"
          onClick={onClick}
          aria-label={label}
        >
          {children}
        </Button>
      </TooltipTrigger>
      <TooltipContent className="text-[12px]" container={portalContainer}>
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
        className="hidden w-20 sm:flex"
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
}: {
  value: PlaybackOutput
  options: OutputOption[]
  onChange: (output: PlaybackOutput) => void
}) {
  const t = useT()
  const portalContainer = useContext(PortalContainerContext)

  return (
    <Select
      value={value}
      onValueChange={(next) => onChange(next as PlaybackOutput)}
    >
      <SelectTrigger
        size="sm"
        className="h-8 w-[9.5rem] text-[12px]"
        aria-label={t('Stream format')}
      >
        <SelectValue />
      </SelectTrigger>
      <SelectContent container={portalContainer}>
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
      <div className={cn('relative shrink-0 pt-3', className)}>
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
              duration > 0 ? 'cursor-pointer' : 'cursor-not-allowed opacity-40',
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
              className="pointer-events-none absolute inset-x-0 top-1/2 h-1.5 -translate-y-1/2 rounded-full bg-border"
            />
            <div
              aria-hidden
              className="pointer-events-none absolute left-0 top-1/2 h-1.5 -translate-y-1/2 rounded-full bg-foreground/60"
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
                <div className="size-3 rounded-full border border-border bg-foreground shadow-sm" />
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
                  <div className="overflow-hidden rounded-md border border-border bg-popover shadow-md">
                    <TimelineSpriteThumb
                      cue={scrubPreview.cue}
                      width={SCRUB_PREVIEW_WIDTH_PX}
                    />
                    <p className="px-2 py-1 text-center text-[11px] tabular-nums text-muted-foreground">
                      {formatPlaybackTime(scrubPreview.time)}
                    </p>
                  </div>
                  <div
                    className="mx-auto size-2 -translate-y-1/2 rotate-45 border-b border-r border-border bg-popover"
                    style={{
                      marginLeft: scrubPreview.x - scrubPopoverLeft,
                    }}
                  />
                </div>
              </div>
            ) : null}
          </div>

          <div className="flex flex-wrap items-center gap-x-2 gap-y-2">
            <Button
              type="button"
              variant="secondary"
              size="icon"
              className="h-8 w-8 shrink-0"
              onClick={togglePlay}
              aria-label={playing ? t('Pause') : t('Play')}
            >
              {isLoading ? (
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
              ) : playing ? (
                <Pause className="h-4 w-4" />
              ) : (
                <Play className="h-4 w-4" />
              )}
            </Button>

            <ControlsSeparator />

            <VolumeControl
              videoRef={videoRef}
              loadStartedAt={playerState.loadStartedAt}
            />

            <ControlsSeparator />

            <span className="shrink-0 text-[12px] tabular-nums text-muted-foreground">
              {formatPlaybackTime(currentTime)}
              <span className="text-muted-foreground/60"> / </span>
              {duration > 0 ? formatPlaybackTime(duration) : '--:--'}
            </span>

            <ControlsSeparator />

            <PlaybackFormatSelect
              value={output}
              options={outputOptions}
              onChange={onOutputChange}
            />

            {showQuality ? (
              <Select
                value={currentLevelValue}
                onValueChange={(value) => onSelectLevel(Number(value))}
              >
                <SelectTrigger
                  size="sm"
                  className="h-8 w-[min(100%,11rem)] text-[12px]"
                  aria-label={t('Quality')}
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent container={portalContainer}>
                  <SelectItem value={AUTO_LEVEL} className="text-[12px]">
                    {t('Auto quality')}
                  </SelectItem>
                  {playerState.levels.map((level) => (
                    <SelectItem
                      key={level.index}
                      value={String(level.index)}
                      className="text-[12px]"
                    >
                      {formatResolution(level.width, level.height)} ·{' '}
                      {formatBitrate(level.bitrate)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            ) : null}

            {playerState.subtitleTracks.length > 0 ? (
              <Select
                value={String(playerState.currentSubtitleTrack)}
                onValueChange={(value) => onSelectSubtitleTrack(Number(value))}
              >
                <SelectTrigger
                  size="sm"
                  className="h-8 w-[min(100%,10rem)] text-[12px]"
                  aria-label={t('Subtitles')}
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent container={portalContainer}>
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
                  className="h-8 shrink-0 gap-1.5 text-[12px]"
                  onClick={onOpenInspector}
                >
                  <Activity className="h-3.5 w-3.5" />
                  {t('Stream inspector')}
                  <SquareArrowOutUpRight className="h-3 w-3 text-muted-foreground" />
                </Button>
              </>
            ) : null}

            {hasNewRenditions ? (
              <span className="text-[12px] text-muted-foreground">
                {t('New renditions are ready.')}
              </span>
            ) : null}

            <div className="ms-auto flex shrink-0 items-center gap-2">
              <ControlsSeparator />
              <IconControl label={t('Reload stream')} onClick={onReload}>
                <RefreshCw className="h-3.5 w-3.5" />
              </IconControl>
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
    </PortalContainerContext.Provider>
  )
}
