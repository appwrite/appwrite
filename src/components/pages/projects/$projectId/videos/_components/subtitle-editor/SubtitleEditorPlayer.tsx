import {
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type ComponentProps,
  type CSSProperties,
  type RefObject,
} from 'react'
import type { Models } from '@appwrite.io/console'
import {
  Pause,
  Play,
  Plus,
  Scissors,
  SkipBack,
  SkipForward,
  Volume1,
  Volume2,
  VolumeX,
  type LucideIcon,
} from 'lucide-react'
import { AudioOnlyPlayerBackdrop } from '../player/AudioOnlyPlayerBackdrop'
import { useStreamPlayer, type PlayerSource } from '../player/useStreamPlayer'
import { CueMarkup } from './CueMarkup'
import { usePlaybackValue } from './playback-clock'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Slider } from '@/components/ui/slider'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { sdk } from '@/lib/appwrite/sdk'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { isVideoMetadataReady } from '@/lib/react-query/hooks'
import {
  groupReadyRenditionIds,
  resolvePreferredPlaybackOutput,
} from '@/lib/videos/preferred-playback-output'
import {
  activeSubtitleCues,
  cueTextAlign,
  formatEditorTimestamp,
  stripCueMarkup,
  subtitleTextDirection,
  type SubtitleCue,
} from '@/lib/videos/subtitle-cues'
import { applyVideoStreamAccess } from '@/lib/videos/video-stream-access'

const PLAYBACK_RATES = [0.5, 0.75, 1, 1.25, 1.5, 2]

export function SubtitleEditorPlayer({
  projectId,
  video,
  renditions,
  videoRef,
  cues,
  selectedId,
  languageCode,
  onSelectCue,
}: {
  projectId: string
  video: Models.Video
  renditions: Models.VideoRendition[]
  videoRef: RefObject<HTMLVideoElement | null>
  cues: SubtitleCue[]
  selectedId: string | null
  languageCode: string
  onSelectCue: (id: string) => void
}) {
  const readyIds = useMemo(
    () => groupReadyRenditionIds(renditions),
    [renditions],
  )
  const output = resolvePreferredPlaybackOutput(readyIds)
  const source = useMemo<PlayerSource>(() => {
    const videos = sdk.forProject(projectId).videos
    switch (output) {
      case 'hls':
        return { type: 'hls', url: videos.getHlsManifest({ videoId: video.$id }) }
      case 'cmaf':
        return {
          type: 'hls',
          url: videos.getCmafHlsManifest({ videoId: video.$id }),
        }
      case 'dash':
        return {
          type: 'dash',
          url: videos.getDashManifest({ videoId: video.$id }),
        }
      default:
        return {
          type: 'file',
          url: applyVideoStreamAccess(
            sdk.forProject(projectId).storage.getFileView({
              bucketId: video.bucketId,
              fileId: video.fileId,
            }),
            'console',
          ),
        }
    }
  }, [output, projectId, video.$id, video.bucketId, video.fileId])

  const { state, setSubtitleTrack } = useStreamPlayer(
    videoRef,
    source,
    0,
    'console',
  )

  // The packaged track would render under the cues being edited.
  useEffect(() => {
    if (state.currentSubtitleTrack >= 0) setSubtitleTrack(-1)
  }, [state.currentSubtitleTrack, state.subtitleTracks.length, setSubtitleTrack])

  const audioOnly = video.duration > 0 && !isVideoMetadataReady(video)

  return (
    <div
      data-subtitle-keep-selection
      className="@container relative flex min-h-0 flex-1 items-center justify-center overflow-hidden bg-black"
    >
      <video
        ref={videoRef}
        playsInline
        preload="auto"
        className="h-full max-h-full w-full object-contain"
        onClick={(event) => {
          const el = event.currentTarget
          if (el.paused) void el.play().catch(() => {})
          else el.pause()
        }}
      />
      {audioOnly ? <AudioOnlyPlayerBackdrop title={video.name} /> : null}
      <CaptionOverlay
        cues={cues}
        selectedId={selectedId}
        languageCode={languageCode}
        onSelectCue={onSelectCue}
      />
    </div>
  )
}

function cueAlignStyle(align: ReturnType<typeof cueTextAlign>): CSSProperties {
  switch (align) {
    case 'start':
    case 'left':
      return { textAlign: align, alignSelf: align === 'left' ? 'flex-start' : 'start' }
    case 'end':
    case 'right':
      return { textAlign: align, alignSelf: align === 'right' ? 'flex-end' : 'end' }
    default:
      return { textAlign: 'center' }
  }
}

