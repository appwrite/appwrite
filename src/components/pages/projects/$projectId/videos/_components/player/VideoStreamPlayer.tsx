import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import type { Models } from '@appwrite.io/console'
import { Info, Loader2, SquareArrowOutUpRight } from 'lucide-react'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
import { Button } from '@/components/ui/button'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
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
import { StreamDebugPanel, type StreamManifest } from './StreamDebugPanel'
import {
  VideoStreamPlayerControls,
  type PlaybackOutput,
} from './VideoStreamPlayerControls'
import { useStreamPlayer, type PlayerSource } from './useStreamPlayer'
import { usePopoutWindow } from './usePopoutWindow'

export interface VideoStreamPlayerProps {
  projectId: string
  video: Models.Video
  renditions: Models.VideoRendition[]
  /** Show the stream inspector inline (the Debugger page). */
  showDebugTools?: boolean
  /** Rendition progress card above the player. */
  showProcessing?: boolean
  /** Overview layout: slightly shorter max height. */
  variant?: 'default' | 'featured'
}

export function VideoStreamPlayer({
  projectId,
  video,
  renditions,
  showDebugTools = true,
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
  const [chosenOutput, setChosenOutput] = useState<PlaybackOutput | null>(null)
  const output = chosenOutput ?? preferredOutput

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
  const onPopoutBlocked = useCallback(() => {
    toast.error(
      t(
        'Your browser blocked the window. Allow pop-ups for this site to open the stream inspector.',
      ),
    )
  }, [t])
  const popout = usePopoutWindow({
    name: `appwrite-video-inspector-${video.$id}`,
    title: `${video.name} · ${t('Stream inspector')}`,
    onBlocked: onPopoutBlocked,
  })
  const inspectorMode = popout.isOpen
    ? 'window'
    : showDebugTools
      ? 'inline'
      : 'closed'
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

  const reload = () => {
    setLoadedKey(outputReadyKey)
    setReloadToken((n) => n + 1)
  }

  const { state, setLevel, setSubtitleTrack, clearEvents } = useStreamPlayer(
    videoRef,
    playerSource,
    reloadToken,
  )

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

  const inspectorPanel = (detached: boolean) => (
    <StreamDebugPanel
      player={state}
      activeManifestUrl={activeManifestUrl}
      manifests={manifests}
      onSeek={(seconds) => {
        if (videoRef.current) videoRef.current.currentTime = seconds
      }}
      onSelectLevel={setLevel}
      onClearEvents={clearEvents}
      detached={detached}
      portalContainer={detached ? (popout.container ?? undefined) : undefined}
      onOpenWindow={detached ? undefined : popout.open}
      onClose={detached ? popout.close : undefined}
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
          fullscreen && 'flex flex-col bg-background p-4 sm:p-6',
        )}
      >
        <div
          className={cn(
            'flex w-full min-w-0 justify-center',
            fullscreen && 'min-h-0 flex-1',
          )}
        >
          <div
            className={cn(
              'relative shrink-0 overflow-hidden rounded-xl bg-black',
              fullscreen && 'size-full',
            )}
            style={fullscreen ? undefined : viewportStyle}
          >
            <video
              ref={videoRef}
              className="size-full cursor-pointer object-contain outline-none"
              playsInline
              poster={posterUrl}
              aria-label={video.name}
              onClick={() => {
                const el = videoRef.current
                if (!el) return
                if (el.paused) void el.play()
                else el.pause()
              }}
              onDoubleClick={toggleFullscreen}
            />
            {state.fatalError ? (
              <div className="absolute inset-0 flex items-center justify-center bg-black/70 px-6 text-center">
                <p className="max-w-md text-[13px] text-white/80">
                  {state.fatalError}
                </p>
              </div>
            ) : null}
          </div>
        </div>
        {!state.fatalError ? (
          <VideoStreamPlayerControls
            videoRef={videoRef}
            output={output}
            outputOptions={outputOptions}
            onOutputChange={setChosenOutput}
            playerState={state}
            onSelectLevel={setLevel}
            onSelectSubtitleTrack={setSubtitleTrack}
            onReload={reload}
            hasNewRenditions={hasNewRenditions}
            isLoading={streamLoading}
            scrubCues={timelineCues}
            fullscreen={fullscreen}
            onToggleFullscreen={toggleFullscreen}
            onOpenInspector={showDebugTools ? undefined : popout.open}
            portalContainer={
              fullscreen ? (playerContainerRef.current ?? undefined) : undefined
            }
          />
        ) : null}
      </div>

      {inspectorMode === 'window' ? (
        <div className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-card/50 px-3 py-2">
          <SquareArrowOutUpRight className="h-3.5 w-3.5 text-muted-foreground" />
          <span className="min-w-0 flex-1 text-[12px] text-muted-foreground">
            {t('The stream inspector is open in a separate window.')}
          </span>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-7 text-[12px]"
            onClick={popout.open}
          >
            {t('Show window')}
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-7 text-[12px]"
            onClick={popout.close}
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

      {inspectorMode === 'inline' ? inspectorPanel(false) : null}
      {inspectorMode === 'window' && popout.container
        ? createPortal(inspectorPanel(true), popout.container)
        : null}
    </div>
  )
}
