import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type CSSProperties,
} from 'react'
import { createPortal } from 'react-dom'
import type { Models } from '@appwrite.io/console'
import { Pause, Play, SquareArrowOutUpRight } from 'lucide-react'
import { sdk } from '@/lib/appwrite/sdk'
import { isEmbedUnauthorizedMessage } from '@/lib/videos/embed-player-errors'
import { applyVideoStreamAccess } from '@/lib/videos/video-stream-access'
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
  resolveVideoViewportAspect,
} from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { useVideoPlayerPrefs } from '@/hooks/use-video-player-prefs'
import { StreamDebugPanel, type StreamManifest } from './StreamDebugPanel'
import {
  VideoStreamPlayerControls,
  type PlaybackOutput,
} from './VideoStreamPlayerControls'
import { useStreamPlayer, type PlayerSource } from './useStreamPlayer'
import {
  inspectorWindowTitle,
  useVideoInspector,
} from './VideoInspectorContext'
import { useQoeTracker } from './useQoeTracker'
import { AudioOnlyPlayerBackdrop } from './AudioOnlyPlayerBackdrop'
import {
  resolveVideoSubtitleSize,
  VIDEO_SUBTITLE_INSET_CONSOLE_PX,
  VIDEO_SUBTITLE_INSET_OVERLAY_GAP_PX,
  VIDEO_SUBTITLE_OVERLAY_GRADIENT_PAD_PX,
  videoSubtitleSizeCss,
  type VideoSubtitleSize,
} from '@/lib/videos/player-subtitle-display'

const FULLSCREEN_CONTROLS_IDLE_MS = 2500

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
}