function CaptionOverlay({
  cues,
  selectedId,
  languageCode,
  onSelectCue,
}: {
  cues: SubtitleCue[]
  selectedId: string | null
  languageCode: string
  onSelectCue: (id: string) => void
}) {
  const activeKey = usePlaybackValue((clock) =>
    activeSubtitleCues(cues, clock.time)
      .map((cue) => cue.id)
      .join(','),
  )
  const active = useMemo(() => {
    const ids = new Set(activeKey.split(',').filter(Boolean))
    return cues.filter((cue) => ids.has(cue.id))
  }, [activeKey, cues])

  if (active.length === 0) return null
  const dir = subtitleTextDirection(languageCode)
  return (
    <div className="pointer-events-none absolute inset-x-0 bottom-0 flex flex-col items-center gap-1 px-6 pb-[6%]">
      {active.map((cue) => (
        <button
          key={cue.id}
          type="button"
          data-subtitle-keep-selection
          dir={dir}
          lang={languageCode || undefined}
          onClick={() => onSelectCue(cue.id)}
          style={cueAlignStyle(cueTextAlign(cue.settings))}
          className={cn(
            'pointer-events-auto max-w-[90%] whitespace-pre-line rounded bg-black/75 px-2 py-0.5 text-[clamp(13px,2.4cqw,24px)] leading-snug text-white [unicode-bidi:plaintext]',
            cue.id === selectedId && 'ring-2 ring-white/70',
          )}
        >
          {stripCueMarkup(cue.text).trim() ? <CueMarkup text={cue.text} /> : '\u00a0'}
        </button>
      ))}
    </div>
  )
}

