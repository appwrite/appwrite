import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import type { Models } from '@appwrite.io/console'
import { Info, Loader2, Pause, Play, SquareArrowOutUpRight } from 'lucide-react'
import { sdk } from '@/lib/appwrite/sdk'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
import { Button } from '@/components/ui/button'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
import { AppwriteMarkIcon } from '@/components/global/shared/AppwriteMarkIcon'
import {
  isVideoRenditionActive,
  parseVideoProgress,
  useVideoTimeline,
  videoSourceHasAudio,
} from '@/lib/react-query/hooks/videos'
import { VideoFormatLabel } from '../VideoOutputBadge'
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

const FULLSCREEN_CONTROLS_IDLE_MS = 2500

export interface VideoStreamPlayerProps {
  projectId: string
  video: Models.Video
  renditions: Models.VideoRendition[]
  /** Rendition progress card above the player. */
  showProcessing?: boolean
  /** Overview layout: slightly shorter max height. */
  variant?: 'default' | 'featured'
}

export function VideoStreamPlayer({
  projectId,
  video,
  renditions,
  showProcessing = false,
  variant = 'default',
}: VideoStreamPlayerProps) {
  const t = useT()
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const featured = variant === 'featured'

  const readyIds = useMemo(() => {
    const byOutput: Record<string, string[]> = { hls: [], dash: [], cmaf: [] }
    for (const rendition of renditions) {
      if (rendition.status === 'ready') {
        byOutput[rendition.output]?.push(rendition.$id)
      }
    }
    for (const key of Object.keys(byOutput)) byOutput[key].sort()
    return byOutput
  }, [renditions])

  const preferredOutput: PlaybackOutput =
    readyIds.hls.length > 0
      ? 'hls'
      : readyIds.cmaf.length > 0
        ? 'cmaf'
        : readyIds.dash.length > 0
          ? 'dash'
          : 'source'
  const playerPrefs = useVideoPlayerPrefs()
  const [chosenOutput, setChosenOutput] = useState<PlaybackOutput | null>(
    () => {
      const saved = playerPrefs.read().output
      if (!saved) return null
      return saved === 'source' || readyIds[saved]?.length ? saved : null
    },
  )
  const output = chosenOutput ?? preferredOutput
  const changeOutput = (next: PlaybackOutput) => {
    setChosenOutput(next)
    playerPrefs.update({ output: next })
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
      withAdminMode(
        sdk.forProject(projectId).storage.getFileView({
          bucketId: video.bucketId,
          fileId: video.fileId,
        }),
      ),
    [projectId, video.bucketId, video.fileId],
  )

  const posterUrl = useMemo(
    () =>
      video.previewId
        ? withAdminMode(
            sdk.forProject(projectId).videos.getPreview({
              videoId: video.$id,
              previewId: video.previewId,
              width: 1280,
            }),
          )
        : undefined,
    [projectId, video.$id, video.previewId],
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

  const playerSource: PlayerSource | null = useMemo(() => {
    if (!activeManifestUrl) {
      return { url: sourceFileUrl, type: 'file' }
    }
    if (output === 'dash') {
      return { url: activeManifestUrl, type: 'dash' }
    }
    return { url: activeManifestUrl, type: 'hls' }
  }, [activeManifestUrl, sourceFileUrl, output])

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
    setMediaPixelSize(null)
  }, [playerSource?.url, reloadToken])

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

  // `cqh` is the visible height of the VideoPage scroll area; the offset
  // reserves room for the controls bar so the whole player stays on screen.
  const playerMaxHeight = featured
    ? 'max(200px, min(800px, calc(100cqh - 130px)))'
    : 'max(240px, min(800px, calc(100cqh - 120px)))'
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
    () => getVideoPlayerViewportStyle(viewportAspect, playerMaxHeight),
    [viewportAspect, playerMaxHeight],
  )

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
    if (!fullscreen || !container || !video) {
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
  }, [fullscreen, revealControls])

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

  const timelineQuery = useVideoTimeline(projectId, video.$id)
  const timelineCues = timelineQuery.data?.cues

  const activeRenditions = renditions.filter((r) =>
    isVideoRenditionActive(r.status),
  )
  const failedRenditions = renditions.filter((r) => r.status === 'error')

  const hasAdaptiveReady =
    readyIds.hls.length + readyIds.cmaf.length + readyIds.dash.length > 0

  const sourceMissingAudio =
    video.status === 'ready' &&
    (video.duration ?? 0) > 0 &&
    !videoSourceHasAudio(video)

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

  const inspectorPanel = (container: HTMLElement) => (
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
      detached
      portalContainer={container}
      onClose={inspector?.close}
    />
  )

  return (
    <div className="min-w-0 max-w-full space-y-4">
      {showProcessing &&
      (activeRenditions.length > 0 || failedRenditions.length > 0) ? (
        <div className="rounded-xl border border-border bg-card/50 px-6 py-4">
          <div className="flex items-center gap-2">
            {activeRenditions.length > 0 ? (
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            ) : (
              <Info className="h-4 w-4 text-muted-foreground" />
            )}
            <h3 className="text-[13px] font-semibold text-foreground">
              {activeRenditions.length > 0
                ? t('Processing renditions')
                : t('Some renditions failed')}
            </h3>
          </div>
          <div className="mt-3 space-y-2">
            {[...activeRenditions, ...failedRenditions].map((rendition) => (
              <div
                key={rendition.$id}
                className="grid grid-cols-[minmax(0,160px)_1fr] items-center gap-3"
              >
                <VideoFormatLabel
                  format={rendition.output}
                  className="min-w-0 truncate font-mono text-[12px]"
                >
                  {rendition.output.toUpperCase()} {rendition.height}p
                </VideoFormatLabel>
                {rendition.status === 'error' ? (
                  <span className="text-[12px] text-red-600 dark:text-red-400">
                    {t('Failed')}
                  </span>
                ) : (
                  <ProgressBarRow
                    value={parseVideoProgress(rendition.progress)}
                    className="mb-0"
                  />
                )}
              </div>
            ))}
          </div>
        </div>
      ) : null}

      {sourceMissingAudio ? (
        <div className="rounded-xl border border-border bg-card/50 px-4 py-3 sm:px-6">
          <div className="flex gap-2">
            <Info className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <p className="text-[13px] text-muted-foreground">
              {t(
                'This video has no detected audio track after prepare. Adaptive streams and the original file will play without sound. Re-upload a file with an audio track or check the source in storage.',
              )}
            </p>
          </div>
        </div>
      ) : null}

      <div
        ref={playerContainerRef}
        className={cn(
          'min-w-0',
          fullscreen && 'dark relative bg-black',
          fullscreen && !controlsVisible && 'cursor-none',
        )}
      >
        <div
          className={cn(
            'flex w-full min-w-0 justify-center',
            fullscreen && 'absolute inset-0',
          )}
        >
          <div
            className={cn(
              'relative shrink-0 overflow-hidden bg-black',
              fullscreen ? 'size-full' : 'rounded-xl',
            )}
            style={fullscreen ? undefined : viewportStyle}
          >
            <video
              ref={videoRef}
              className={cn(
                'size-full object-contain outline-none',
                reloading
                  ? 'opacity-0'
                  : 'opacity-100 transition-opacity duration-700 ease-out',
                fullscreen && !controlsVisible
                  ? 'cursor-none'
                  : 'cursor-pointer',
              )}
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
            {state.fatalError ? (
              <div className="absolute inset-0 flex items-center justify-center bg-black/70 px-6 text-center">
                <p className="max-w-md text-[13px] text-white/80">
                  {state.fatalError}
                </p>
              </div>
            ) : null}
          </div>
        </div>
        {fullscreen ? (
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
        {!state.fatalError ? (
          <div
            ref={controlsRef}
            className={cn(
              fullscreen &&
                'pointer-events-none absolute inset-x-0 bottom-0 px-4 pb-16 transition duration-300 sm:px-6 sm:pb-24',
              fullscreen && !controlsVisible && 'translate-y-2 opacity-0',
            )}
          >
            <VideoStreamPlayerControls
              videoRef={videoRef}
              output={output}
              outputOptions={outputOptions}
              onOutputChange={changeOutput}
              playerState={state}
              onSelectLevel={selectLevel}
              onSelectSubtitleTrack={selectSubtitleTrack}
              onReload={() => reload()}
              hasNewRenditions={hasNewRenditions}
              isLoading={streamLoading}
              scrubCues={timelineCues}
              fullscreen={fullscreen}
              onToggleFullscreen={toggleFullscreen}
              onOpenInspector={openInspector}
              portalContainer={
                fullscreen
                  ? (playerContainerRef.current ?? undefined)
                  : undefined
              }
              className={cn(
                fullscreen && 'mx-auto max-w-7xl',
                fullscreen &&
                  (controlsVisible
                    ? 'pointer-events-auto'
                    : 'pointer-events-none'),
              )}
            />
          </div>
        ) : null}
      </div>

      {inspectorContainer ? (
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

      {output === 'source' ? (
        <p className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
          <Info className="h-3.5 w-3.5 shrink-0" />
          {hasAdaptiveReady
            ? t(
                'Playing the original Storage file. Switch to HLS, DASH, or CMAF to test adaptive streaming.',
              )
            : t(
                'Playing the original Storage file. Create HLS, DASH, or CMAF renditions to test adaptive streaming.',
              )}
        </p>
      ) : null}

      {inspectorContainer
        ? createPortal(inspectorPanel(inspectorContainer), inspectorContainer)
        : null}
    </div>
  )
}
