import { useEffect, useMemo, useRef, useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { BarChart3, Info, Loader2, RefreshCw } from 'lucide-react'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
import {
  isVideoRenditionActive,
  parseVideoProgress,
  useCreateVideoTimeline,
  useVideoTimeline,
} from '@/lib/react-query/hooks/videos'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { formatBitrate, formatResolution } from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import { BufferVisualizer } from './BufferVisualizer'
import { StreamDebugPanel, type StreamManifest } from './StreamDebugPanel'
import { useStreamPlayer, type PlayerSource } from './useStreamPlayer'

const BUFFER_VIZ_STORAGE_KEY = 'console.videos.bufferVisualizer'

type PlaybackOutput = 'hls' | 'dash' | 'cmaf' | 'source'

const AUTO_LEVEL = '-1'
const SUBTITLES_OFF = '-1'

export interface VideoStreamPlayerProps {
  projectId: string
  video: Models.Video
  renditions: Models.VideoRendition[]
  subtitles: Models.VideoSubtitle[]
  canWrite: boolean
}

export function VideoStreamPlayer({
  projectId,
  video,
  renditions,
  subtitles,
  canWrite,
}: VideoStreamPlayerProps) {
  const t = useT()
  const videoRef = useRef<HTMLVideoElement | null>(null)

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
  const [bufferVisualizerEnabled, setBufferVisualizerEnabled] = useState(
    () => {
      if (typeof window === 'undefined') return false
      return window.localStorage.getItem(BUFFER_VIZ_STORAGE_KEY) === 'true'
    },
  )
  const playerSource: PlayerSource | null = useMemo(
    () =>
      activeManifestUrl
        ? { url: activeManifestUrl, type: 'stream' }
        : { url: sourceFileUrl, type: 'file' },
    [activeManifestUrl, sourceFileUrl],
  )

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

  const [timelineRequested, setTimelineRequested] = useState(false)
  const timelineQuery = useVideoTimeline(projectId, video.$id, {
    pollWhileMissing: timelineRequested,
  })
  const createTimeline = useCreateVideoTimeline(projectId, video.$id)
  useEffect(() => {
    if (timelineQuery.data) setTimelineRequested(false)
  }, [timelineQuery.data])

  const activeRenditions = renditions.filter((r) =>
    isVideoRenditionActive(r.status),
  )
  const failedRenditions = renditions.filter((r) => r.status === 'error')

  const hasAdaptiveReady =
    readyIds.hls.length + readyIds.cmaf.length + readyIds.dash.length > 0

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
    { id: 'source', label: t('Original file') },
  ]

  const currentLevelValue =
    state.stats && !state.stats.autoLevelEnabled
      ? String(state.stats.currentLevel)
      : AUTO_LEVEL

  return (
    <div className="space-y-4">
      {video.status === 'downloading' ||
      activeRenditions.length > 0 ||
      failedRenditions.length > 0 ? (
        <div className="rounded-xl border border-border bg-card/50 px-6 py-4">
          <div className="flex items-center gap-2">
            {video.status === 'downloading' || activeRenditions.length > 0 ? (
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            ) : (
              <Info className="h-4 w-4 text-muted-foreground" />
            )}
            <h3 className="text-[13px] font-semibold text-foreground">
              {video.status === 'downloading'
                ? t('Downloading source')
                : activeRenditions.length > 0
                  ? t('Processing renditions')
                  : t('Some renditions failed')}
            </h3>
          </div>
          <div className="mt-3 space-y-2">
            {video.status === 'downloading' ? (
              <ProgressBarRow
                value={
                  video.chunksTotal > 0
                    ? (video.chunksUploaded / video.chunksTotal) * 100
                    : 0
                }
                className="mb-0"
              />
            ) : null}
            {[...activeRenditions, ...failedRenditions].map((rendition) => (
              <div
                key={rendition.$id}
                className="grid grid-cols-[minmax(0,160px)_1fr] items-center gap-3"
              >
                <span className="truncate font-mono text-[12px] text-muted-foreground">
                  {rendition.output.toUpperCase()} {rendition.height}p
                </span>
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

      <div className="overflow-hidden rounded-xl border border-border bg-black">
        <div className="relative mx-auto aspect-video w-full max-h-[70dvh]">
          <video
            ref={videoRef}
            className="h-full w-full object-contain outline-none"
            controls
            playsInline
            poster={posterUrl}
            aria-label={video.name}
          />
          {state.fatalError ? (
            <div className="absolute inset-0 flex items-center justify-center bg-black/70 px-6 text-center">
              <p className="max-w-md text-[13px] text-white/80">
                {state.fatalError}
              </p>
            </div>
          ) : null}
        </div>
        {bufferVisualizerEnabled && state.stats ? (
          <BufferVisualizer
            stats={state.stats}
            fragments={state.fragments}
            levels={state.levels}
            onSeek={(seconds) => {
              if (videoRef.current) videoRef.current.currentTime = seconds
            }}
          />
        ) : null}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <TooltipProvider delayDuration={0}>
          <ToggleGroup
            type="single"
            variant="outline"
            size="sm"
            value={output}
            onValueChange={(value) => {
              if (value) setChosenOutput(value as PlaybackOutput)
            }}
          >
            {outputOptions.map((option) =>
              option.disabledReason ? (
                <Tooltip key={option.id}>
                  <TooltipTrigger asChild>
                    <span>
                      <ToggleGroupItem
                        value={option.id}
                        disabled
                        className="h-8 px-3 text-[12px]"
                      >
                        {option.label}
                      </ToggleGroupItem>
                    </span>
                  </TooltipTrigger>
                  <TooltipContent>{option.disabledReason}</TooltipContent>
                </Tooltip>
              ) : (
                <ToggleGroupItem
                  key={option.id}
                  value={option.id}
                  className="h-8 px-3 text-[12px]"
                >
                  {option.label}
                </ToggleGroupItem>
              ),
            )}
          </ToggleGroup>
        </TooltipProvider>

        {state.engine === 'shaka' && state.levels.length > 0 ? (
          <Select
            value={currentLevelValue}
            onValueChange={(value) => setLevel(Number(value))}
          >
            <SelectTrigger
              className="h-8 w-[200px] text-[12px]"
              aria-label={t('Quality')}
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={AUTO_LEVEL} className="text-[12px]">
                {t('Auto quality')}
              </SelectItem>
              {state.levels.map((level) => (
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

        {state.subtitleTracks.length > 0 ? (
          <Select
            value={String(state.currentSubtitleTrack)}
            onValueChange={(value) => setSubtitleTrack(Number(value))}
          >
            <SelectTrigger
              className="h-8 w-[180px] text-[12px]"
              aria-label={t('Subtitles')}
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={SUBTITLES_OFF} className="text-[12px]">
                {t('Subtitles off')}
              </SelectItem>
              {state.subtitleTracks.map((track) => (
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

        <div className="flex items-center gap-2 rounded-md border border-border bg-card/50 px-2.5 py-1.5">
          <BarChart3 className="h-3.5 w-3.5 text-muted-foreground" />
          <Label
            htmlFor="buffer-visualizer"
            className="cursor-pointer text-[12px] font-normal text-foreground"
          >
            {t('Buffer visualizer')}
          </Label>
          <Switch
            id="buffer-visualizer"
            checked={bufferVisualizerEnabled}
            onCheckedChange={(checked) => {
              setBufferVisualizerEnabled(checked)
              if (typeof window !== 'undefined') {
                window.localStorage.setItem(
                  BUFFER_VIZ_STORAGE_KEY,
                  checked ? 'true' : 'false',
                )
              }
            }}
          />
        </div>

        <div className="ms-auto flex items-center gap-2">
          {hasNewRenditions ? (
            <span className="text-[12px] text-muted-foreground">
              {t('New renditions are ready.')}
            </span>
          ) : null}
          <Button
            variant="outline"
            size="sm"
            className="h-8 gap-1.5 text-[12px]"
            onClick={reload}
          >
            <RefreshCw className="h-3.5 w-3.5" />
            {t('Reload stream')}
          </Button>
        </div>
      </div>

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

      <StreamDebugPanel
        video={video}
        renditions={renditions}
        subtitles={subtitles}
        player={state}
        activeManifestUrl={activeManifestUrl}
        manifests={manifests}
        timeline={timelineQuery.data}
        timelineLoading={timelineQuery.isLoading}
        timelinePending={timelineRequested || createTimeline.isPending}
        canWrite={canWrite}
        onGenerateTimeline={() =>
          createTimeline.mutate(undefined, {
            onSuccess: () => {
              setTimelineRequested(true)
              toast.success(t('Timeline generation started'))
            },
            onError: (error) =>
              toast.error(
                getErrorMessage(error) || t('Failed to generate timeline'),
              ),
          })
        }
        onSeek={(seconds) => {
          const el = videoRef.current
          if (!el) return
          el.currentTime = seconds
        }}
        onSelectLevel={setLevel}
        onClearEvents={clearEvents}
      />
    </div>
  )
}
