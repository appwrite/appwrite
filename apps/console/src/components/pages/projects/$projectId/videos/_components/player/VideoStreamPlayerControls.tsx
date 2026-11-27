import * as DropdownMenuPrimitive from '@radix-ui/react-dropdown-menu'
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type ComponentProps,
  type CSSProperties,
  type KeyboardEvent,
  type PointerEvent as ReactPointerEvent,
  type ReactNode,
  type RefObject,
} from 'react'
import {
  Activity,
  ALargeSmall,
  AudioLines,
  Captions,
  Check,
  ChevronLeft,
  ChevronRight,
  Film,
  Gauge,
  Keyboard,
  Maximize,
  Minimize,
  Pause,
  PictureInPicture2,
  Play,
  RefreshCw,
  RotateCcw,
  Settings,
  SlidersHorizontal,
  SquareArrowOutUpRight,
  Volume1,
  Volume2,
  VolumeX,
  type LucideIcon,
} from 'lucide-react'
import { Slider } from '@/components/ui/slider'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import type { VideoTimelineCue } from '@/lib/react-query/hooks/videos'
import { findTimelineCueAtTime } from '@/lib/videos/timeline-cues'
import type { PlaybackOutput } from '@/lib/videos/playback-output'
import { usePageDirection } from '@/lib/layout/page-direction'
import { cn } from '@/lib/utils'
import {
  formatBitrate,
  formatPlayerClock,
  formatQuality,
} from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import type { StreamPlayerState } from './useStreamPlayer'
import { TimelineSpriteThumb } from './TimelineSpriteThumb'
import {
  VIDEO_SUBTITLE_SIZES,
  videoSubtitleSizeLabelKey,
  type VideoSubtitleSize,
} from '@/lib/videos/player-subtitle-display'

export type { PlaybackOutput }

/** Feedback over the picture for an action taken from the keyboard. */
export type PlayerFlash = 'play' | 'pause' | 'back' | 'forward'

const AUTO_LEVEL = '-1'
const SUBTITLES_OFF = '-1'
const PLAYBACK_RATES = [0.5, 0.75, 1, 1.25, 1.5, 2] as const
/** Arrow keys seek this far; J and L twice as far. */
const SEEK_STEP_SECONDS = 5
const VOLUME_STEP = 0.05
/** `,` and `.` step this far while paused: one frame at 30 fps. */
const FRAME_STEP_SECONDS = 1 / 30

/**
 * Round icon button on the picture. Sizes follow the player's width (the
 * controls are a container), so a small embed gets small controls.
 */
const CONTROL_BUTTON_CLASS =
  'relative inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-full text-white/90 outline-none transition-[background-color,color,transform] duration-150 ease-out hover:bg-white/12 hover:text-white active:scale-[0.94] focus-visible:ring-2 focus-visible:ring-white/70 disabled:pointer-events-none disabled:opacity-40 @md:size-9 @3xl:size-10 @7xl:size-11 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:drop-shadow-[0_1px_2px_rgb(0_0_0/0.35)] @md:[&_svg]:size-[18px] @3xl:[&_svg]:size-5 @7xl:[&_svg]:size-[22px]'

const TOOLTIP_CLASS =
  'flex items-center gap-1.5 rounded-md border border-white/10 bg-neutral-950/85 px-2 py-1 text-[12px] font-medium text-white shadow-lg backdrop-blur-md'

const KBD_CLASS =
  'rounded-[4px] border border-white/15 bg-white/10 px-1 font-sans text-[10px] font-medium leading-[14px] text-white/70'

/** Glass panel for the settings menu. */
const PANEL_CLASS =
  'z-[150] w-[15.5rem] max-w-[calc(100vw-1rem)] rounded-xl border-white/10 bg-neutral-950/80 p-1 text-white shadow-[0_24px_60px_-12px_rgb(0_0_0/0.65)] backdrop-blur-2xl backdrop-saturate-150 supports-[backdrop-filter]:bg-neutral-950/65'

const MENU_ROW_CLASS =
  'flex min-h-9 w-full cursor-pointer select-none items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-[13px] text-white/90 outline-none transition-colors duration-100 data-[highlighted]:bg-white/10 data-[highlighted]:text-white data-[disabled]:cursor-default data-[disabled]:opacity-40'

/**
 * The scrim fades from the bottom edge up past the controls, eased so it has
 * no visible top edge on a bright picture.
 */
const SCRIM_CLASS =
  'pointer-events-none absolute inset-x-0 -top-14 bottom-0 bg-[linear-gradient(to_top,rgb(0_0_0/0.74)_0%,rgb(0_0_0/0.5)_30%,rgb(0_0_0/0.2)_62%,transparent_100%)] @3xl:-top-24'

const SEEK_THUMB_PX = 14
const PREVIEW_WIDTH_PX = 176
/** Half the width of the time-only preview, enough for `h:mm:ss`. */
const PREVIEW_PILL_HALF_PX = 30
const PREVIEW_EDGE_PX = 4

function clamp(value: number, min: number, max: number) {
  return Math.min(max, Math.max(min, value))
}

function seekFractionFromClientX(clientX: number, trackRect: DOMRect): number {
  if (trackRect.width <= 0) return 0
  return clamp((clientX - trackRect.left) / trackRect.width, 0, 1)
}

/** Keep the scrub thumb inside the track (no half-dot hanging past the edges). */
function clampSeekThumbCenterX(fraction: number, trackWidth: number): number {
  if (trackWidth <= 0) return 0
  const half = SEEK_THUMB_PX / 2
  return clamp(fraction * trackWidth, half, trackWidth - half)
}

/** End of the buffered range that holds the playhead. */
function bufferedEnd(video: HTMLVideoElement): number {
  const { buffered, currentTime } = video
  for (let index = 0; index < buffered.length; index++) {
    if (
      buffered.start(index) <= currentTime + 0.5 &&
      buffered.end(index) >= currentTime
    ) {
      return buffered.end(index)
    }
  }
  return 0
}

/** `HD` and `4K` next to a quality, as players label them. */
function qualityBadge(width: number, height: number): string | null {
  const edge = Math.min(width, height)
  if (edge >= 2160) return '4K'
  if (edge >= 720) return 'HD'
  return null
}

function formatRate(rate: number) {
  return `${rate}×`
}

/**
 * A shortcut is left to the focused element when it would type, move a
 * slider or press a button, and to any open menu.
 */