export function VideoStreamPlayer({
  projectId,
  video,
  renditions,
  variant = 'default',
  layout = 'console',
  initialStartSeconds,
}: VideoStreamPlayerProps) {
  const t = useT()
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const featured = variant === 'featured'
  const embed = layout === 'embed'
  const streamAccess = embed ? 'end-user' : 'console'
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

  const sourceFileUrl = useMemo(
    () =>
      applyVideoStreamAccess(
        sdk.forProject(projectId).storage.getFileView({
          bucketId: video.bucketId,
          fileId: video.fileId,
        }),
        streamAccess,
      ),
    [projectId, video.bucketId, video.fileId, streamAccess],
  )

  const posterUrl = useMemo(
    () =>
      video.previewId
        ? applyVideoStreamAccess(
            sdk.forProject(projectId).videos.getPreview({
              videoId: video.$id,
              previewId: video.previewId,
              width: 1280,
            }),
            streamAccess,
          )
        : undefined,
    [projectId, video.$id, video.previewId, streamAccess],
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

  const playerSource: PlayerSource | null = useMemo(() => {
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
  }, [activeManifestUrl, hasStorageFile, output, readyIds, sourceFileUrl])

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
  // once. Renditions often finish seconds apart, so batch them.
  useEffect(() => {
    if (!hasNewRenditions) return
    const timer = window.setTimeout(
      () => reloadRef.current({ silent: true }),
      1500,
    )
    return () => window.clearTimeout(timer)
  }, [hasNewRenditions, outputReadyKey])

  const { state, setLevel, setSubtitleTrack, clearEvents } = useStreamPlayer(
    videoRef,
    playerSource,
    reloadToken,
    streamAccess,
  )

  useEffect(() => {
    if (state.firstFrameAt != null || state.fatalError) setReloading(false)
  }, [state.firstFrameAt, state.fatalError])

  const { read: readPlayerPrefs, update: updatePlayerPrefs } = playerPrefs

  useEffect(() => {
    const el = videoRef.current
    if (!el) return
    const saved = readPlayerPrefs()
    el.volume = saved.volume
    el.muted = saved.muted
    const onVolumeChange = () =>
      updatePlayerPrefs({ volume: el.volume, muted: el.muted })
    el.addEventListener('volumechange', onVolumeChange)
    return () => el.removeEventListener('volumechange', onVolumeChange)
  }, [readPlayerPrefs, updatePlayerPrefs])

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
  const playerMaxHeight = featured
    ? 'max(240px, min(800px, calc(100cqh - 32px)))'
    : 'max(240px, min(800px, calc(100cqh - 24px)))'
  const viewportAspect = useMemo(() => {
    if (mediaPixelSize) {
      return mediaPixelSize
    }
    return resolveVideoViewportAspect(
      video.width,
      video.height,
      video.aspectRatio,
    )
  }, [mediaPixelSize, video.width, video.height, video.aspectRatio])
  const viewportStyle = useMemo(
    () =>
      embed
        ? { width: '100%', height: '100%', maxHeight: 'none' }
        : getVideoPlayerViewportStyle(
            viewportAspect,
            playerMaxHeight,
            featured ? 'width' : 'height',
          ),
    [embed, featured, viewportAspect, playerMaxHeight],
  )

  const audioOnly =
    (video.duration ?? 0) > 0 && !isVideoMetadataReady(video)

  const playerContainerRef = useRef<HTMLDivElement | null>(null)
  const [fullscreen, setFullscreen] = useState(false)

  useEffect(() => {
    const onChange = () => {
      setFullscreen(
        playerContainerRef.current != null &&
          document.fullscreenElement === playerContainerRef.current,
      )
    }
    document.addEventListener('fullscreenchange', onChange)
    return () => document.removeEventListener('fullscreenchange', onChange)
  }, [])

  const [toggleFlash, setToggleFlash] = useState<{
    action: 'play' | 'pause'
    key: number
  } | null>(null)
  const [controlsVisible, setControlsVisible] = useState(true)
  const controlsRef = useRef<HTMLDivElement | null>(null)
  const pointerOnControlsRef = useRef(false)
  const hideControlsTimerRef = useRef<number | undefined>(undefined)

  const revealControls = useCallback(() => {
    setControlsVisible(true)
    window.clearTimeout(hideControlsTimerRef.current)
    hideControlsTimerRef.current = window.setTimeout(() => {
      if (
        pointerOnControlsRef.current ||
        controlsRef.current?.querySelector(':focus-visible')
      ) {
        return
      }
      setControlsVisible(false)
    }, FULLSCREEN_CONTROLS_IDLE_MS)
  }, [])

  useEffect(() => {
    const container = playerContainerRef.current
    const video = videoRef.current
    if (!container || !video) {
      setControlsVisible(true)
      return
    }
    const onPointer = (event: PointerEvent) => {
      // Menus portal into the container, so anything but the video holds the controls open.
      pointerOnControlsRef.current = event.target !== video
      revealControls()
    }
    container.addEventListener('pointermove', onPointer)
    container.addEventListener('pointerdown', onPointer)
    container.addEventListener('keydown', revealControls)
    video.addEventListener('play', revealControls)
    video.addEventListener('pause', revealControls)
    revealControls()
    return () => {
      window.clearTimeout(hideControlsTimerRef.current)
      container.removeEventListener('pointermove', onPointer)
      container.removeEventListener('pointerdown', onPointer)
      container.removeEventListener('keydown', revealControls)
      video.removeEventListener('play', revealControls)
      video.removeEventListener('pause', revealControls)
    }
  }, [revealControls])

  const toggleFullscreen = () => {
    if (document.fullscreenElement) {
      void document.exitFullscreen()
      return
    }
    const container = playerContainerRef.current
    if (container?.requestFullscreen) {
      void container.requestFullscreen()
      return
    }
    // iPhone Safari only supports fullscreen on the video element itself.
    const el = videoRef.current as
      | (HTMLVideoElement & { webkitEnterFullscreen?: () => void })
      | null
    el?.webkitEnterFullscreen?.()
  }

  const timelineQuery = useVideoTimeline(
    embed ? null : projectId,
    embed ? null : video.$id,
  )
  const timelineCues = timelineQuery.data?.cues

  let emptyPlaybackMessage: string | null = null
  if (!playerSource) {
    if (renditions.some((rendition) => isVideoRenditionActive(rendition.status))) {
      emptyPlaybackMessage = t(
        'Renditions are still encoding. Playback starts when one is ready.',
      )
    } else if (renditions.length > 0) {
      emptyPlaybackMessage = t('No renditions are ready to play.')
    } else {
      emptyPlaybackMessage = t('This video has no source to play.')
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

  /** Overlay-style controls, same chrome as the embed player page. */
  const menuOnlyAdvanced = true

  useEffect(() => {
    const node = controlsRef.current
    if (!node) {
      setSubtitleInsetPx(72)
      return
    }
    const measure = () => {
      const height = node.getBoundingClientRect().height
      setSubtitleInsetPx(
        height > 0
          ? Math.max(
              48,
              height -
                VIDEO_SUBTITLE_OVERLAY_GRADIENT_PAD_PX +
                VIDEO_SUBTITLE_INSET_OVERLAY_GAP_PX,
            )
          : 72,
      )
    }
    measure()
    const ro = new ResizeObserver(measure)
    ro.observe(node)
    return () => ro.disconnect()
  }, [embed, fullscreen, state.fatalError])

  const videoSubtitleStyle = useMemo(
    (): CSSProperties =>
      ({
        '--video-subtitle-bottom-offset': `${subtitleInsetPx}px`,
        '--video-subtitle-font-size': videoSubtitleSizeCss(subtitleSize),
      }) as CSSProperties,
    [subtitleInsetPx, subtitleSize],
  )

  const playerControls = !state.fatalError ? (
    <VideoStreamPlayerControls
      videoRef={videoRef}
      output={output}
      outputOptions={outputOptions}
      onOutputChange={changeOutput}
      playerState={state}
      onSelectLevel={selectLevel}
      onSelectSubtitleTrack={selectSubtitleTrack}
      subtitleSize={subtitleSize}
      onSelectSubtitleSize={selectSubtitleSize}
      onReload={() => reload()}
      hasNewRenditions={hasNewRenditions}
      isLoading={streamLoading}
      scrubCues={timelineCues}
      fullscreen={fullscreen}
      onToggleFullscreen={toggleFullscreen}
      onOpenInspector={openInspector}
      menuOnlyAdvanced={menuOnlyAdvanced}
      portalContainer={
        embed || fullscreen
          ? (playerContainerRef.current ?? undefined)
          : undefined
      }
      className={
        controlsVisible ? 'pointer-events-auto' : 'pointer-events-none'
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
    <div className="absolute inset-0 flex items-center justify-center bg-black px-6 text-center">
      <p className="max-w-sm text-[13px] leading-relaxed text-white/75">
        {emptyPlaybackMessage}
      </p>
    </div>
  ) : state.fatalError ? (
    <div className="absolute inset-0 flex items-center justify-center bg-black/70 px-6 text-center">
      <p className="max-w-md text-[13px] text-white/80">
        {isEmbedUnauthorizedMessage(state.fatalError)
          ? t(state.fatalError)
          : state.fatalError}
      </p>
    </div>
  ) : null

  if (embed && isEmbedUnauthorizedMessage(state.fatalError)) {
    return <EmbedUnauthorized className="h-full" />
  }

  const frameVisible = state.firstFrameAt != null && !reloading
  const videoFrameClass = cn(
    'transition-opacity duration-[900ms] ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none',
    frameVisible ? 'opacity-100' : 'opacity-0',
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
        className={cn(
          'min-w-0',
          embed && 'relative flex min-h-0 flex-1 flex-col dark bg-black',
          (fullscreen || overlayControls) && 'dark relative bg-black',
          (fullscreen || overlayControls) &&
            !controlsVisible &&
            'cursor-none',
        )}
      >
        <div
          className={cn(
            'flex w-full min-w-0',
            featured && !fullscreen ? 'justify-stretch' : 'justify-center',
            embed && 'min-h-0 flex-1',
            (fullscreen || overlayControls) && 'absolute inset-0',
          )}
        >
          {embed ? (
            <div className="relative size-full shrink-0 overflow-hidden bg-black">
              {audioOnly ? (
                <AudioOnlyPlayerBackdrop title={video.name} />
              ) : null}
              <video
                ref={videoRef}
                className={cn(
                  'video-stream-player size-full object-contain outline-none',
                  videoFrameClass,
                  !controlsVisible ? 'cursor-none' : 'cursor-pointer',
                )}
                style={videoSubtitleStyle}
                playsInline
                poster={posterUrl}
                aria-label={video.name}
                onClick={() => {
                  const el = videoRef.current
                  if (!el) return
                  const play = el.paused
                  if (play) void el.play()
                  else el.pause()
                  setToggleFlash((current) => ({
                    action: play ? 'play' : 'pause',
                    key: (current?.key ?? 0) + 1,
                  }))
                }}
                onDoubleClick={toggleFullscreen}
              />
              {toggleFlash ? (
                <div
                  key={toggleFlash.key}
                  aria-hidden
                  className="pointer-events-none absolute inset-0 flex items-center justify-center"
                >
                  <div
                    className="animate-video-toggle-flash flex size-20 items-center justify-center rounded-full border border-white/20 bg-black/35 text-white shadow-2xl backdrop-blur-md sm:size-28"
                    onAnimationEnd={() => setToggleFlash(null)}
                  >
                    {toggleFlash.action === 'play' ? (
                      <Play className="size-9 translate-x-0.5 fill-current sm:size-12" />
                    ) : (
                      <Pause className="size-9 fill-current sm:size-12" />
                    )}
                  </div>
                </div>
              ) : null}
              {frameMessage}
              {playerControls ? (
                <div
                  ref={controlsRef}
                  className={cn(
                    'pointer-events-none absolute inset-x-0 bottom-0 z-10 transition duration-300',
                    !controlsVisible && 'translate-y-2 opacity-0',
                  )}
                >
                  {playerControls}
                </div>
              ) : null}
            </div>
          ) : (
            <div
              className={cn(
                'animate-player-fade-in relative flex min-w-0 max-w-full flex-col overflow-hidden',
                fullscreen
                  ? 'size-full min-h-0'
                  : 'rounded-xl border border-border shadow-sm',
              )}
              style={fullscreen ? undefined : { width: viewportStyle.width }}
            >
              <div
                className={cn(
                  'relative w-full overflow-hidden bg-black',
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
                {audioOnly ? (
                  <AudioOnlyPlayerBackdrop title={video.name} />
                ) : null}
                <video
                  ref={videoRef}
                  className={cn(
                    'video-stream-player size-full object-contain outline-none',
                    videoFrameClass,
                    !controlsVisible ? 'cursor-none' : 'cursor-pointer',
                  )}
                  style={videoSubtitleStyle}
                  playsInline
                  poster={posterUrl}
                  aria-label={video.name}
                  onClick={() => {
                    const el = videoRef.current
                    if (!el) return
                    const play = el.paused
                    if (play) void el.play()
                    else el.pause()
                    setToggleFlash((current) => ({
                      action: play ? 'play' : 'pause',
                      key: (current?.key ?? 0) + 1,
                    }))
                  }}
                  onDoubleClick={toggleFullscreen}
                />
                {toggleFlash ? (
                  <div
                    key={toggleFlash.key}
                    aria-hidden
                    className="pointer-events-none absolute inset-0 flex items-center justify-center"
                  >
                    <div
                      className="animate-video-toggle-flash flex size-20 items-center justify-center rounded-full border border-white/20 bg-black/35 text-white shadow-2xl backdrop-blur-md sm:size-28"
                      onAnimationEnd={() => setToggleFlash(null)}
                    >
                      {toggleFlash.action === 'play' ? (
                        <Play className="size-9 translate-x-0.5 fill-current sm:size-12" />
                      ) : (
                        <Pause className="size-9 fill-current sm:size-12" />
                      )}
                    </div>
                  </div>
                ) : null}
                {frameMessage}
                {playerControls ? (
                  <div
                    ref={controlsRef}
                    className={cn(
                      'pointer-events-none absolute inset-x-0 bottom-0 z-10 transition duration-300',
                      !controlsVisible && 'translate-y-2 opacity-0',
                    )}
                  >
                    {playerControls}
                  </div>
                ) : null}
              </div>
            </div>
          )}
        </div>
        {fullscreen && !embed ? (
          <div
            aria-hidden
            className={cn(
              'pointer-events-none absolute start-4 top-4 flex size-11 items-center justify-center rounded-xl border border-white/15 bg-black/30 text-white shadow-sm backdrop-blur-md transition duration-300 sm:start-6 sm:top-6',
              !controlsVisible && '-translate-y-2 opacity-0',
            )}
          >
            <AppwriteMarkIcon className="size-5" />
          </div>
        ) : null}
      </div>

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
