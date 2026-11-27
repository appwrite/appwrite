import type { Models } from '@appwrite.io/console'
import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type CSSProperties,
  type MouseEvent as ReactMouseEvent,
} from 'react'
import { createPortal } from 'react-dom'
import { DockedPlayerControls } from './DockedPlayerControls'
import {
  FastForward,
  Film,
  Lock,
  Pause,
  Play,
  Rewind,
  RotateCcw,
  SquareArrowOutUpRight,
  TriangleAlert,
  VolumeX,
} from 'lucide-react'
import { sdk } from '@/lib/appwrite/sdk'
import {
  isConsolePlaybackMessage,
  isEmbedUnauthorizedMessage,
} from '@/lib/videos/embed-player-errors'
import {
  applyVideoStreamAccess,
  type VideoStreamAccess,
} from '@/lib/videos/video-stream-access'
import { EmbedUnauthorized } from '@/components/pages/player/EmbedUnauthorized'
import {
  groupReadyRenditionIds,
  resolvePreferredPlaybackOutput,
} from '@/lib/videos/preferred-playback-output'
import { Button } from '@/components/ui/button'
import { AppwriteMarkIcon } from '@/components/global/shared/AppwriteMarkIcon'
import {
  isVideoMetadataReady,
  isVideoRenditionActive,
  useVideoTimeline,
} from '@/lib/react-query/hooks/videos'
import {
  getVideoPlayerViewportStyle,
  renditionFrame,
  resolvePlayerAspect,
} from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { useLongWait } from '@/hooks/use-long-wait'
import { useVideoPlayerPrefs } from '@/hooks/use-video-player-prefs'
import { StreamDebugPanel, type StreamManifest } from './StreamDebugPanel'
import {
  VideoStreamPlayerControls,
  type PlaybackOutput,
  type PlayerFlash,
} from './VideoStreamPlayerControls'
import { useStreamPlayer, type PlayerSource } from './useStreamPlayer'
import {
  inspectorWindowTitle,
  useVideoInspector,
} from './VideoInspectorContext'
import { useQoeTracker } from './useQoeTracker'
import { useMediaSession } from './useMediaSession'
import { AudioOnlyPlayerBackdrop } from './AudioOnlyPlayerBackdrop'
import {
  resolveVideoSubtitleSize,
  VIDEO_SUBTITLE_INSET_CONSOLE_PX,
  VIDEO_SUBTITLE_INSET_OVERLAY_GAP_PX,
  videoSubtitleSizeCss,
  type VideoSubtitleSize,
} from '@/lib/videos/player-subtitle-display'

/** While playing, the controls fade after this long without a pointer or key. */
const CONTROLS_IDLE_MS = 2500
/** Leaving the player with the mouse hides the controls this much sooner. */
const CONTROLS_LEAVE_MS = 600
/** Cue offset from the bottom of the picture while the controls are hidden. */
const SUBTITLE_INSET_IDLE_PX = 20
/** Stalls shorter than this pass without a spinner. */
const BUFFERING_SPINNER_DELAY_MS = 400
/** Two taps on a side of the picture within this window seek. */
const DOUBLE_TAP_MS = 300
const DOUBLE_TAP_SEEK_SECONDS = 10

export interface VideoStreamPlayerProps {
  projectId: string
  video: Models.Video
  renditions: Models.VideoRendition[]
  /** Overview layout: slightly shorter max height. */
  variant?: 'default' | 'featured'
  /** Full-viewport embed player for end users (`/player/:videoId`). */
  layout?: 'console' | 'embed'
  /** Seek once when the stream is ready (`?start=` on the embed URL). */
  initialStartSeconds?: number
  /** Start playing once the stream can play, for a viewer who asked to watch. */
  autoPlay?: boolean
  /** Start with the sound off (`?muted=1` on the embed URL). */
  muted?: boolean
  /** Play again from the start at the end (`?loop=1`). */
  loop?: boolean
  /** `false` hides the control bar (`?controls=0`). */
  controls?: boolean
  /** Progress and volume color (`?color=`), a CSS color. */
  accentColor?: string
  /**
   * Timeline frames over the seek bar. On by default in the Console; the
   * player page turns them on only when the viewer can read the video.
   */
  seekPreviews?: boolean
}

/** Height of the docked player strip. */
const COMPACT_PLAYER_HEIGHT = 72
/** Docked strip: the player plus its `py-2` padding and bottom border. */
const DOCKED_STRIP_HEIGHT = COMPACT_PLAYER_HEIGHT + 16 + 1
/** Caption offset from the bottom of the docked picture. */
const COMPACT_SUBTITLE_INSET_PX = 4
/** Scrolling back this far above the docking point undocks the player. */
const UNDOCK_SLACK_PX = 16

function findScrollParent(node: HTMLElement | null): HTMLElement | null {
  let current = node?.parentElement ?? null
  while (current) {
    const overflowY = getComputedStyle(current).overflowY
    if (overflowY === 'auto' || overflowY === 'scroll') return current
    current = current.parentElement
  }
  return null
}

/** Thin ring spinner for the picture. */
function PlayerSpinner({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 48 48"
      fill="none"
      aria-hidden
      className={cn('animate-spin-smooth', className)}
    >
      <circle
        cx="24"
        cy="24"
        r="20"
        stroke="currentColor"
        strokeOpacity={0.18}
        strokeWidth={3.5}
      />
      <path
        d="M44 24A20 20 0 0 0 24 4"
        stroke="currentColor"
        strokeWidth={3.5}
        strokeLinecap="round"
      />
    </svg>
  )
}