export function SubtitleTransport({
  videoRef,
  onAddCue,
  onToggleSplit,
  splitActive,
  canSplit,
}: {
  videoRef: RefObject<HTMLVideoElement | null>
  onAddCue: () => void
  /** Shows or cancels the draggable split marker on the timeline. */
  onToggleSplit: () => void
  splitActive: boolean
  canSplit: boolean
}) {
  const t = useT()
  const playing = usePlaybackValue((clock) => clock.playing)
  const time = usePlaybackValue((clock) => Math.floor(clock.time * 1000))
  const duration = usePlaybackValue((clock) => clock.duration)
  const [rate, setRate] = useState(1)
  const barRef = useRef<HTMLDivElement | null>(null)
  const [compact, setCompact] = useState(false)
  // Labels drop once the labelled layout overflows. The width it needed is
  // remembered so the bar doesn't flip back and forth at the boundary.
  const wideWidthRef = useRef(0)
  useLayoutEffect(() => {
    const el = barRef.current
    if (!el) return
    const measure = () => {
      setCompact((wasCompact) => {
        if (!wasCompact && el.scrollWidth > el.clientWidth) {
          wideWidthRef.current = el.scrollWidth
          return true
        }
        if (wasCompact && el.clientWidth >= wideWidthRef.current) return false
        return wasCompact
      })
    }
    measure()
    const observer = new ResizeObserver(measure)
    observer.observe(el)
    return () => observer.disconnect()
  }, [])
  useLayoutEffect(() => {
    const el = barRef.current
    if (!compact && el && el.scrollWidth > el.clientWidth) {
      wideWidthRef.current = el.scrollWidth
      setCompact(true)
    }
  }, [compact])

  const seekBy = (delta: number) => {
    const el = videoRef.current
    if (!el) return
    el.currentTime = Math.max(0, Math.min(el.duration || 0, el.currentTime + delta))
  }
  const toggle = () => {
    const el = videoRef.current
    if (!el) return
    if (el.paused) void el.play().catch(() => {})
    else el.pause()
  }

  return (
    <div
      ref={barRef}
      data-subtitle-keep-selection
      className="@container grid shrink-0 grid-cols-[minmax(max-content,1fr)_auto_minmax(max-content,1fr)] items-center gap-3 overflow-hidden border-t border-border bg-background px-3 py-1.5"
    >
      <div className="flex items-center gap-2 justify-self-start">
        <span
          className="whitespace-nowrap font-mono text-[13px] tabular-nums text-foreground"
          dir="ltr"
        >
          {formatEditorTimestamp(time / 1000)}
          <span className="hidden text-[12px] text-muted-foreground @md:inline">
            {' / '}
            {formatEditorTimestamp(duration)}
          </span>
        </span>
        <VolumeControl videoRef={videoRef} />
      </div>

      <div className="flex items-center gap-1">
        <TransportButton
          icon={SkipBack}
          label={t('Back 1 second')}
          onClick={() => seekBy(-1)}
        />
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              type="button"
              size="icon"
              className="h-9 w-9 rounded-full"
              aria-label={playing ? t('Pause') : t('Play')}
              onClick={toggle}
            >
              {playing ? (
                <Pause className="h-4 w-4 fill-current" />
              ) : (
                <Play className="h-4 w-4 translate-x-px fill-current" />
              )}
            </Button>
          </TooltipTrigger>
          <TooltipContent className="text-[12px]">
            {playing ? t('Pause') : t('Play')}
          </TooltipContent>
        </Tooltip>
        <TransportButton
          icon={SkipForward}
          label={t('Forward 1 second')}
          onClick={() => seekBy(1)}
        />
      </div>

      <div className="flex items-center justify-self-end gap-2">
        <Select
          value={String(rate)}
          onValueChange={(value) => {
            setRate(Number(value))
            if (videoRef.current) videoRef.current.playbackRate = Number(value)
          }}
        >
          <SelectTrigger
            className="hidden h-8 w-[68px] text-[12px] @lg:flex"
            aria-label={t('Playback speed')}
          >
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {PLAYBACK_RATES.map((value) => (
              <SelectItem key={value} value={String(value)} className="text-[12px]">
                {value}×
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <div aria-hidden className="h-5 w-px shrink-0 bg-border" />
        <CompactLabelButton
          compact={compact}
          icon={Scissors}
          label={t('Split cue')}
          className={cn(
            splitActive &&
              'border-sky-500 bg-sky-500 text-white hover:bg-sky-600 hover:text-white dark:border-sky-500 dark:bg-sky-500 dark:hover:bg-sky-600',
          )}
          aria-pressed={splitActive}
          disabled={!canSplit}
          onClick={onToggleSplit}
        />
        <CompactLabelButton
          compact={compact}
          icon={Plus}
          label={t('Add cue at playhead')}
          onClick={onAddCue}
        />
      </div>
    </div>
  )
}

/** Mirrors the element's volume so the M shortcut and the controls stay in sync. */
function VolumeControl({
  videoRef,
}: {
  videoRef: RefObject<HTMLVideoElement | null>
}) {
  const t = useT()
  const [volume, setVolume] = useState(1)
  const [muted, setMuted] = useState(false)

  useEffect(() => {
    const el = videoRef.current
    if (!el) return
    const sync = () => {
      setVolume(el.volume)
      setMuted(el.muted)
    }
    sync()
    el.addEventListener('volumechange', sync)
    return () => el.removeEventListener('volumechange', sync)
  }, [videoRef])

  const silent = muted || volume === 0
  const Icon = silent ? VolumeX : volume < 0.5 ? Volume1 : Volume2

  const toggleMute = () => {
    const el = videoRef.current
    if (!el) return
    if (silent) {
      if (el.volume === 0) el.volume = 1
      el.muted = false
    } else {
      el.muted = true
    }
  }

  return (
    <div className="flex items-center gap-1">
      <TransportButton
        icon={Icon}
        label={silent ? t('Unmute') : t('Mute')}
        onClick={toggleMute}
      />
      <Slider
        aria-label={t('Volume')}
        className="hidden w-20 @xl:flex"
        min={0}
        max={1}
        step={0.05}
        value={[silent ? 0 : volume]}
        onValueChange={([value]) => {
          const el = videoRef.current
          if (!el || value == null) return
          el.volume = value
          el.muted = value === 0
        }}
      />
    </div>
  )
}

/** Labelled when the transport bar is wide; icon-only with a tooltip on narrow screens. */
function CompactLabelButton({
  icon: Icon,
  label,
  compact,
  className,
  ...props
}: Omit<ComponentProps<typeof Button>, 'children'> & {
  icon: LucideIcon
  label: string
  compact: boolean
}) {
  const button = (
    <Button
      type="button"
      variant="outline"
      size="sm"
      aria-label={compact ? label : undefined}
      className={cn('h-8 gap-1.5 text-[12px]', compact && 'w-8 px-0', className)}
      {...props}
    >
      <Icon className="h-3.5 w-3.5" />
      {compact ? null : label}
    </Button>
  )
  if (!compact) return button
  return (
    <Tooltip>
      <TooltipTrigger asChild>{button}</TooltipTrigger>
      <TooltipContent className="text-[12px]">{label}</TooltipContent>
    </Tooltip>
  )
}

function TransportButton({
  icon: Icon,
  label,
  onClick,
}: {
  icon: LucideIcon
  label: string
  onClick: () => void
}) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="h-8 w-8"
          aria-label={label}
          onClick={onClick}
        >
          <Icon className="h-4 w-4" />
        </Button>
      </TooltipTrigger>
      <TooltipContent className="text-[12px]">{label}</TooltipContent>
    </Tooltip>
  )
}