function shortcutAllowedFrom(target: EventTarget | null, key: string) {
  if (!(target instanceof Element)) return true
  if (
    target.closest(
      'input, textarea, select, [contenteditable=""], [contenteditable="true"], [role="menu"], [role="listbox"], [role="combobox"]',
    )
  ) {
    return false
  }
  if (
    (key === ' ' || key === 'Enter') &&
    target.closest(
      'button, a, [role="button"], [role="tab"], [role="switch"], [role="checkbox"]',
    )
  ) {
    return false
  }
  const navigates =
    key.startsWith('Arrow') ||
    key === 'Home' ||
    key === 'End' ||
    key === 'PageUp' ||
    key === 'PageDown'
  return !(
    navigates &&
    target.closest('[role="slider"], [role="tablist"], [role="radiogroup"]')
  )
}

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
  onSelectAudioTrack: (id: number) => void
  subtitleSize: VideoSubtitleSize
  onSelectSubtitleSize: (size: VideoSubtitleSize) => void
  onReload: () => void
  hasNewRenditions: boolean
  scrubCues?: VideoTimelineCue[]
  /** Browser fullscreen. */
  fullscreen: boolean
  onToggleFullscreen: () => void
  onOpenInspector?: () => void
  /** Fullscreen element; overlays must portal into it to stay visible. */
  portalContainer?: HTMLElement
  /**
   * Where keyboard shortcuts listen: the whole page on the player page, or
   * only while focus is inside the player.
   */
  shortcuts?: 'page' | 'player'
  /** Audio-only sources have no picture to float. */
  pictureInPicture?: boolean
  /** The settings menu opened or closed; controls stay up while it is open. */
  onMenuOpenChange?: (open: boolean) => void
  /** A keyboard shortcut ran; `flash` is the feedback to show, if any. */
  onShortcut?: (flash: PlayerFlash | null) => void
  /** Played progress and volume color; white when unset. */
  accentColor?: string
  className?: string
}

const PortalContainerContext = createContext<HTMLElement | undefined>(undefined)

function ControlTooltip({
  label,
  shortcut,
  disabled = false,
  children,
}: {
  label: string
  shortcut?: string
  disabled?: boolean
  children: ReactNode
}) {
  const portalContainer = useContext(PortalContainerContext)
  return (
    <Tooltip open={disabled ? false : undefined}>
      <TooltipTrigger asChild>{children}</TooltipTrigger>
      <TooltipContent
        side="top"
        sideOffset={10}
        arrow={false}
        container={portalContainer}
        className={TOOLTIP_CLASS}
      >
        {label}
        {shortcut ? <kbd className={KBD_CLASS}>{shortcut}</kbd> : null}
      </TooltipContent>
    </Tooltip>
  )
}