export function VideoStreamPlayer({
  projectId,
  video,
  renditions,
  variant = 'default',
  layout = 'console',
  initialStartSeconds,
  autoPlay = false,
  muted,
  loop = false,
  controls = true,
  accentColor,
  seekPreviews,
}: VideoStreamPlayerProps) {
  const t = useT()
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const featured = variant === 'featured'
  const embed = layout === 'embed'
  // The player page streams as a visitor would. When that is refused but the
  // video itself loaded (a project member viewing a private video), it falls
  // back to Console access once and says the video is private.
  const [embedAccess, setEmbedAccess] = useState<VideoStreamAccess>('end-user')
  const streamAccess: VideoStreamAccess = embed ? embedAccess : 'console'
  const memberPreview = embed && embedAccess === 'console'
  const overlayControls = embed

  const readyIds = useMemo(
    () => groupReadyRenditionIds(renditions),
    [renditions],
  )

  const preferredOutput = useMemo(
    () => resolvePreferredPlaybackOutput(readyIds),
    [readyIds],
  )
  const playerPrefs = useVideoPlayerPrefs()
  const [chosenOutput, setChosenOutput] = useState<PlaybackOutput | null>(
    () => {
      if (embed) return null
      const saved = playerPrefs.read().output
      if (!saved) return null
      return saved === 'source' || readyIds[saved]?.length ? saved : null
    },
  )
  const output = chosenOutput ?? preferredOutput
  // Keep the first output that became playable. Another output finishing
  // later would otherwise swap the manifest under a playing video.
  useEffect(() => {
    if (chosenOutput == null && preferredOutput !== 'source') {
      setChosenOutput(preferredOutput)
    }
  }, [chosenOutput, preferredOutput])
  const changeOutput = (next: PlaybackOutput) => {
    setChosenOutput(next)
    if (!embed) playerPrefs.update({ output: next })
  }

  const videos = sdk.forProject(projectId).videos
  const manifests: StreamManifest[] = useMemo(
    () => [
      {
        id: 'hls',
        label: 'HLS',
        url: videos.getHlsManifest({ videoId: video.$id }),
        available: readyIds.hls.length > 0,
      },
      {
        id: 'cmaf-hls',
        label: 'CMAF (HLS)',
        url: videos.getCmafHlsManifest({ videoId: video.$id }),
        available: readyIds.cmaf.length > 0,
      },
      {
        id: 'cmaf-dash',
        label: 'CMAF (DASH)',
        url: videos.getCmafDashManifest({ videoId: video.$id }),
        available: readyIds.cmaf.length > 0,
      },
      {
        id: 'dash',
        label: 'DASH',
        url: videos.getDashManifest({ videoId: video.$id }),
        available: readyIds.dash.length > 0,
      },
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [projectId, video.$id, readyIds],
  )

  // A visitor's playback from the public playlist has no source file.
  const sourceFileUrl = useMemo(
    () =>
      video.bucketId && video.fileId
        ? applyVideoStreamAccess(
            sdk.forProject(projectId).storage.getFileView({
              bucketId: video.bucketId,
              fileId: video.fileId,
            }),
            streamAccess,
          )
        : '',
    [projectId, video.bucketId, video.fileId, streamAccess],
  )

  const activeManifestUrl = useMemo(() => {
    switch (output) {
      case 'hls':
        return videos.getHlsManifest({ videoId: video.$id })
      case 'cmaf':
        return videos.getCmafHlsManifest({ videoId: video.$id })
      case 'dash':
        return videos.getDashManifest({ videoId: video.$id })
      default:
        return null
    }
  }, [output, videos, video.$id])

  const outputReadyKey =
    output === 'hls'
      ? readyIds.hls.join(',')
      : output === 'cmaf'
        ? readyIds.cmaf.join(',')
        : output === 'dash'
          ? readyIds.dash.join(',')
          : 'source'

  const [loadedKey, setLoadedKey] = useState(outputReadyKey)
  const [reloadToken, setReloadToken] = useState(0)
  const inspector = useVideoInspector()
  const inspectorContainer = inspector?.container ?? null
  const attachInspector = inspector?.attach
  const openInspector = inspector
    ? () =>
        inspector.open({
          projectId,
          videoId: video.$id,
          videoName: video.name,
        })
    : undefined

  useEffect(() => attachInspector?.(), [attachInspector])

  useEffect(() => {
    if (!inspectorContainer) return
    inspectorContainer.ownerDocument.title = inspectorWindowTitle(
      video.name,
      t('Inspector'),
    )
  }, [inspectorContainer, video.name, t])

  const hasStorageFile = Boolean(video.bucketId && video.fileId)
  // Playback stays locked until at least one rendition is ready, even though
  // the source file exists: the player only streams encoded renditions.
  const locked =
    readyIds.hls.length === 0 &&
    readyIds.dash.length === 0 &&
    readyIds.cmaf.length === 0

  const playerSource: PlayerSource | null = useMemo(() => {
    if (locked) return null
    const adaptiveReady =
      (output === 'hls' && readyIds.hls.length > 0) ||
      (output === 'dash' && readyIds.dash.length > 0) ||
      (output === 'cmaf' && readyIds.cmaf.length > 0)
    if (adaptiveReady && activeManifestUrl) {
      return {
        url: activeManifestUrl,
        type: output === 'dash' ? 'dash' : 'hls',
      }
    }
    if (hasStorageFile) {
      return { url: sourceFileUrl, type: 'file' }
    }
    return null
  }, [
    activeManifestUrl,
    hasStorageFile,
    locked,
    output,
    readyIds,
    sourceFileUrl,
  ])

  useEffect(() => {
    setLoadedKey(outputReadyKey)
    // Only re-baseline when the output (and therefore the manifest) changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [output])

  const hasNewRenditions = output !== 'source' && loadedKey !== outputReadyKey

  const [reloading, setReloading] = useState(false)

  const reload = ({ silent = false }: { silent?: boolean } = {}) => {
    const el = videoRef.current
    if (el && el.currentTime > 0) {
      const resumeAt = el.currentTime
      const resumePlaying = !el.paused && !el.ended
      const onLoadedMetadata = () => {
        el.removeEventListener('loadedmetadata', onLoadedMetadata)
        el.currentTime = resumeAt
        if (resumePlaying) void el.play().catch(() => {})
      }
      el.addEventListener('loadedmetadata', onLoadedMetadata)
    }
    setLoadedKey(outputReadyKey)
    if (!silent) setReloading(true)
    setReloadToken((n) => n + 1)
  }
  const reloadRef = useRef(reload)
  reloadRef.current = reload

  // The quality list comes from the master manifest, which players only read
  // once. Renditions often finish seconds apart, so batch them. Reloading
  // tears the stream down, so never do it under a playing video: wait for a
  // pause or the end, and meanwhile the menu offers a manual reload.
  useEffect(() => {
    if (!hasNewRenditions) return
    const el = videoRef.current
    let timer: number | undefined
    const schedule = () => {
      window.clearTimeout(timer)
      timer = window.setTimeout(() => {
        if (el && !el.paused && !el.ended) return
        reloadRef.current({ silent: true })
      }, 1500)
    }
    if (!el || el.paused || el.ended) schedule()
    el?.addEventListener('pause', schedule)
    el?.addEventListener('ended', schedule)
    return () => {
      window.clearTimeout(timer)
      el?.removeEventListener('pause', schedule)
      el?.removeEventListener('ended', schedule)
    }
  }, [hasNewRenditions, outputReadyKey])

  const { state, setLevel, setSubtitleTrack, setAudioTrack, clearEvents } =
    useStreamPlayer(videoRef, playerSource, reloadToken, streamAccess)

  // While the visitor stream is refused and the Console retry is about to
  // start, keep the video mounted and show no error.
  const memberRetryPending =
    embed &&
    embedAccess === 'end-user' &&
    (state.fatalStatus === 401 ||
      state.fatalStatus === 403 ||
      state.fatalStatus === 404)

  // The refused attempt's error stays in state until the Console attempt
  // starts loading; keep it hidden so the video is not unmounted meanwhile.
  const [refusedLoadAt, setRefusedLoadAt] = useState<number | null>(null)
  const refusalShowing =
    memberRetryPending ||
    (embed &&
      embedAccess === 'console' &&
      refusedLoadAt != null &&
      state.loadStartedAt === refusedLoadAt)

  useEffect(() => {
    if (!memberRetryPending) return
    setRefusedLoadAt(state.loadStartedAt)
    setEmbedAccess('console')
  }, [memberRetryPending, state.loadStartedAt])

  useEffect(() => {
    if (state.firstFrameAt != null || state.fatalError) setReloading(false)
  }, [state.firstFrameAt, state.fatalError])

  const { read: readPlayerPrefs, update: updatePlayerPrefs } = playerPrefs

  useEffect(() => {
    const el = videoRef.current
    if (!el) return
    const saved = readPlayerPrefs()
    el.volume = saved.volume
    el.muted = muted ?? saved.muted
    // The player page leaves Console preferences alone: `?muted=1` must not
    // mute a member's Console player.
    if (embed) return
    const onVolumeChange = () =>
      updatePlayerPrefs({ volume: el.volume, muted: el.muted })
    el.addEventListener('volumechange', onVolumeChange)
    return () => el.removeEventListener('volumechange', onVolumeChange)
  }, [readPlayerPrefs, updatePlayerPrefs, muted, embed])

  const appliedEmbedStartRef = useRef(false)
  useEffect(() => {
    appliedEmbedStartRef.current = false
  }, [initialStartSeconds, outputReadyKey, reloadToken])

  useEffect(() => {
    const start = initialStartSeconds
    if (start == null || !Number.isFinite(start) || start <= 0) return
    const el = videoRef.current
    if (!el || appliedEmbedStartRef.current) return

    const applyStart = () => {
      if (appliedEmbedStartRef.current) return
      if (!Number.isFinite(el.duration) || el.duration <= 0) return
      const target = Math.min(start, Math.max(0, el.duration - 0.05))
      if (target > 0) {
        el.currentTime = target
      }
      appliedEmbedStartRef.current = true
    }

    el.addEventListener('loadedmetadata', applyStart)
    el.addEventListener('durationchange', applyStart)
    applyStart()
    return () => {
      el.removeEventListener('loadedmetadata', applyStart)
      el.removeEventListener('durationchange', applyStart)
    }
  }, [initialStartSeconds, outputReadyKey, reloadToken])

  // Re-apply saved quality and subtitles once per load, when the tracks arrive.
  const appliedQualityForRef = useRef<number | null>(null)
  const appliedSubtitlesForRef = useRef<number | null>(null)

  useEffect(() => {
    const load = state.loadStartedAt
    if (!load || state.levels.length === 0) return
    if (appliedQualityForRef.current === load) return
    appliedQualityForRef.current = load
    const height = readPlayerPrefs().quality
    if (height == null) return
    const closest = [...state.levels].sort(
      (a, b) =>
        Math.abs(a.height - height) - Math.abs(b.height - height) ||
        b.bitrate - a.bitrate,
    )[0]
    if (closest) setLevel(closest.index)
  }, [state.loadStartedAt, state.levels, readPlayerPrefs, setLevel])

  useEffect(() => {
    const load = state.loadStartedAt
    if (!load || state.subtitleTracks.length === 0) return
    if (appliedSubtitlesForRef.current === load) return
    appliedSubtitlesForRef.current = load
    const saved = readPlayerPrefs().subtitles
    if (saved == null) return
    if (saved === 'off') {
      setSubtitleTrack(-1)
      return
    }
    const track = state.subtitleTracks.find(
      (item) => item.lang === saved || item.name === saved,
    )
    if (track) setSubtitleTrack(track.id)
  }, [
    state.loadStartedAt,
    state.subtitleTracks,
    readPlayerPrefs,
    setSubtitleTrack,
  ])

  const selectLevel = (index: number) => {
    setLevel(index)
    const level = state.levels.find((item) => item.index === index)
    updatePlayerPrefs({ quality: index < 0 ? null : level?.height || null })
  }

  const selectSubtitleTrack = (id: number) => {
    setSubtitleTrack(id)
    const track = state.subtitleTracks.find((item) => item.id === id)
    updatePlayerPrefs({
      subtitles: id < 0 ? 'off' : track?.lang || track?.name || null,
    })
  }

  const [subtitleSize, setSubtitleSize] = useState<VideoSubtitleSize>(() =>
    resolveVideoSubtitleSize(readPlayerPrefs().subtitleSize),
  )
  const selectSubtitleSize = (size: VideoSubtitleSize) => {
    setSubtitleSize(size)
    updatePlayerPrefs({ subtitleSize: size })
  }

  const [subtitleInsetPx, setSubtitleInsetPx] = useState(
    VIDEO_SUBTITLE_INSET_CONSOLE_PX,
  )

  const { qoe, reset: resetQoe } = useQoeTracker(videoRef, state)
  const clearSessionData = () => {
    clearEvents()
    resetQoe()
  }
  const [renderedHeight, setRenderedHeight] = useState<number | null>(null)

  useEffect(() => {
    const el = videoRef.current
    if (!el) return
    const update = () =>
      setRenderedHeight(
        el.clientHeight > 0 ? el.clientHeight * window.devicePixelRatio : null,
      )
    update()
    const observer = new ResizeObserver(update)
    observer.observe(el)
    return () => observer.disconnect()
  }, [])

  const [mediaPixelSize, setMediaPixelSize] = useState<{
    width: number
    height: number
  } | null>(null)

  useEffect(() => {
    const el = videoRef.current
    if (!el) return

    const syncMediaSize = () => {
      if (el.videoWidth > 0 && el.videoHeight > 0) {
        setMediaPixelSize({
          width: el.videoWidth,
          height: el.videoHeight,
        })
      }
    }

    el.addEventListener('loadedmetadata', syncMediaSize)
    syncMediaSize()

    return () => {
      el.removeEventListener('loadedmetadata', syncMediaSize)
    }
  }, [playerSource?.url, reloadToken, state.loadStartedAt])

  // `cqh` is the visible height of the VideoPage scroll area. Controls sit on
  // the picture, so the frame can use almost the full area.
  // Scrolled past the top, the featured player docks as a small sticky strip
  // so the tools below get the room; its controls move beside the picture.
  const [compact, setCompact] = useState(false)
  const [dockSpacer, setDockSpacer] = useState(0)
  const playerMaxHeight = compact
    ? `${COMPACT_PLAYER_HEIGHT}px`
    : featured
      ? 'max(240px, min(800px, calc(100cqh - 32px)))'
      : 'max(240px, min(800px, calc(100cqh - 24px)))'
  // Size the frame from the renditions before the stream loads: their padded
  // box, not the source, is the shape that decodes.
  const expectedFrame = useMemo(
    () => renditionFrame(renditions, output),
    [renditions, output],
  )
  const viewportAspect = useMemo(
    () =>
      resolvePlayerAspect(
        {
          width: video.width,
          height: video.height,
          aspectRatio: video.aspectRatio,
        },
        mediaPixelSize ?? expectedFrame,
      ),
    [
      mediaPixelSize,
      expectedFrame,
      video.width,
      video.height,
      video.aspectRatio,
    ],
  )
  const viewportStyle = useMemo(
    () =>
      embed
        ? { width: '100%', height: '100%', maxHeight: 'none' }
        : getVideoPlayerViewportStyle(
            viewportAspect,
            playerMaxHeight,
            featured && !compact ? 'width' : 'height',
          ),
    [embed, featured, compact, viewportAspect, playerMaxHeight],
  )

  const audioOnly = (video.duration ?? 0) > 0 && !isVideoMetadataReady(video)

  const playerContainerRef = useRef<HTMLDivElement | null>(null)
  const [fullscreen, setFullscreen] = useState(false)
  // A locked or failed player has nothing to control from the strip.
  const canDock =
    featured && !embed && !fullscreen && !!playerSource && !state.fatalError

  useEffect(() => {
    if (!canDock) {
      setCompact(false)
      setDockSpacer(0)
      return
    }
    const scroller = findScrollParent(playerContainerRef.current)
    if (!scroller) return
    // Docking keeps the page the same height (a spacer stands in for the
    // full player), so nothing below jumps and the scroll position holds.
    let dockAt: number | null = null
    const onScroll = () => {
      const top = scroller.scrollTop
      const player = playerContainerRef.current
      if (!player) return
      if (dockAt == null) {
        const offset =
          player.getBoundingClientRect().top -
          scroller.getBoundingClientRect().top +
          top
        const height = player.offsetHeight
        // Dock once the full player has scrolled up to the size of the strip.
        const at = offset + height - DOCKED_STRIP_HEIGHT
        if (height <= DOCKED_STRIP_HEIGHT || top <= at) return
        dockAt = at
        setDockSpacer(height - DOCKED_STRIP_HEIGHT)
        setCompact(true)
      } else if (top < dockAt - UNDOCK_SLACK_PX) {
        dockAt = null
        setDockSpacer(0)
        setCompact(false)
      }
    }
    onScroll()
    scroller.addEventListener('scroll', onScroll, { passive: true })
    return () => scroller.removeEventListener('scroll', onScroll)
  }, [canDock])

  useEffect(() => {
    const onChange = () => {
      setFullscreen(
        playerContainerRef.current != null &&
          document.fullscreenElement === playerContainerRef.current,
      )
      if (!document.fullscreenElement) {
        const orientation = screen.orientation as
          | (ScreenOrientation & { unlock?: () => void })
          | undefined
        try {
          orientation?.unlock?.()
        } catch {
          // Nothing was locked.
        }
      }
    }
    document.addEventListener('fullscreenchange', onChange)
    return () => document.removeEventListener('fullscreenchange', onChange)
  }, [])

  const [flash, setFlash] = useState<{
    kind: PlayerFlash
    key: number
  } | null>(null)
  const showFlash = useCallback((kind: PlayerFlash) => {
    setFlash((current) => ({ kind, key: (current?.key ?? 0) + 1 }))
  }, [])

  // Playback status for what sits over the picture: the start and replay
  // buttons, and a spinner while a playing stream waits for data.
  const [started, setStarted] = useState(false)
  const [ended, setEnded] = useState(false)
  const [waiting, setWaiting] = useState(false)
  const buffering = useLongWait(waiting, BUFFERING_SPINNER_DELAY_MS)

  useEffect(() => {
    const el = videoRef.current
    if (!el) return
    const onPlay = () => {
      setStarted(true)
      setEnded(false)
    }
    const onEnded = () => setEnded(true)
    // A slow seek waits too, playing or paused.
    const onWaiting = () => setWaiting(true)
    const onResume = () => {
      setWaiting(false)
      setEnded(el.ended)
    }
    const resumeEvents = [
      'playing',
      'pause',
      'seeked',
      'canplay',
      'emptied',
    ] as const
    if (!el.paused) setStarted(true)
    el.addEventListener('play', onPlay)
    el.addEventListener('ended', onEnded)
    el.addEventListener('waiting', onWaiting)
    el.addEventListener('seeking', onWaiting)
    for (const name of resumeEvents) el.addEventListener(name, onResume)
    return () => {
      el.removeEventListener('play', onPlay)
      el.removeEventListener('ended', onEnded)
      el.removeEventListener('waiting', onWaiting)
      el.removeEventListener('seeking', onWaiting)
      for (const name of resumeEvents) el.removeEventListener(name, onResume)
    }
  }, [])

  // Browsers often refuse autoplay with sound until the viewer interacts.
  // Then play muted, as video sites do, and offer the sound back.
  const [autoplayMuted, setAutoplayMuted] = useState(false)
  const [autoplayRefused, setAutoplayRefused] = useState(false)
  const autoplayTriedRef = useRef(false)

  useEffect(() => {
    const el = videoRef.current
    if (!el || !autoPlay) return
    let cancelled = false
    const start = () => {
      if (cancelled || autoplayTriedRef.current || !el.paused) return
      autoplayTriedRef.current = true
      el.play().catch((error: unknown) => {
        if (cancelled) return
        const refused =
          error instanceof DOMException && error.name === 'NotAllowedError'
        if (!refused) return
        if (el.muted) {
          setAutoplayRefused(true)
          return
        }
        el.muted = true
        el.play().then(
          () => {
            if (!cancelled) setAutoplayMuted(true)
          },
          () => {
            if (!cancelled) setAutoplayRefused(true)
          },
        )
      })
    }
    el.addEventListener('canplay', start)
    if (el.readyState >= HTMLMediaElement.HAVE_FUTURE_DATA) start()
    return () => {
      cancelled = true
      el.removeEventListener('canplay', start)
    }
  }, [autoPlay])

  useEffect(() => {
    const el = videoRef.current
    if (!el || !autoplayMuted) return
    const onVolumeChange = () => {
      if (!el.muted) setAutoplayMuted(false)
    }
    el.addEventListener('volumechange', onVolumeChange)
    return () => el.removeEventListener('volumechange', onVolumeChange)
  }, [autoplayMuted])

  const unmute = () => {
    const el = videoRef.current
    if (!el) return
    el.muted = false
    if (el.volume === 0) el.volume = 1
    setAutoplayMuted(false)
  }

  useMediaSession(videoRef, video.name)

  const [controlsVisible, setControlsVisible] = useState(true)
  // Read by pointer handlers before React re-renders.
  const controlsVisibleRef = useRef(true)
  const controlsRef = useRef<HTMLDivElement | null>(null)
  const pointerOnControlsRef = useRef(false)
  const menuOpenRef = useRef(false)
  // A tap on hidden controls only brings them back; the next tap plays or pauses.
  const tapRevealsRef = useRef(false)
  const lastPointerTypeRef = useRef('mouse')
  const lastTapRef = useRef<{
    time: number
    zone: 'back' | 'center' | 'forward'
  } | null>(null)
  const tapTimerRef = useRef<number | undefined>(undefined)
  const hideControlsTimerRef = useRef<number | undefined>(undefined)

  const showControls = useCallback((shown: boolean) => {
    controlsVisibleRef.current = shown
    setControlsVisible(shown)
  }, [])

  const revealControls = useCallback(() => {
    showControls(true)
    window.clearTimeout(hideControlsTimerRef.current)
    hideControlsTimerRef.current = window.setTimeout(() => {
      const el = videoRef.current
      // Paused or finished, the controls stay up.
      if (!el || el.paused || el.ended) return
      if (
        menuOpenRef.current ||
        pointerOnControlsRef.current ||
        controlsRef.current?.querySelector(':focus-visible')
      ) {
        return
      }
      showControls(false)
    }, CONTROLS_IDLE_MS)
  }, [showControls])

  useEffect(() => {
    const container = playerContainerRef.current
    const video = videoRef.current
    if (!container || !video) {
      showControls(true)
      return
    }
    const onPointer = (event: PointerEvent) => {
      if (event.type === 'pointerdown') {
        lastPointerTypeRef.current = event.pointerType
        tapRevealsRef.current =
          event.pointerType !== 'mouse' &&
          event.target === video &&
          !controlsVisibleRef.current
      }
      // Menus portal into the container, so anything but the video holds the controls open.
      pointerOnControlsRef.current = event.target !== video
      revealControls()
    }
    const onLeave = (event: PointerEvent) => {
      if (event.pointerType !== 'mouse') return
      pointerOnControlsRef.current = false
      if (video.paused || video.ended || menuOpenRef.current) return
      window.clearTimeout(hideControlsTimerRef.current)
      hideControlsTimerRef.current = window.setTimeout(
        () => showControls(false),
        CONTROLS_LEAVE_MS,
      )
    }
    container.addEventListener('pointermove', onPointer)
    container.addEventListener('pointerdown', onPointer)
    container.addEventListener('pointerleave', onLeave)
    container.addEventListener('keydown', revealControls)
    video.addEventListener('play', revealControls)
    video.addEventListener('pause', revealControls)
    revealControls()
    return () => {
      window.clearTimeout(hideControlsTimerRef.current)
      window.clearTimeout(tapTimerRef.current)
      container.removeEventListener('pointermove', onPointer)
      container.removeEventListener('pointerdown', onPointer)
      container.removeEventListener('pointerleave', onLeave)
      container.removeEventListener('keydown', revealControls)
      video.removeEventListener('play', revealControls)
      video.removeEventListener('pause', revealControls)
    }
  }, [revealControls, showControls])

  const togglePlayback = () => {
    const el = videoRef.current
    if (!el) return
    const play = el.paused || el.ended
    if (play) void el.play().catch(() => {})
    else el.pause()
    showFlash(play ? 'play' : 'pause')
  }

  const seekBy = (seconds: number) => {
    const el = videoRef.current
    if (!el || !Number.isFinite(el.duration)) return
    el.currentTime = Math.min(
      el.duration,
      Math.max(0, el.currentTime + seconds),
    )
  }

  const onVideoClick = (event: ReactMouseEvent<HTMLVideoElement>) => {
    // Keyboard shortcuts follow focus in the Console, so take it with the click.
    if (!embed) playerContainerRef.current?.focus({ preventScroll: true })
    const revealOnly = tapRevealsRef.current
    tapRevealsRef.current = false
    if (lastPointerTypeRef.current !== 'touch') {
      if (!revealOnly) togglePlayback()
      return
    }
    // Touch: a double tap on either side seeks, as in video apps, and every
    // further tap seeks again. A single tap waits to rule that out.
    const rect = event.currentTarget.getBoundingClientRect()
    const fraction =
      rect.width > 0 ? (event.clientX - rect.left) / rect.width : 0.5
    const zone =
      fraction < 1 / 3 ? 'back' : fraction > 2 / 3 ? 'forward' : 'center'
    const last = lastTapRef.current
    lastTapRef.current = { time: event.timeStamp, zone }
    window.clearTimeout(tapTimerRef.current)
    if (
      zone !== 'center' &&
      last?.zone === zone &&
      event.timeStamp - last.time < DOUBLE_TAP_MS
    ) {
      seekBy(
        zone === 'back' ? -DOUBLE_TAP_SEEK_SECONDS : DOUBLE_TAP_SEEK_SECONDS,
      )
      showFlash(zone)
      revealControls()
      return
    }
    if (revealOnly) return
    if (zone === 'center') {
      togglePlayback()
      return
    }
    tapTimerRef.current = window.setTimeout(togglePlayback, DOUBLE_TAP_MS)
  }

  const onVideoDoubleClick = () => {
    // On touch, double taps seek instead.
    if (lastPointerTypeRef.current === 'touch') return
    toggleFullscreen()
  }

  /** Phones turn to landscape for a landscape video in full screen, as video apps do. */
  const lockLandscape = () => {
    const el = videoRef.current
    const orientation = screen.orientation as
      | (ScreenOrientation & { lock?: (orientation: string) => Promise<void> })
      | undefined
    if (!el || !orientation?.lock) return
    if (!window.matchMedia('(pointer: coarse)').matches) return
    if (el.videoWidth <= el.videoHeight) return
    void orientation.lock('landscape').catch(() => {})
  }

  const toggleFullscreen = () => {
    if (document.fullscreenElement) {
      void document.exitFullscreen()
      return
    }
    const container = playerContainerRef.current
    if (container?.requestFullscreen) {
      void container.requestFullscreen().then(lockLandscape, () => {})
      return
    }
    // iPhone Safari only supports fullscreen on the video element itself.
    const el = videoRef.current as
      | (HTMLVideoElement & { webkitEnterFullscreen?: () => void })
      | null
    el?.webkitEnterFullscreen?.()
  }

  const showSeekPreviews = seekPreviews ?? !embed
  const timelineQuery = useVideoTimeline(
    showSeekPreviews ? projectId : null,
    showSeekPreviews ? video.$id : null,
  )
  const timelineCues = timelineQuery.data?.cues

  let emptyPlaybackMessage: string | null = null
  const encodingPending =
    !playerSource &&
    renditions.some((rendition) => isVideoRenditionActive(rendition.status))
  if (!playerSource) {
    if (encodingPending) {
      emptyPlaybackMessage = t(
        'Renditions are still encoding. Playback starts when one is ready.',
      )
    } else if (renditions.length > 0) {
      emptyPlaybackMessage = t('No renditions are ready to play.')
    } else {
      emptyPlaybackMessage = t('Create a rendition to play this video.')
    }
  }

  const outputOptions: Array<{
    id: PlaybackOutput
    label: string
    disabledReason?: string
  }> = [
    {
      id: 'hls',
      label: 'HLS',
      disabledReason:
        readyIds.hls.length === 0 ? t('No ready HLS renditions') : undefined,
    },
    {
      id: 'dash',
      label: 'DASH',
      disabledReason:
        readyIds.dash.length === 0 ? t('No ready DASH renditions') : undefined,
    },
    {
      id: 'cmaf',
      label: 'CMAF',
      disabledReason:
        readyIds.cmaf.length === 0 ? t('No ready CMAF renditions') : undefined,
    },
    { id: 'source', label: t('Source') },
  ]

  const streamLoading =
    Boolean(playerSource) &&
    !state.fatalError &&
    state.firstFrameAt == null &&
    state.loadStartedAt != null
  // A spinner shows only on a slow start.
  const streamLoadingLong = useLongWait(streamLoading)

  useEffect(() => {
    // Docked, the controls sit beside the picture: captions keep to the edge.
    if (compact) {
      setSubtitleInsetPx(COMPACT_SUBTITLE_INSET_PX)
      return
    }
    const node = controlsRef.current
    if (!node) {
      setSubtitleInsetPx(64)
      return
    }
    // The scrim reaches above the controls without adding to their box, so
    // cues sit just over the seek bar.
    const measure = () => {
      const height = node.getBoundingClientRect().height
      setSubtitleInsetPx(
        height > 0
          ? Math.max(40, height + VIDEO_SUBTITLE_INSET_OVERLAY_GAP_PX)
          : 64,
      )
    }
    measure()
    const ro = new ResizeObserver(measure)
    ro.observe(node)
    return () => ro.disconnect()
  }, [embed, fullscreen, state.fatalError, compact])

  // Cues drop toward the edge while the controls are hidden, or when the
  // player has no control bar at all.
  const subtitleBottomPx =
    compact || (controls && controlsVisible)
      ? subtitleInsetPx
      : SUBTITLE_INSET_IDLE_PX

  const videoSubtitleStyle = useMemo(
    (): CSSProperties =>
      ({
        '--video-subtitle-bottom-offset': `${subtitleBottomPx}px`,
        '--video-subtitle-font-size': videoSubtitleSizeCss(subtitleSize),
      }) as CSSProperties,
    [subtitleBottomPx, subtitleSize],
  )

  const playerControls =
    controls && !state.fatalError && !locked ? (
      <VideoStreamPlayerControls
        videoRef={videoRef}
        output={output}
        outputOptions={outputOptions}
        onOutputChange={changeOutput}
        playerState={state}
        onSelectLevel={selectLevel}
        onSelectSubtitleTrack={selectSubtitleTrack}
        onSelectAudioTrack={setAudioTrack}
        subtitleSize={subtitleSize}
        onSelectSubtitleSize={selectSubtitleSize}
        onReload={() => reload()}
        hasNewRenditions={hasNewRenditions}
        scrubCues={timelineCues}
        fullscreen={fullscreen}
        onToggleFullscreen={toggleFullscreen}
        onOpenInspector={openInspector}
        shortcuts={embed ? 'page' : 'player'}
        pictureInPicture={!audioOnly}
        accentColor={accentColor}
        onMenuOpenChange={(open) => {
          menuOpenRef.current = open
          if (!open) revealControls()
        }}
        onShortcut={(kind) => {
          revealControls()
          if (kind) showFlash(kind)
        }}
        portalContainer={
          embed || fullscreen
            ? (playerContainerRef.current ?? undefined)
            : undefined
        }
        className={
          controlsVisible || compact
            ? 'pointer-events-auto'
            : 'pointer-events-none'
        }
      />
    ) : null

  const showInlineInspector = featured && !embed && !inspectorContainer

  const streamInspector = ({
    detached,
    portalContainer,
    onOpenWindow,
    onClose,
  }: {
    detached: boolean
    portalContainer?: HTMLElement
    onOpenWindow?: () => void
    onClose?: () => void
  }) => (
    <StreamDebugPanel
      player={state}
      qoe={qoe}
      renderedHeight={renderedHeight}
      context={{
        videoId: video.$id,
        videoName: video.name,
        output,
        manifestUrl: activeManifestUrl,
      }}
      activeManifestUrl={activeManifestUrl}
      manifests={manifests}
      onSeek={(seconds) => {
        if (videoRef.current) videoRef.current.currentTime = seconds
      }}
      onSelectLevel={setLevel}
      onClearEvents={clearEvents}
      onClearData={clearSessionData}
      onRestartSession={() => reload()}
      detached={detached}
      portalContainer={portalContainer}
      onOpenWindow={onOpenWindow}
      onClose={onClose}
    />
  )

  const frameMessage = emptyPlaybackMessage ? (
    <div
      role="status"
      data-player-state="locked"
      className="absolute inset-0 flex flex-col items-center justify-center gap-4 bg-[radial-gradient(ellipse_at_center,rgb(255_255_255/0.06),transparent_65%)] px-6 text-center"
    >
      <span className="flex size-12 items-center justify-center rounded-full bg-white/[0.06] text-white/75 ring-1 ring-white/10">
        {encodingPending ? (
          <PlayerSpinner className="size-6" />
        ) : (
          <Film className="size-5" aria-hidden />
        )}
      </span>
      <p className="max-w-sm text-[13px] leading-relaxed text-white/70">
        {emptyPlaybackMessage}
      </p>
    </div>
  ) : state.fatalError && !refusalShowing ? (
    <div
      role="alert"
      className="absolute inset-0 z-20 flex flex-col items-center justify-center gap-4 bg-black/80 px-6 text-center backdrop-blur-sm"
    >
      <span className="flex size-12 items-center justify-center rounded-full bg-white/[0.06] text-white/80 ring-1 ring-white/10">
        <TriangleAlert className="size-5" aria-hidden />
      </span>
      <div className="max-w-md space-y-1">
        <p className="text-[14px] font-medium text-white">
          {t('Playback failed')}
        </p>
        <p className="text-[13px] leading-relaxed text-white/65">
          {isConsolePlaybackMessage(state.fatalError)
            ? t(state.fatalError)
            : state.fatalError}
        </p>
      </div>
      <button
        type="button"
        onClick={() => reload()}
        className="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-full bg-white px-3.5 text-[13px] font-medium text-neutral-950 outline-none transition-colors hover:bg-white/90 focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:ring-offset-2 focus-visible:ring-offset-black"
      >
        <RotateCcw className="size-3.5" aria-hidden />
        {t('Try again')}
      </button>
    </div>
  ) : null

  const pictureOverlaysAllowed =
    !compact && !locked && !state.fatalError && Boolean(playerSource)
  const showSpinner = pictureOverlaysAllowed && (streamLoadingLong || buffering)
  const showStartButton =
    pictureOverlaysAllowed &&
    (!autoPlay || autoplayRefused) &&
    !started &&
    !showSpinner &&
    !refusalShowing
  const showReplayButton = pictureOverlaysAllowed && ended

  // What sits over the picture, the same in the Console and on the player page.
  const pictureOverlays = (
    <>
      {flash ? (
        <div
          key={flash.key}
          aria-hidden
          className={cn(
            'pointer-events-none absolute inset-y-0 z-[5] flex items-center justify-center',
            flash.kind === 'back'
              ? 'left-0 w-1/3'
              : flash.kind === 'forward'
                ? 'right-0 w-1/3'
                : 'inset-x-0',
          )}
        >
          <div
            className="animate-video-toggle-flash flex size-14 items-center justify-center rounded-full bg-black/45 text-white shadow-[0_8px_32px_rgb(0_0_0/0.35)] ring-1 ring-white/15 backdrop-blur-md @md:size-16 @3xl:size-[76px] [&_svg]:size-6 [&_svg]:fill-current @3xl:[&_svg]:size-8"
            onAnimationEnd={() => setFlash(null)}
          >
            {flash.kind === 'play' ? (
              <Play className="translate-x-0.5" />
            ) : flash.kind === 'pause' ? (
              <Pause />
            ) : flash.kind === 'back' ? (
              <Rewind className="-translate-x-0.5" />
            ) : (
              <FastForward className="translate-x-0.5" />
            )}
          </div>
        </div>
      ) : null}
      {showStartButton || showReplayButton ? (
        // The control bar holds the accessible button; this is the big target.
        <button
          type="button"
          tabIndex={-1}
          aria-hidden
          onClick={togglePlayback}
          className="absolute left-1/2 top-1/2 z-[5] flex size-14 -translate-x-1/2 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full bg-black/40 text-white shadow-[0_8px_32px_rgb(0_0_0/0.35)] ring-1 ring-white/20 backdrop-blur-xl transition-[transform,background-color] duration-200 ease-out hover:scale-105 hover:bg-black/55 active:scale-95 @md:size-16 @3xl:size-[76px] [&_svg]:size-6 @3xl:[&_svg]:size-8"
        >
          {showReplayButton ? (
            <RotateCcw aria-hidden />
          ) : (
            <Play className="translate-x-0.5 fill-current" aria-hidden />
          )}
        </button>
      ) : null}
      {showSpinner ? (
        <div
          role="status"
          aria-label={t('Loading video')}
          className="pointer-events-none absolute inset-0 z-[5] flex items-center justify-center"
        >
          <PlayerSpinner className="size-10 text-white @3xl:size-12" />
        </div>
      ) : null}
      {autoplayMuted && !compact ? (
        <button
          type="button"
          onClick={unmute}
          className="absolute end-3 top-3 z-[11] inline-flex h-9 cursor-pointer items-center gap-2 rounded-full bg-white/95 pe-3.5 ps-3 text-[13px] font-medium text-neutral-950 shadow-[0_8px_24px_rgb(0_0_0/0.35)] outline-none transition-[background-color,transform] duration-150 hover:bg-white active:scale-95 focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:ring-offset-2 focus-visible:ring-offset-black/40 @3xl:end-4 @3xl:top-4"
        >
          <VolumeX className="size-4" aria-hidden />
          {t('Unmute')}
        </button>
      ) : null}
    </>
  )

  if (
    embed &&
    !refusalShowing &&
    isEmbedUnauthorizedMessage(state.fatalError)
  ) {
    return <EmbedUnauthorized className="h-full" />
  }

  const frameVisible = state.firstFrameAt != null && !reloading
  const videoFrameClass = cn(
    'transition-opacity duration-[900ms] ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none',
    frameVisible ? 'opacity-100' : 'opacity-0',
  )

  // The picture and everything over it; the Console card and the player page
  // only differ in the box around it.
  const stage = (
    <>
      {audioOnly ? <AudioOnlyPlayerBackdrop title={video.name} /> : null}
      <video
        ref={videoRef}
        className={cn(
          'video-stream-player size-full object-contain outline-none',
          videoFrameClass,
          !controlsVisible ? 'cursor-none' : 'cursor-pointer',
        )}
        style={videoSubtitleStyle}
        playsInline
        loop={loop}
        aria-label={video.name}
        onClick={onVideoClick}
        onDoubleClick={onVideoDoubleClick}
      />
      {pictureOverlays}
      {frameMessage}
      {memberPreview && !state.fatalError && !fullscreen ? (
        <div
          role="status"
          data-video-private
          className="pointer-events-none absolute inset-x-3 top-3 z-10 flex items-start gap-3 rounded-xl border border-white/10 bg-neutral-950/75 px-3.5 py-3 text-white shadow-[0_12px_32px_-12px_rgb(0_0_0/0.6)] backdrop-blur-xl sm:inset-x-auto sm:start-3 sm:max-w-md"
        >
          <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-amber-400/15 text-amber-300 ring-1 ring-amber-300/25">
            <Lock className="size-3.5" aria-hidden />
          </span>
          <div className="min-w-0 text-[13px] leading-snug">
            <p className="font-medium text-white">
              {t('This video is private')}
            </p>
            <p className="mt-0.5 text-white/65">
              {t(
                'Visitors get an error here. You can watch because you are a project member.',
              )}
            </p>
          </div>
        </div>
      ) : null}
      {fullscreen ? (
        <div
          aria-hidden
          className={cn(
            'pointer-events-none absolute inset-x-0 top-0 z-10 flex items-center gap-3 bg-[linear-gradient(to_bottom,rgb(0_0_0/0.62),rgb(0_0_0/0.24)_55%,transparent)] px-5 pb-16 pt-[max(1.25rem,env(safe-area-inset-top))] transition duration-300 ease-out sm:px-7 sm:pt-[max(1.5rem,env(safe-area-inset-top))]',
            !controlsVisible && '-translate-y-1 opacity-0',
          )}
        >
          {!embed ? (
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/10 text-white ring-1 ring-white/15 backdrop-blur-md">
              <AppwriteMarkIcon className="size-[18px]" />
            </span>
          ) : null}
          <p className="min-w-0 truncate text-[15px] font-medium text-white [text-shadow:0_1px_2px_rgb(0_0_0/0.4)] sm:text-[17px]">
            {video.name}
          </p>
        </div>
      ) : null}
      {playerControls && !compact ? (
        <div
          ref={controlsRef}
          className={cn(
            'pointer-events-none absolute inset-x-0 bottom-0 z-10 transition duration-300 ease-out',
            !controlsVisible && 'translate-y-1 opacity-0',
          )}
        >
          {playerControls}
        </div>
      ) : null}
    </>
  )

  return (
    <div
      className={cn(
        'min-w-0 max-w-full',
        featured && !fullscreen && 'w-full',
        embed
          ? 'animate-player-fade-in flex h-full min-h-0 flex-col'
          : 'space-y-4',
      )}
    >
      <div
        ref={playerContainerRef}
        data-video-player
        data-player-docked={compact || undefined}
        tabIndex={-1}
        className={cn(
          'min-w-0 outline-none',
          compact &&
            'animate-in fade-in-0 slide-in-from-top-1 sticky top-0 z-20 -mx-4 border-b border-border bg-background/90 px-4 py-2 shadow-sm backdrop-blur-xl duration-200 supports-[backdrop-filter]:bg-background/75 sm:-mx-6 sm:px-6',
          embed && 'relative flex min-h-0 flex-1 flex-col dark bg-black',
          (fullscreen || overlayControls) && 'dark relative bg-black',
          (fullscreen || overlayControls) && !controlsVisible && 'cursor-none',
        )}
      >
        <div
          className={cn(
            'flex w-full min-w-0',
            featured && !fullscreen ? 'justify-stretch' : 'justify-center',
            compact && 'items-center gap-4',
            embed && 'min-h-0 flex-1',
            (fullscreen || overlayControls) && 'absolute inset-0',
          )}
        >
          {embed ? (
            <div className="@container relative size-full shrink-0 overflow-hidden bg-black">
              {stage}
            </div>
          ) : (
            <div
              className={cn(
                'animate-player-fade-in relative flex min-w-0 max-w-full flex-col overflow-hidden',
                compact && 'shrink-0',
                fullscreen
                  ? 'size-full min-h-0'
                  : compact
                    ? 'rounded-md border border-border'
                    : 'rounded-xl border border-border shadow-sm',
              )}
              style={fullscreen ? undefined : { width: viewportStyle.width }}
            >
              <div
                className={cn(
                  '@container relative w-full overflow-hidden bg-black',
                  fullscreen ? 'min-h-0 flex-1' : 'shrink-0',
                )}
                style={
                  fullscreen
                    ? undefined
                    : {
                        aspectRatio:
                          'aspectRatio' in viewportStyle
                            ? viewportStyle.aspectRatio
                            : undefined,
                        maxHeight: viewportStyle.maxHeight,
                      }
                }
              >
                {stage}
              </div>
            </div>
          )}
          {compact ? (
            <DockedPlayerControls videoRef={videoRef} title={video.name} />
          ) : null}
        </div>
      </div>
      {dockSpacer > 0 ? (
        <div aria-hidden style={{ height: dockSpacer }} className="!mt-0" />
      ) : null}

      {showInlineInspector
        ? streamInspector({
            detached: false,
            onOpenWindow: openInspector,
          })
        : null}

      {!embed && inspectorContainer ? (
        <div className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-card/50 px-3 py-2">
          <SquareArrowOutUpRight className="h-3.5 w-3.5 text-muted-foreground" />
          <span className="min-w-0 flex-1 text-[12px] text-muted-foreground">
            {t('The inspector is open in a separate window.')}
          </span>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-7 text-[12px]"
            onClick={openInspector}
          >
            {t('Show window')}
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-7 text-[12px]"
            onClick={inspector?.close}
          >
            {t('Close')}
          </Button>
        </div>
      ) : null}

      {inspectorContainer
        ? createPortal(
            streamInspector({
              detached: true,
              portalContainer: inspectorContainer,
              onClose: inspector?.close,
            }),
            inspectorContainer,
          )
        : null}
    </div>
  )
}