function ControlButton({ className, ...props }: ComponentProps<'button'>) {
  return (
    <button
      type="button"
      className={cn(CONTROL_BUTTON_CLASS, className)}
      {...props}
    />
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
  const label = silent ? t('Unmute') : t('Mute')

  return (
    <div className="group/volume flex shrink-0 items-center">
      <ControlTooltip label={label} shortcut="M">
        <ControlButton
          aria-label={label}
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
          <Icon />
        </ControlButton>
      </ControlTooltip>
      {/* Opens beside the button on hover or focus, the way players keep the bar quiet. */}
      <div className="hidden w-0 overflow-hidden opacity-0 transition-[width,opacity] duration-200 ease-out group-focus-within/volume:w-[5.5rem] group-focus-within/volume:opacity-100 group-hover/volume:w-[5.5rem] group-hover/volume:opacity-100 @md:block">
        <div className="w-[5.5rem] px-2.5">
          <Slider
            className="h-6 [&_[data-slot=slider-range]]:bg-[var(--player-accent,#ffffff)] [&_[data-slot=slider-thumb]]:size-3 [&_[data-slot=slider-thumb]]:border-0 [&_[data-slot=slider-thumb]]:bg-white [&_[data-slot=slider-thumb]]:shadow-[0_1px_3px_rgb(0_0_0/0.45)] [&_[data-slot=slider-thumb]]:hover:ring-0 [&_[data-slot=slider-thumb]:focus-visible]:ring-2 [&_[data-slot=slider-thumb]:focus-visible]:ring-white/50 [&_[data-slot=slider-track]]:h-[3px] [&_[data-slot=slider-track]]:bg-white/25"
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
      </div>
    </div>
  )
}

/**
 * Thin progress line that thickens under the pointer, with the buffered
 * range behind the playhead and the time (or a timeline frame) at the pointer.
 */
function SeekBar({
  duration,
  time,
  buffered,
  cues,
  onScrub,
  onCommit,
}: {
  duration: number
  time: number
  buffered: number
  cues?: VideoTimelineCue[]
  /** While dragging, the time under the pointer; null once released. */
  onScrub: (time: number | null) => void
  onCommit: (time: number) => void
}) {
  const t = useT()
  const trackRef = useRef<HTMLDivElement | null>(null)
  const draggingRef = useRef(false)
  const lastScrubRef = useRef(0)
  const [dragging, setDragging] = useState(false)
  const [width, setWidth] = useState(0)
  const [hoverTime, setHoverTime] = useState<number | null>(null)
  const ready = duration > 0

  useEffect(() => {
    const el = trackRef.current
    if (!el) return
    // Layout width, not the on-screen box: a dialog opening with a zoom
    // measures smaller mid-animation, and a transform never fires a resize,
    // so the playhead would stop short of the end for good.
    const update = () => setWidth(el.offsetWidth)
    update()
    const observer = new ResizeObserver(update)
    observer.observe(el)
    return () => observer.disconnect()
  }, [])

  const fractionOf = (value: number) =>
    ready ? clamp(value / duration, 0, 1) : 0
  const timeAt = (clientX: number) => {
    const el = trackRef.current
    if (!el || !ready) return 0
    return (
      seekFractionFromClientX(clientX, el.getBoundingClientRect()) * duration
    )
  }

  const scrubTo = (next: number) => {
    lastScrubRef.current = next
    setHoverTime(next)
    onScrub(next)
  }

  const release = (commit: number | null) => {
    draggingRef.current = false
    setDragging(false)
    onScrub(null)
    if (commit != null) onCommit(commit)
  }

  const onKeyDown = (event: KeyboardEvent) => {
    if (!ready) return
    let next: number
    switch (event.key) {
      case 'ArrowLeft':
      case 'ArrowDown':
        next = time - SEEK_STEP_SECONDS
        break
      case 'ArrowRight':
      case 'ArrowUp':
        next = time + SEEK_STEP_SECONDS
        break
      case 'PageDown':
        next = time - duration / 10
        break
      case 'PageUp':
        next = time + duration / 10
        break
      case 'Home':
        next = 0
        break
      case 'End':
        next = duration
        break
      default:
        return
    }
    event.preventDefault()
    onCommit(clamp(next, 0, duration))
  }

  const played = fractionOf(time)
  const playheadX = width > 0 ? clampSeekThumbCenterX(played, width) : 0
  const previewCue =
    hoverTime != null && cues?.length
      ? findTimelineCueAtTime(cues, hoverTime)
      : undefined
  const previewHalf = previewCue ? PREVIEW_WIDTH_PX / 2 : PREVIEW_PILL_HALF_PX
  const previewX = hoverTime != null ? fractionOf(hoverTime) * width : 0
  const previewLeft =
    width > previewHalf * 2
      ? clamp(
          previewX,
          previewHalf + PREVIEW_EDGE_PX,
          width - previewHalf - PREVIEW_EDGE_PX,
        )
      : width / 2

  return (
    <div
      ref={trackRef}
      dir="ltr"
      role="slider"
      tabIndex={ready ? 0 : -1}
      aria-label={t('Seek')}
      aria-valuemin={0}
      aria-valuemax={ready ? duration : 0}
      aria-valuenow={time}
      aria-valuetext={`${formatPlayerClock(time)} / ${formatPlayerClock(duration)}`}
      aria-disabled={!ready}
      data-dragging={dragging || undefined}
      className={cn(
        'group/seek relative flex h-5 w-full touch-none select-none items-center outline-none @3xl:h-6',
        ready ? 'cursor-pointer' : 'cursor-default opacity-50',
      )}
      onKeyDown={onKeyDown}
      onPointerDown={(event) => {
        if (!ready || event.button !== 0) return
        draggingRef.current = true
        setDragging(true)
        event.currentTarget.setPointerCapture(event.pointerId)
        scrubTo(timeAt(event.clientX))
      }}
      onPointerMove={(event) => {
        if (!ready) return
        if (draggingRef.current) {
          scrubTo(timeAt(event.clientX))
        } else if (event.pointerType === 'mouse') {
          setHoverTime(timeAt(event.clientX))
        }
      }}
      onPointerUp={(event) => {
        if (!draggingRef.current) return
        event.currentTarget.releasePointerCapture(event.pointerId)
        release(timeAt(event.clientX))
        if (event.pointerType !== 'mouse') setHoverTime(null)
      }}
      onPointerCancel={() => {
        if (!draggingRef.current) return
        release(lastScrubRef.current)
        setHoverTime(null)
      }}
      onPointerLeave={() => {
        if (!draggingRef.current) setHoverTime(null)
      }}
    >
      <div className="relative h-[3px] w-full overflow-hidden rounded-full bg-white/20 transition-[height] duration-150 ease-out group-hover/seek:h-[5px] group-focus-visible/seek:h-[5px] group-data-[dragging]/seek:h-[5px]">
        <div
          aria-hidden
          className="absolute inset-y-0 left-0 bg-white/30 transition-[width] duration-300 ease-out"
          style={{ width: `${fractionOf(buffered) * 100}%` }}
        />
        {hoverTime != null && !dragging ? (
          <div
            aria-hidden
            className="absolute inset-y-0 left-0 bg-white/25"
            style={{ width: previewX }}
          />
        ) : null}
        <div
          aria-hidden
          className="absolute inset-y-0 left-0 bg-[var(--player-accent,#ffffff)]"
          style={{ width: `${played * 100}%` }}
        />
      </div>

      {ready ? (
        <div
          aria-hidden
          className="pointer-events-none absolute top-1/2 size-3.5 -translate-x-1/2 -translate-y-1/2 scale-0 rounded-full bg-[var(--player-accent,#ffffff)] shadow-[0_1px_4px_rgb(0_0_0/0.5)] transition-transform duration-150 ease-out group-hover/seek:scale-100 group-focus-visible/seek:scale-100 group-focus-visible/seek:ring-4 group-focus-visible/seek:ring-white/25 group-data-[dragging]/seek:scale-110"
          style={{ left: playheadX }}
        />
      ) : null}

      {hoverTime != null && ready ? (
        <div
          aria-hidden
          className="pointer-events-none absolute bottom-full z-30 mb-2 flex -translate-x-1/2 flex-col items-center gap-1.5"
          style={{ left: previewLeft }}
        >
          {previewCue ? (
            <div
              className="overflow-hidden rounded-lg bg-black shadow-[0_16px_40px_-12px_rgb(0_0_0/0.7)] ring-1 ring-white/20"
              style={{ width: PREVIEW_WIDTH_PX }}
            >
              <TimelineSpriteThumb cue={previewCue} width={PREVIEW_WIDTH_PX} />
            </div>
          ) : null}
          <span className="rounded-md bg-neutral-950/85 px-1.5 py-0.5 text-[12px] font-semibold tabular-nums text-white ring-1 ring-white/10 backdrop-blur-md">
            {formatPlayerClock(hoverTime)}
          </span>
        </div>
      ) : null}
    </div>
  )
}

type SettingsView =
  | 'main'
  | 'quality'
  | 'speed'
  | 'subtitles'
  | 'size'
  | 'audio'
  | 'format'
  | 'shortcuts'

/** What the keyboard does, listed under Keyboard shortcuts. */
const SHORTCUTS: Array<{ keys: string[]; label: string }> = [
  { keys: ['Space', 'K'], label: 'Play or pause' },
  { keys: ['←', '→'], label: 'Seek 5 seconds' },
  { keys: ['J', 'L'], label: 'Seek 10 seconds' },
  { keys: [',', '.'], label: 'Step one frame' },
  { keys: ['↑', '↓'], label: 'Change volume' },
  { keys: ['M'], label: 'Mute or unmute' },
  { keys: ['<', '>'], label: 'Change playback speed' },
  { keys: ['C'], label: 'Turn subtitles on or off' },
  { keys: ['F'], label: 'Full screen' },
  { keys: ['I'], label: 'Picture in picture' },
  { keys: ['0-9'], label: 'Jump to 0% to 90%' },
  { keys: ['Home', 'End'], label: 'Go to start or end' },
  { keys: ['?'], label: 'Keyboard shortcuts' },
]

function SettingsRow({
  view,
  icon: Icon,
  label,
  value,
  onOpen,
}: {
  view: SettingsView
  icon: LucideIcon
  label: string
  value?: ReactNode
  onOpen: (view: SettingsView) => void
}) {
  return (
    <DropdownMenuPrimitive.Item
      data-settings-row={view}
      className={MENU_ROW_CLASS}
      onSelect={(event) => {
        event.preventDefault()
        onOpen(view)
      }}
    >
      <Icon className="size-4 shrink-0 text-white/60" aria-hidden />
      <span className="min-w-0 flex-1 truncate">{label}</span>
      {value != null ? (
        // Isolated, so `2160p` or `1.5×` keep their order in a right-to-left menu.
        <bdi className="max-w-[45%] shrink-0 truncate text-[12px] text-white/50">
          {value}
        </bdi>
      ) : null}
      <ChevronRight className="size-4 shrink-0 text-white/35" aria-hidden />
    </DropdownMenuPrimitive.Item>
  )
}

function SettingsOption({
  value,
  label,
  badge,
  detail,
  description,
  disabled,
}: {
  value: string
  label: ReactNode
  badge?: string | null
  detail?: string
  description?: string
  disabled?: boolean
}) {
  return (
    <DropdownMenuPrimitive.RadioItem
      value={value}
      disabled={disabled}
      className={MENU_ROW_CLASS}
    >
      <span className="flex size-4 shrink-0 items-center justify-center">
        <DropdownMenuPrimitive.ItemIndicator>
          <Check className="size-4 text-white" strokeWidth={2.25} aria-hidden />
        </DropdownMenuPrimitive.ItemIndicator>
      </span>
      <span className="flex min-w-0 flex-1 flex-col">
        <span className="flex min-w-0 items-center gap-1.5">
          <bdi className="truncate">{label}</bdi>
          {badge ? (
            <span className="rounded-[3px] bg-white/15 px-1 text-[9px] font-semibold leading-[14px] tracking-wide text-white/85">
              {badge}
            </span>
          ) : null}
        </span>
        {description ? (
          <span className="text-[11px] leading-4 text-white/45">
            {description}
          </span>
        ) : null}
      </span>
      {detail ? (
        <bdi className="shrink-0 text-[12px] tabular-nums text-white/45">
          {detail}
        </bdi>
      ) : null}
    </DropdownMenuPrimitive.RadioItem>
  )
}

function SettingsMenu({
  open,
  onOpenChange,
  view,
  onViewChange,
  showShortcuts,
  output,
  outputOptions,
  onOutputChange,
  playerState,
  onSelectLevel,
  onSelectSubtitleTrack,
  onSelectAudioTrack,
  subtitleSize,
  onSelectSubtitleSize,
  playbackRate,
  onSelectPlaybackRate,
  onReload,
  hasNewRenditions,
  onOpenInspector,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** The list showing; the player opens straight to shortcuts on `?`. */
  view: SettingsView
  onViewChange: (view: SettingsView) => void
  /** A keyboard is likely, so the shortcut list is worth a row. */
  showShortcuts: boolean
  output: PlaybackOutput
  outputOptions: OutputOption[]
  onOutputChange: (output: PlaybackOutput) => void
  playerState: StreamPlayerState
  onSelectLevel: (index: number) => void
  onSelectSubtitleTrack: (index: number) => void
  onSelectAudioTrack: (id: number) => void
  subtitleSize: VideoSubtitleSize
  onSelectSubtitleSize: (size: VideoSubtitleSize) => void
  playbackRate: number
  onSelectPlaybackRate: (rate: number) => void
  onReload: () => void
  hasNewRenditions: boolean
  onOpenInspector?: () => void
}) {
  const t = useT()
  const direction = usePageDirection()
  const portalContainer = useContext(PortalContainerContext)
  const contentRef = useRef<HTMLDivElement | null>(null)
  // The list a step back came from, so focus lands on its row again.
  const [cameFrom, setCameFrom] = useState<SettingsView | null>(null)

  const show = (next: SettingsView) => {
    setCameFrom(next === 'main' ? view : null)
    onViewChange(next)
  }

  // Keyboard focus follows the view: the chosen option in a list, and the
  // row it came from on the way back.
  useEffect(() => {
    if (!open) return
    const content = contentRef.current
    if (!content) return
    const frame = requestAnimationFrame(() => {
      const target =
        view === 'main'
          ? cameFrom
            ? content.querySelector<HTMLElement>(
                `[data-settings-row="${cameFrom}"]`,
              )
            : null
          : (content.querySelector<HTMLElement>(
              '[role="menuitemradio"][aria-checked="true"]',
            ) ?? content.querySelector<HTMLElement>('[role^="menuitem"]'))
      target?.focus()
    })
    return () => cancelAnimationFrame(frame)
  }, [open, view, cameFrom])

  const levels = [...playerState.levels].sort(
    (a, b) => b.height - a.height || b.bitrate - a.bitrate,
  )
  const showQuality =
    (playerState.engine === 'shaka' || playerState.engine === 'hls.js') &&
    levels.length > 0
  const currentLevelValue =
    playerState.stats && !playerState.stats.autoLevelEnabled
      ? String(playerState.stats.currentLevel)
      : AUTO_LEVEL
  const currentLevel =
    currentLevelValue === AUTO_LEVEL
      ? undefined
      : playerState.levels.find(
          (level) => String(level.index) === currentLevelValue,
        )
  const autoLevel =
    playerState.stats?.autoLevelEnabled && playerState.stats.currentLevel >= 0
      ? playerState.levels[playerState.stats.currentLevel]
      : undefined
  const autoLevelLabel =
    autoLevel?.width && autoLevel.height
      ? formatQuality(autoLevel.width, autoLevel.height)
      : null
  const qualityValue: ReactNode = currentLevel ? (
    formatQuality(currentLevel.width, currentLevel.height)
  ) : autoLevelLabel ? (
    <>
      {t('Auto')} · <bdi>{autoLevelLabel}</bdi>
    </>
  ) : (
    t('Auto')
  )

  const subtitleTracks = playerState.subtitleTracks
  const currentSubtitle = subtitleTracks.find(
    (track) => track.id === playerState.currentSubtitleTrack,
  )
  const audioTracks = playerState.audioTracks
  const currentAudio = audioTracks.find(
    (track) => track.id === playerState.currentAudioTrack,
  )
  // `HD` or `4K` on the gear for what is playing now, as players mark it.
  const playingLevel = currentLevel ?? autoLevel
  const playingBadge =
    playingLevel?.width && playingLevel.height
      ? qualityBadge(playingLevel.width, playingLevel.height)
      : null
  const formatLabel =
    outputOptions.find((option) => option.id === output)?.label ?? output

  const header = (title: string) => (
    <>
      <DropdownMenuPrimitive.Item
        className={cn(MENU_ROW_CLASS, 'gap-2 font-semibold text-white')}
        onSelect={(event) => {
          event.preventDefault()
          show('main')
        }}
      >
        <ChevronLeft className="size-4 shrink-0 text-white/60" aria-hidden />
        <span className="sr-only">{t('Back')}</span>
        <span className="min-w-0 truncate">{title}</span>
      </DropdownMenuPrimitive.Item>
      <DropdownMenuPrimitive.Separator className="mx-1.5 my-1 h-px bg-white/10" />
    </>
  )

  let body: ReactNode
  switch (view) {
    case 'quality':
      body = (
        <>
          {header(t('Quality'))}
          <DropdownMenuPrimitive.RadioGroup
            value={currentLevelValue}
            onValueChange={(value) => onSelectLevel(Number(value))}
          >
            <SettingsOption
              value={AUTO_LEVEL}
              label={t('Auto')}
              detail={autoLevelLabel ?? undefined}
            />
            {levels.map((level) => (
              <SettingsOption
                key={level.index}
                value={String(level.index)}
                label={
                  level.width && level.height
                    ? formatQuality(level.width, level.height)
                    : formatBitrate(level.bitrate)
                }
                badge={
                  level.width && level.height
                    ? qualityBadge(level.width, level.height)
                    : null
                }
                detail={
                  level.width && level.height && level.bitrate
                    ? formatBitrate(level.bitrate)
                    : undefined
                }
              />
            ))}
          </DropdownMenuPrimitive.RadioGroup>
        </>
      )
      break
    case 'speed':
      body = (
        <>
          {header(t('Playback speed'))}
          <DropdownMenuPrimitive.RadioGroup
            value={String(playbackRate)}
            onValueChange={(value) => onSelectPlaybackRate(Number(value))}
          >
            {PLAYBACK_RATES.map((rate) => (
              <SettingsOption
                key={rate}
                value={String(rate)}
                label={formatRate(rate)}
              />
            ))}
          </DropdownMenuPrimitive.RadioGroup>
        </>
      )
      break
    case 'subtitles':
      body = (
        <>
          {header(t('Subtitles'))}
          <DropdownMenuPrimitive.RadioGroup
            value={String(playerState.currentSubtitleTrack)}
            onValueChange={(value) => onSelectSubtitleTrack(Number(value))}
          >
            <SettingsOption value={SUBTITLES_OFF} label={t('Off')} />
            {subtitleTracks.map((track) => (
              <SettingsOption
                key={track.id}
                value={String(track.id)}
                label={track.name}
                detail={track.lang || undefined}
              />
            ))}
          </DropdownMenuPrimitive.RadioGroup>
        </>
      )
      break
    case 'size':
      body = (
        <>
          {header(t('Subtitle size'))}
          <DropdownMenuPrimitive.RadioGroup
            value={subtitleSize}
            onValueChange={(value) =>
              onSelectSubtitleSize(value as VideoSubtitleSize)
            }
          >
            {VIDEO_SUBTITLE_SIZES.map((size) => (
              <SettingsOption
                key={size}
                value={size}
                label={t(videoSubtitleSizeLabelKey(size))}
              />
            ))}
          </DropdownMenuPrimitive.RadioGroup>
        </>
      )
      break
    case 'audio':
      body = (
        <>
          {header(t('Audio'))}
          <DropdownMenuPrimitive.RadioGroup
            value={String(playerState.currentAudioTrack)}
            onValueChange={(value) => onSelectAudioTrack(Number(value))}
          >
            {audioTracks.map((track) => (
              <SettingsOption
                key={track.id}
                value={String(track.id)}
                label={track.name}
                detail={track.lang || undefined}
              />
            ))}
          </DropdownMenuPrimitive.RadioGroup>
        </>
      )
      break
    case 'shortcuts':
      body = (
        <>
          {header(t('Keyboard shortcuts'))}
          <div className="max-h-[min(22rem,55dvh)] overflow-y-auto px-1 pb-1">
            {SHORTCUTS.map((shortcut) => (
              <div
                key={shortcut.label}
                className="flex min-h-8 items-center justify-between gap-3 px-1.5 text-[12px] text-white/75"
              >
                <span className="min-w-0">{t(shortcut.label)}</span>
                <span dir="ltr" className="flex shrink-0 items-center gap-1">
                  {shortcut.keys.map((key) => (
                    <kbd
                      key={key}
                      className="min-w-5 rounded-[5px] border border-white/15 bg-white/10 px-1.5 text-center font-sans text-[11px] font-medium leading-5 text-white/85"
                    >
                      {key}
                    </kbd>
                  ))}
                </span>
              </div>
            ))}
          </div>
        </>
      )
      break
    case 'format':
      body = (
        <>
          {header(t('Stream format'))}
          <DropdownMenuPrimitive.RadioGroup
            value={output}
            onValueChange={(next) => onOutputChange(next as PlaybackOutput)}
          >
            {outputOptions.map((option) => (
              <SettingsOption
                key={option.id}
                value={option.id}
                label={option.label}
                disabled={Boolean(option.disabledReason)}
                description={option.disabledReason}
              />
            ))}
          </DropdownMenuPrimitive.RadioGroup>
        </>
      )
      break
    default:
      body = (
        <>
          {showQuality ? (
            <SettingsRow
              view="quality"
              icon={SlidersHorizontal}
              label={t('Quality')}
              value={qualityValue}
              onOpen={show}
            />
          ) : null}
          <SettingsRow
            view="speed"
            icon={Gauge}
            label={t('Playback speed')}
            value={formatRate(playbackRate)}
            onOpen={show}
          />
          {subtitleTracks.length > 0 ? (
            <>
              <SettingsRow
                view="subtitles"
                icon={Captions}
                label={t('Subtitles')}
                value={currentSubtitle?.name ?? t('Off')}
                onOpen={show}
              />
              <SettingsRow
                view="size"
                icon={ALargeSmall}
                label={t('Subtitle size')}
                value={t(videoSubtitleSizeLabelKey(subtitleSize))}
                onOpen={show}
              />
            </>
          ) : null}
          {audioTracks.length > 1 ? (
            <SettingsRow
              view="audio"
              icon={AudioLines}
              label={t('Audio')}
              value={currentAudio?.name}
              onOpen={show}
            />
          ) : null}
          <DropdownMenuPrimitive.Separator className="mx-1.5 my-1 h-px bg-white/10" />
          {showShortcuts ? (
            <SettingsRow
              view="shortcuts"
              icon={Keyboard}
              label={t('Keyboard shortcuts')}
              onOpen={show}
            />
          ) : null}
          <SettingsRow
            view="format"
            icon={Film}
            label={t('Stream format')}
            value={formatLabel}
            onOpen={show}
          />
          {onOpenInspector ? (
            <DropdownMenuPrimitive.Item
              className={MENU_ROW_CLASS}
              onSelect={onOpenInspector}
            >
              <Activity className="size-4 shrink-0 text-white/60" aria-hidden />
              <span className="min-w-0 flex-1 truncate">{t('Inspector')}</span>
              <SquareArrowOutUpRight
                className="size-3.5 shrink-0 text-white/40"
                aria-hidden
              />
            </DropdownMenuPrimitive.Item>
          ) : null}
          <DropdownMenuPrimitive.Item
            className={MENU_ROW_CLASS}
            onSelect={onReload}
          >
            <RefreshCw className="size-4 shrink-0 text-white/60" aria-hidden />
            <span className="flex min-w-0 flex-1 flex-col">
              <span className="truncate">{t('Reload stream')}</span>
              {hasNewRenditions ? (
                <span className="text-[11px] leading-4 text-white/50">
                  {t('New renditions are ready.')}
                </span>
              ) : null}
            </span>
            {hasNewRenditions ? (
              <span
                aria-hidden
                className="size-1.5 shrink-0 rounded-full bg-[var(--brand-cta)]"
              />
            ) : null}
          </DropdownMenuPrimitive.Item>
        </>
      )
  }

  const back = direction === 'rtl' ? 'ArrowRight' : 'ArrowLeft'

  return (
    <DropdownMenu
      modal={false}
      open={open}
      onOpenChange={(next) => {
        if (next) {
          onViewChange('main')
          setCameFrom(null)
        }
        onOpenChange(next)
      }}
    >
      <ControlTooltip label={t('Settings')} disabled={open}>
        <DropdownMenuTrigger asChild>
          <ControlButton aria-label={t('Settings')}>
            <Settings
              className={cn(
                'transition-transform duration-300 ease-out',
                open && 'rotate-[30deg]',
              )}
            />
            {playingBadge ? (
              <span
                aria-hidden
                className="pointer-events-none absolute -end-0.5 top-0.5 rounded-[3px] bg-white px-[3px] text-[8px] font-bold leading-[11px] tracking-wide text-neutral-950 shadow-sm @3xl:top-1"
              >
                {playingBadge}
              </span>
            ) : null}
            {hasNewRenditions ? (
              <span
                aria-hidden
                className="absolute start-1.5 top-1.5 size-1.5 rounded-full bg-[var(--brand-cta)] ring-2 ring-black/30"
              />
            ) : null}
          </ControlButton>
        </DropdownMenuTrigger>
      </ControlTooltip>
      <DropdownMenuContent
        ref={contentRef}
        side="top"
        align="end"
        sideOffset={12}
        collisionPadding={8}
        container={portalContainer}
        className={cn(PANEL_CLASS, view === 'shortcuts' && 'w-[18.5rem]')}
        onKeyDown={(event) => {
          if (view !== 'main' && event.key === back) {
            event.preventDefault()
            show('main')
          }
        }}
      >
        <div
          key={view}
          className={cn(
            view !== 'main'
              ? 'animate-in fade-in-0 slide-in-from-right-2 duration-200 rtl:slide-in-from-left-2'
              : cameFrom
                ? 'animate-in fade-in-0 slide-in-from-left-2 duration-200 rtl:slide-in-from-right-2'
                : undefined,
          )}
        >
          {body}
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

/** Picture in picture where the browser offers it. */
function usePictureInPicture(videoRef: RefObject<HTMLVideoElement | null>) {
  const [supported, setSupported] = useState(false)
  const [active, setActive] = useState(false)

  useEffect(() => {
    const video = videoRef.current
    if (!video) return
    setSupported(
      document.pictureInPictureEnabled === true &&
        !video.disablePictureInPicture,
    )
    const onEnter = () => setActive(true)
    const onLeave = () => setActive(false)
    video.addEventListener('enterpictureinpicture', onEnter)
    video.addEventListener('leavepictureinpicture', onLeave)
    return () => {
      video.removeEventListener('enterpictureinpicture', onEnter)
      video.removeEventListener('leavepictureinpicture', onLeave)
    }
  }, [videoRef])

  const toggle = () => {
    if (document.pictureInPictureElement) {
      void document.exitPictureInPicture().catch(() => {})
      return
    }
    void videoRef.current?.requestPictureInPicture().catch(() => {})
  }

  return { supported, active, toggle }
}

export function VideoStreamPlayerControls({
  videoRef,
  output,
  outputOptions,
  onOutputChange,
  playerState,
  onSelectLevel,
  onSelectSubtitleTrack,
  onSelectAudioTrack,
  subtitleSize,
  onSelectSubtitleSize,
  onReload,
  hasNewRenditions,
  scrubCues,
  fullscreen,
  onToggleFullscreen,
  onOpenInspector,
  portalContainer,
  shortcuts = 'player',
  pictureInPicture = true,
  onMenuOpenChange,
  onShortcut,
  accentColor,
  className,
}: VideoStreamPlayerControlsProps) {
  const t = useT()
  const rootRef = useRef<HTMLDivElement | null>(null)
  const [playing, setPlaying] = useState(false)
  const [ended, setEnded] = useState(false)
  const [currentTime, setCurrentTime] = useState(0)
  const [duration, setDuration] = useState(0)
  const [buffered, setBuffered] = useState(0)
  const [scrubTime, setScrubTime] = useState<number | null>(null)
  const [playbackRate, setPlaybackRate] = useState(1)
  const [settingsOpen, setSettingsOpen] = useState(false)
  const [settingsView, setSettingsView] = useState<SettingsView>('main')
  // The clock counts down when someone asks for the time left.
  const [showRemaining, setShowRemaining] = useState(false)
  // Pointer devices usually come with a keyboard; touch screens do not.
  const [showShortcuts, setShowShortcuts] = useState(false)
  const pip = usePictureInPicture(videoRef)
  const pipAvailable = pip.supported && pictureInPicture

  useEffect(() => {
    setShowShortcuts(
      window.matchMedia?.('(hover: hover) and (pointer: fine)').matches ??
        false,
    )
  }, [])

  const sync = useCallback(() => {
    const video = videoRef.current
    if (!video) return
    setPlaying(!video.paused && !video.ended)
    setEnded(video.ended)
    setCurrentTime(video.currentTime)
    if (Number.isFinite(video.duration)) setDuration(video.duration)
    setBuffered(bufferedEnd(video))
    setPlaybackRate(video.playbackRate)
  }, [videoRef])

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
      'progress',
      'seeked',
      'ratechange',
      'emptied',
    ] as const
    for (const event of events) video.addEventListener(event, sync)
    sync()
    return () => {
      for (const event of events) video.removeEventListener(event, sync)
    }
  }, [videoRef, sync, playerState.loadStartedAt])

  const seekTo = useCallback(
    (time: number) => {
      const video = videoRef.current
      if (!video || !Number.isFinite(video.duration)) return
      video.currentTime = clamp(time, 0, video.duration)
      sync()
    },
    [videoRef, sync],
  )

  const togglePlay = useCallback(() => {
    const video = videoRef.current
    if (!video) return
    if (video.paused || video.ended) void video.play().catch(() => {})
    else video.pause()
  }, [videoRef])

  const selectPlaybackRate = (rate: number) => {
    const video = videoRef.current
    if (!video) return
    // A reload resets the rate to the default one, so set both.
    video.defaultPlaybackRate = rate
    video.playbackRate = rate
  }

  const subtitleTracks = playerState.subtitleTracks
  const captionsOn = playerState.currentSubtitleTrack >= 0
  const lastSubtitleTrackRef = useRef<number | null>(null)
  useEffect(() => {
    if (captionsOn)
      lastSubtitleTrackRef.current = playerState.currentSubtitleTrack
  }, [captionsOn, playerState.currentSubtitleTrack])

  const toggleCaptions = useCallback(() => {
    if (subtitleTracks.length === 0) return
    if (captionsOn) {
      onSelectSubtitleTrack(-1)
      return
    }
    const last = lastSubtitleTrackRef.current
    const track =
      subtitleTracks.find((item) => item.id === last) ??
      subtitleTracks.find((item) => item.default) ??
      subtitleTracks[0]
    onSelectSubtitleTrack(track.id)
  }, [captionsOn, onSelectSubtitleTrack, subtitleTracks])

  // Shortcuts read the latest state through a ref, so the listener stays put.
  const shortcutActionsRef = useRef<{
    togglePlay: () => void
    seekTo: (time: number) => void
    toggleCaptions: () => void
    onToggleFullscreen: () => void
    onShortcut?: (flash: PlayerFlash | null) => void
    togglePictureInPicture?: () => void
    openShortcuts: () => void
  }>({
    togglePlay,
    seekTo,
    toggleCaptions,
    onToggleFullscreen,
    onShortcut,
    openShortcuts: () => {},
  })
  shortcutActionsRef.current = {
    togglePlay,
    seekTo,
    toggleCaptions,
    onToggleFullscreen,
    onShortcut,
    togglePictureInPicture: pipAvailable ? pip.toggle : undefined,
    openShortcuts: () => {
      setSettingsView('shortcuts')
      setSettingsOpen(true)
      onMenuOpenChange?.(true)
    },
  }

  useEffect(() => {
    const target: Document | HTMLElement | null =
      shortcuts === 'page'
        ? document
        : (rootRef.current?.closest<HTMLElement>('[data-video-player]') ?? null)
    if (!target) return

    const onKeyDown = (event: Event) => {
      const keyEvent = event as globalThis.KeyboardEvent
      if (
        keyEvent.defaultPrevented ||
        keyEvent.altKey ||
        keyEvent.ctrlKey ||
        keyEvent.metaKey
      ) {
        return
      }
      const video = videoRef.current
      if (!video) return
      const key =
        keyEvent.key.length === 1 ? keyEvent.key.toLowerCase() : keyEvent.key
      if (!shortcutAllowedFrom(keyEvent.target, keyEvent.key)) return
      const actions = shortcutActionsRef.current
      const position = video.currentTime
      let flash: PlayerFlash | null = null

      switch (key) {
        case ' ':
        case 'k':
          flash = video.paused || video.ended ? 'play' : 'pause'
          actions.togglePlay()
          break
        case 'ArrowLeft':
          actions.seekTo(position - SEEK_STEP_SECONDS)
          flash = 'back'
          break
        case 'ArrowRight':
          actions.seekTo(position + SEEK_STEP_SECONDS)
          flash = 'forward'
          break
        case 'j':
          actions.seekTo(position - SEEK_STEP_SECONDS * 2)
          flash = 'back'
          break
        case 'l':
          actions.seekTo(position + SEEK_STEP_SECONDS * 2)
          flash = 'forward'
          break
        case 'ArrowUp':
          video.muted = false
          video.volume = clamp(video.volume + VOLUME_STEP, 0, 1)
          break
        case 'ArrowDown':
          video.volume = clamp(video.volume - VOLUME_STEP, 0, 1)
          break
        case 'm':
          video.muted = !video.muted
          if (!video.muted && video.volume === 0) video.volume = 1
          break
        case 'f':
          actions.onToggleFullscreen()
          break
        case 'c':
          actions.toggleCaptions()
          break
        case ',':
        case '.':
          // Frame steps only make sense on a still picture.
          if (!video.paused) return
          actions.seekTo(
            position + (key === ',' ? -FRAME_STEP_SECONDS : FRAME_STEP_SECONDS),
          )
          break
        case '<':
        case '>': {
          const index = PLAYBACK_RATES.findIndex(
            (rate) => rate >= video.playbackRate,
          )
          const current = index < 0 ? PLAYBACK_RATES.length - 1 : index
          const next =
            PLAYBACK_RATES[
              clamp(
                current + (key === '<' ? -1 : 1),
                0,
                PLAYBACK_RATES.length - 1,
              )
            ]
          video.defaultPlaybackRate = next
          video.playbackRate = next
          break
        }
        case 'i':
          if (!actions.togglePictureInPicture) return
          actions.togglePictureInPicture()
          break
        case '?':
          actions.openShortcuts()
          break
        case 'Home':
          actions.seekTo(0)
          break
        case 'End':
          actions.seekTo(video.duration)
          break
        default:
          if (key.length === 1 && key >= '0' && key <= '9') {
            actions.seekTo((video.duration * Number(key)) / 10)
            break
          }
          return
      }
      keyEvent.preventDefault()
      actions.onShortcut?.(flash)
    }

    target.addEventListener('keydown', onKeyDown)
    return () => target.removeEventListener('keydown', onKeyDown)
  }, [shortcuts, videoRef])

  const onControlsPointerDownCapture = (event: ReactPointerEvent) => {
    const active = document.activeElement
    if (
      active instanceof HTMLElement &&
      event.target instanceof Node &&
      !active.contains(event.target)
    ) {
      active.blur()
    }
  }

  const displayTime = scrubTime ?? currentTime
  const playLabel = ended ? t('Replay') : playing ? t('Pause') : t('Play')
  const pipLabel = pip.active
    ? t('Exit picture in picture')
    : t('Picture in picture')
  const fullscreenLabel = fullscreen ? t('Exit full screen') : t('Full screen')
  const timeLabel = showRemaining
    ? t('Show elapsed time')
    : t('Show remaining time')

  return (
    <PortalContainerContext.Provider value={portalContainer}>
      <div
        ref={rootRef}
        className={cn('@container relative w-full', className)}
        style={{ '--player-accent': accentColor ?? '#ffffff' } as CSSProperties}
        onPointerDownCapture={onControlsPointerDownCapture}
      >
        <div aria-hidden className={SCRIM_CLASS} />
        <div className="relative px-2 pb-[max(0.25rem,env(safe-area-inset-bottom))] @md:px-3 @md:pb-[max(0.5rem,env(safe-area-inset-bottom))] @3xl:px-4 @3xl:pb-[max(0.75rem,env(safe-area-inset-bottom))]">
          <div className="px-1 @3xl:px-1.5">
            <SeekBar
              duration={duration}
              time={displayTime}
              buffered={buffered}
              cues={scrubCues}
              onScrub={setScrubTime}
              onCommit={seekTo}
            />
          </div>

          <div className="flex items-center gap-0.5 @3xl:gap-1">
            <ControlTooltip label={playLabel} shortcut="K">
              <ControlButton aria-label={playLabel} onClick={togglePlay}>
                {ended ? (
                  <RotateCcw />
                ) : playing ? (
                  <Pause className="fill-current" />
                ) : (
                  <Play className="translate-x-px fill-current" />
                )}
              </ControlButton>
            </ControlTooltip>

            <VolumeControl
              videoRef={videoRef}
              loadStartedAt={playerState.loadStartedAt}
            />

            <ControlTooltip label={timeLabel}>
              <button
                type="button"
                aria-label={timeLabel}
                aria-pressed={showRemaining}
                onClick={() => setShowRemaining((current) => !current)}
                dir="ltr"
                className="flex shrink-0 cursor-pointer items-baseline gap-1 rounded-md px-1.5 py-1 text-[12px] font-medium tabular-nums tracking-[0.01em] text-white outline-none transition-colors [text-shadow:0_1px_2px_rgb(0_0_0/0.35)] hover:bg-white/10 focus-visible:ring-2 focus-visible:ring-white/70 @md:text-[13px] @3xl:px-2 @3xl:text-[14px]"
              >
                <span>
                  {showRemaining && duration > 0
                    ? `-${formatPlayerClock(Math.max(0, duration - displayTime))}`
                    : formatPlayerClock(displayTime)}
                </span>
                <span className="text-white/40">/</span>
                <span className="text-white/65">
                  {duration > 0 ? formatPlayerClock(duration) : '--:--'}
                </span>
              </button>
            </ControlTooltip>

            <div className="ms-auto flex shrink-0 items-center gap-0.5 @3xl:gap-1">
              {subtitleTracks.length > 0 ? (
                <ControlTooltip label={t('Subtitles')} shortcut="C">
                  <ControlButton
                    aria-label={t('Subtitles')}
                    aria-pressed={captionsOn}
                    onClick={toggleCaptions}
                    className={cn(
                      'after:absolute after:bottom-1 after:left-1/2 after:h-0.5 after:w-3.5 after:-translate-x-1/2 after:rounded-full after:bg-white after:transition-transform after:duration-200 @3xl:after:bottom-1.5',
                      captionsOn ? 'after:scale-x-100' : 'after:scale-x-0',
                    )}
                  >
                    <Captions />
                  </ControlButton>
                </ControlTooltip>
              ) : null}

              <SettingsMenu
                open={settingsOpen}
                onOpenChange={(open) => {
                  setSettingsOpen(open)
                  onMenuOpenChange?.(open)
                }}
                view={settingsView}
                onViewChange={setSettingsView}
                showShortcuts={showShortcuts}
                onSelectAudioTrack={onSelectAudioTrack}
                output={output}
                outputOptions={outputOptions}
                onOutputChange={onOutputChange}
                playerState={playerState}
                onSelectLevel={onSelectLevel}
                onSelectSubtitleTrack={onSelectSubtitleTrack}
                subtitleSize={subtitleSize}
                onSelectSubtitleSize={onSelectSubtitleSize}
                playbackRate={playbackRate}
                onSelectPlaybackRate={selectPlaybackRate}
                onReload={onReload}
                hasNewRenditions={hasNewRenditions}
                onOpenInspector={onOpenInspector}
              />

              {pipAvailable ? (
                <ControlTooltip label={pipLabel} shortcut="I">
                  <ControlButton
                    aria-label={pipLabel}
                    aria-pressed={pip.active}
                    onClick={pip.toggle}
                    className="hidden @md:inline-flex"
                  >
                    <PictureInPicture2 />
                  </ControlButton>
                </ControlTooltip>
              ) : null}

              <ControlTooltip label={fullscreenLabel} shortcut="F">
                <ControlButton
                  aria-label={fullscreenLabel}
                  onClick={onToggleFullscreen}
                >
                  {fullscreen ? <Minimize /> : <Maximize />}
                </ControlButton>
              </ControlTooltip>
            </div>
          </div>
        </div>
      </div>
    </PortalContainerContext.Provider>
  )
}
