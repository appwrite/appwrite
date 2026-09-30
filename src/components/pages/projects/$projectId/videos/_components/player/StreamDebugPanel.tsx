import { useMemo, useState, type ReactNode } from 'react'
import type { Models } from '@appwrite.io/console'
import { Copy, ExternalLink, FileText, Loader2, RefreshCw } from 'lucide-react'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
import { cn } from '@/lib/utils'
import { formatBytes } from '@/lib/utils/mock-data'
import { copyToClipboard } from '@/lib/utils/context-menu'
import {
  formatBitrate,
  formatElapsed,
  formatPlaybackTime,
  formatResolution,
  formatVideoDuration,
} from '@/lib/utils/video-format'
import {
  isVideoRenditionActive,
  parseVideoProgress,
  type VideoTimelineCue,
} from '@/lib/react-query/hooks/videos'
import { useT } from '@/lib/i18n/translate'
import { VideoStatusBadge } from '../VideoStatusBadge'
import { withConsoleVideoAccess, type HlsPlayerState } from './useHlsPlayer'

const READY_STATES = [
  'HAVE_NOTHING',
  'HAVE_METADATA',
  'HAVE_CURRENT_DATA',
  'HAVE_FUTURE_DATA',
  'HAVE_ENOUGH_DATA',
]
const NETWORK_STATES = [
  'NETWORK_EMPTY',
  'NETWORK_IDLE',
  'NETWORK_LOADING',
  'NETWORK_NO_SOURCE',
]

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

export type StreamManifest = {
  id: string
  label: string
  url: string
  available: boolean
}

function DebugGrid({ children }: { children: ReactNode }) {
  return (
    <dl className="grid grid-cols-1 gap-x-6 gap-y-2.5 sm:grid-cols-2 lg:grid-cols-3">
      {children}
    </dl>
  )
}

function DebugItem({
  label,
  value,
  mono = true,
}: {
  label: string
  value: ReactNode
  mono?: boolean
}) {
  return (
    <div className="min-w-0">
      <dt className="text-[11px] uppercase tracking-wider text-muted-foreground">
        {label}
      </dt>
      <dd
        className={cn(
          'mt-0.5 truncate text-[13px] text-foreground',
          mono && 'font-mono text-[12px]',
        )}
      >
        {value === '' || value == null ? '-' : value}
      </dd>
    </div>
  )
}

function DebugSection({
  title,
  description,
  actions,
  children,
}: {
  title: string
  description?: string
  actions?: ReactNode
  children: ReactNode
}) {
  return (
    <section className="space-y-3">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h4 className="text-[13px] font-semibold text-foreground">{title}</h4>
          {description ? (
            <p className="mt-0.5 text-[12px] text-muted-foreground">
              {description}
            </p>
          ) : null}
        </div>
        {actions}
      </div>
      {children}
    </section>
  )
}

function EmptyDebug({ children }: { children: ReactNode }) {
  return (
    <p className="rounded-md border border-dashed border-border px-4 py-6 text-center text-[12px] text-muted-foreground">
      {children}
    </p>
  )
}

export interface StreamDebugPanelProps {
  video: Models.Video
  renditions: Models.VideoRendition[]
  subtitles: Models.VideoSubtitle[]
  player: HlsPlayerState
  activeManifestUrl: string | null
  manifests: StreamManifest[]
  timeline: { cues: VideoTimelineCue[]; url: string } | null | undefined
  timelineLoading: boolean
  timelinePending: boolean
  canWrite: boolean
  onGenerateTimeline: () => void
  onSeek: (seconds: number) => void
  onSelectLevel: (level: number) => void
  onClearEvents: () => void
}

export function StreamDebugPanel({
  video,
  renditions,
  subtitles,
  player,
  activeManifestUrl,
  manifests,
  timeline,
  timelineLoading,
  timelinePending,
  canWrite,
  onGenerateTimeline,
  onSeek,
  onSelectLevel,
  onClearEvents,
}: StreamDebugPanelProps) {
  const t = useT()

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <Tabs defaultValue="playback" className="gap-0">
        <div className="overflow-x-auto border-b border-border px-4 py-3 [scrollbar-width:none]">
          <TabsList>
            <TabsTrigger value="playback">{t('Playback')}</TabsTrigger>
            <TabsTrigger value="levels">
              {t('Levels')}
              {player.levels.length > 0 ? ` (${player.levels.length})` : ''}
            </TabsTrigger>
            <TabsTrigger value="segments">{t('Segments')}</TabsTrigger>
            <TabsTrigger value="events">
              {t('Events')}
              {player.events.some((e) => e.kind === 'error') ? (
                <span className="ms-1 inline-block h-1.5 w-1.5 rounded-full bg-red-500" />
              ) : null}
            </TabsTrigger>
            <TabsTrigger value="manifests">{t('Manifests')}</TabsTrigger>
            <TabsTrigger value="video">{t('Video')}</TabsTrigger>
            <TabsTrigger value="processing">{t('Processing')}</TabsTrigger>
            <TabsTrigger value="timeline">{t('Timeline')}</TabsTrigger>
          </TabsList>
        </div>
        <div className="px-6 py-5">
          <TabsContent value="playback">
            <PlaybackTab player={player} />
          </TabsContent>
          <TabsContent value="levels">
            <LevelsTab player={player} onSelectLevel={onSelectLevel} />
          </TabsContent>
          <TabsContent value="segments">
            <SegmentsTab player={player} />
          </TabsContent>
          <TabsContent value="events">
            <EventsTab player={player} onClear={onClearEvents} />
          </TabsContent>
          <TabsContent value="manifests">
            <ManifestsTab
              manifests={manifests}
              activeManifestUrl={activeManifestUrl}
              player={player}
            />
          </TabsContent>
          <TabsContent value="video">
            <VideoTab video={video} />
          </TabsContent>
          <TabsContent value="processing">
            <ProcessingTab
              video={video}
              renditions={renditions}
              subtitles={subtitles}
            />
          </TabsContent>
          <TabsContent value="timeline">
            <TimelineTab
              timeline={timeline}
              loading={timelineLoading}
              pending={timelinePending}
              canWrite={canWrite}
              sourceReady={video.status === 'ready'}
              onGenerate={onGenerateTimeline}
              onSeek={onSeek}
            />
          </TabsContent>
        </div>
      </Tabs>
    </div>
  )
}

function PlaybackTab({ player }: { player: HlsPlayerState }) {
  const t = useT()
  const { stats } = player
  const level =
    stats && stats.currentLevel >= 0 ? player.levels[stats.currentLevel] : null
  const timeToFirstFrame =
    player.firstFrameAt && player.loadStartedAt
      ? `${player.firstFrameAt - player.loadStartedAt} ms`
      : '-'
  const timeToManifest =
    player.manifestLoadedAt && player.loadStartedAt
      ? `${player.manifestLoadedAt - player.loadStartedAt} ms`
      : '-'
  const droppedRatio =
    stats && stats.totalFrames > 0
      ? ` (${((stats.droppedFrames / stats.totalFrames) * 100).toFixed(2)}%)`
      : ''

  if (!stats) {
    return (
      <EmptyDebug>
        {t('Start playback to collect stream statistics.')}
      </EmptyDebug>
    )
  }

  return (
    <div className="space-y-6">
      {player.fatalError ? (
        <div className="rounded-md border border-red-500/30 bg-red-500/5 px-3 py-2 font-mono text-[12px] text-red-600 dark:text-red-400">
          {player.fatalError}
        </div>
      ) : null}
      <DebugSection title={t('Player')}>
        <DebugGrid>
          <DebugItem
            label={t('Engine')}
            value={
              player.engine === 'hls.js'
                ? `hls.js ${player.hlsVersion ?? ''}`
                : player.engine === 'native'
                  ? t('Native video element')
                  : '-'
            }
          />
          <DebugItem label={t('Time to manifest')} value={timeToManifest} />
          <DebugItem
            label={t('Time to first frame')}
            value={timeToFirstFrame}
          />
          <DebugItem
            label={t('Position')}
            value={`${formatPlaybackTime(stats.currentTime)} / ${formatPlaybackTime(stats.duration)}`}
          />
          <DebugItem
            label={t('State')}
            value={`${stats.paused ? 'paused' : 'playing'} @ ${stats.playbackRate}x`}
          />
          <DebugItem
            label={t('Ready state')}
            value={READY_STATES[stats.readyState] ?? stats.readyState}
          />
          <DebugItem
            label={t('Network state')}
            value={NETWORK_STATES[stats.networkState] ?? stats.networkState}
          />
          <DebugItem
            label={t('Decoded resolution')}
            value={formatResolution(stats.videoWidth, stats.videoHeight)}
          />
          <DebugItem
            label={t('Dropped frames')}
            value={`${stats.droppedFrames} / ${stats.totalFrames}${droppedRatio}`}
          />
        </DebugGrid>
      </DebugSection>
      <DebugSection title={t('Buffer')}>
        <DebugGrid>
          <DebugItem
            label={t('Buffered ahead')}
            value={`${stats.bufferedAhead.toFixed(2)} s`}
          />
          <DebugItem
            label={t('Buffered ranges')}
            value={
              stats.bufferedRanges.length
                ? stats.bufferedRanges
                    .map(([s, e]) => `${s.toFixed(1)}-${e.toFixed(1)}`)
                    .join(', ')
                : '-'
            }
          />
          <DebugItem
            label={t('Bandwidth estimate')}
            value={formatBitrate(stats.bandwidthEstimate)}
          />
        </DebugGrid>
        {Number.isFinite(stats.duration) && stats.duration > 0 ? (
          <BufferBar
            duration={stats.duration}
            ranges={stats.bufferedRanges}
            position={stats.currentTime}
          />
        ) : null}
      </DebugSection>
      {player.engine === 'hls.js' ? (
        <DebugSection title={t('Adaptive bitrate')}>
          <DebugGrid>
            <DebugItem
              label={t('Current level')}
              value={
                level
                  ? `#${stats.currentLevel} ${formatResolution(level.width, level.height)} @ ${formatBitrate(level.bitrate)}`
                  : '-'
              }
            />
            <DebugItem label={t('Loading level')} value={stats.loadLevel} />
            <DebugItem label={t('Next level')} value={stats.nextLevel} />
            <DebugItem
              label={t('Level selection')}
              value={stats.autoLevelEnabled ? t('Automatic') : t('Manual')}
              mono={false}
            />
            <DebugItem
              label={t('Codecs')}
              value={
                level
                  ? [level.videoCodec, level.audioCodec]
                      .filter(Boolean)
                      .join(', ')
                  : '-'
              }
            />
            <DebugItem
              label={t('Latency')}
              value={
                stats.latency != null ? `${stats.latency.toFixed(2)} s` : '-'
              }
            />
          </DebugGrid>
        </DebugSection>
      ) : null}
    </div>
  )
}

function BufferBar({
  duration,
  ranges,
  position,
}: {
  duration: number
  ranges: Array<[number, number]>
  position: number
}) {
  return (
    <div className="relative h-2 w-full overflow-hidden rounded-full bg-muted">
      {ranges.map(([start, end]) => (
        <div
          key={`${start}-${end}`}
          className="absolute inset-y-0 bg-foreground/30"
          style={{
            insetInlineStart: `${(start / duration) * 100}%`,
            width: `${((end - start) / duration) * 100}%`,
          }}
        />
      ))}
      <div
        className="absolute inset-y-0 w-0.5 bg-foreground"
        style={{ insetInlineStart: `${(position / duration) * 100}%` }}
      />
    </div>
  )
}

function LevelsTab({
  player,
  onSelectLevel,
}: {
  player: HlsPlayerState
  onSelectLevel: (level: number) => void
}) {
  const t = useT()
  if (player.levels.length === 0) {
    return (
      <EmptyDebug>
        {player.engine === 'native'
          ? t('Quality levels are only available for HLS outputs.')
          : t('No levels parsed yet.')}
      </EmptyDebug>
    )
  }
  const current = player.stats?.currentLevel ?? -1
  const auto = player.stats?.autoLevelEnabled ?? true
  return (
    <div className="overflow-x-auto rounded-lg border border-border">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={HEAD_CLASS}>#</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Resolution')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Bitrate')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Codecs')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Segments')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Target duration')}</TableHead>
            <TableHead className={cn(HEAD_CLASS, 'text-right w-[100px]')} />
          </TableRow>
        </TableHeader>
        <TableBody>
          {player.levels.map((level) => {
            const details = player.levelDetails[level.index]
            const isCurrent = level.index === current
            return (
              <TableRow key={level.index}>
                <TableCell className="px-4 py-3 font-mono text-[12px]">
                  {level.index}
                  {isCurrent ? (
                    <Badge
                      variant="success"
                      className="ms-2 text-[10px] shrink-0"
                    >
                      {t('Playing')}
                    </Badge>
                  ) : null}
                </TableCell>
                <TableCell className="px-4 py-3 font-mono text-[12px]">
                  {formatResolution(level.width, level.height)}
                  {level.frameRate ? ` @ ${level.frameRate}fps` : ''}
                </TableCell>
                <TableCell className="px-4 py-3 font-mono text-[12px]">
                  {formatBitrate(level.bitrate)}
                </TableCell>
                <TableCell className="px-4 py-3 font-mono text-[12px]">
                  {[level.videoCodec, level.audioCodec]
                    .filter(Boolean)
                    .join(', ') || '-'}
                </TableCell>
                <TableCell className="px-4 py-3 font-mono text-[12px]">
                  {details
                    ? `${details.fragments} (${details.totalDuration.toFixed(1)} s)`
                    : '-'}
                </TableCell>
                <TableCell className="px-4 py-3 font-mono text-[12px]">
                  {details ? `${details.targetDuration} s` : '-'}
                </TableCell>
                <TableCell className="px-4 py-3 text-right">
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-7 text-[12px]"
                    disabled={!auto && isCurrent}
                    onClick={() => onSelectLevel(level.index)}
                  >
                    {t('Lock')}
                  </Button>
                </TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
    </div>
  )
}

function SegmentsTab({ player }: { player: HlsPlayerState }) {
  const t = useT()
  if (player.fragments.length === 0) {
    return <EmptyDebug>{t('No segments loaded yet.')}</EmptyDebug>
  }
  return (
    <div className="max-h-[420px] overflow-auto rounded-lg border border-border">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={HEAD_CLASS}>SN</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Level')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Type')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Start')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Duration')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Size')}</TableHead>
            <TableHead className={HEAD_CLASS}>TTFB</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Load time')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Throughput')}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {player.fragments.map((frag) => (
            <TableRow key={`${frag.type}-${frag.level}-${frag.sn}-${frag.at}`}>
              <TableCell
                className="px-4 py-3 font-mono text-[12px]"
                title={frag.url}
              >
                {frag.sn}
              </TableCell>
              <TableCell className="px-4 py-3 font-mono text-[12px]">
                {frag.level}
              </TableCell>
              <TableCell className="px-4 py-3 font-mono text-[12px]">
                {frag.type}
              </TableCell>
              <TableCell className="px-4 py-3 font-mono text-[12px]">
                {frag.start.toFixed(2)} s
              </TableCell>
              <TableCell className="px-4 py-3 font-mono text-[12px]">
                {frag.duration.toFixed(2)} s
              </TableCell>
              <TableCell className="px-4 py-3 font-mono text-[12px]">
                {formatBytes(frag.bytes)}
              </TableCell>
              <TableCell className="px-4 py-3 font-mono text-[12px]">
                {Math.round(frag.firstByteMs)} ms
              </TableCell>
              <TableCell className="px-4 py-3 font-mono text-[12px]">
                {Math.round(frag.loadMs)} ms
              </TableCell>
              <TableCell className="px-4 py-3 font-mono text-[12px]">
                {frag.loadMs > 0
                  ? formatBitrate((frag.bytes * 8 * 1000) / frag.loadMs)
                  : '-'}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

function EventsTab({
  player,
  onClear,
}: {
  player: HlsPlayerState
  onClear: () => void
}) {
  const t = useT()
  return (
    <DebugSection
      title={t('Event log')}
      description={t(
        'Player lifecycle events, level switches, and errors (newest first).',
      )}
      actions={
        <Button
          variant="outline"
          size="sm"
          className="h-8 text-[12px]"
          onClick={onClear}
          disabled={player.events.length === 0 && player.fragments.length === 0}
        >
          {t('Clear')}
        </Button>
      }
    >
      {player.events.length === 0 ? (
        <EmptyDebug>{t('No events recorded yet.')}</EmptyDebug>
      ) : (
        <div className="max-h-[420px] overflow-auto rounded-lg border border-border bg-muted/20 font-mono text-[12px]">
          {player.events.map((event, idx) => (
            <div
              key={`${event.at}-${idx}`}
              className="flex gap-3 border-b border-border/60 px-3 py-1.5 last:border-b-0"
            >
              <span className="shrink-0 text-muted-foreground">
                {new Date(event.at).toISOString().slice(11, 23)}
              </span>
              <span
                className={cn(
                  'shrink-0 font-semibold',
                  event.kind === 'error' && 'text-red-600 dark:text-red-400',
                  event.kind === 'warning' &&
                    'text-amber-600 dark:text-amber-400',
                )}
              >
                {event.name}
              </span>
              <span className="min-w-0 break-all text-muted-foreground">
                {event.detail}
              </span>
            </div>
          ))}
        </div>
      )}
    </DebugSection>
  )
}

function ManifestsTab({
  manifests,
  activeManifestUrl,
  player,
}: {
  manifests: StreamManifest[]
  activeManifestUrl: string | null
  player: HlsPlayerState
}) {
  const t = useT()
  const [viewing, setViewing] = useState<{ label: string; url: string } | null>(
    null,
  )
  const [content, setContent] = useState<string>('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const levelPlaylists = useMemo(
    () =>
      player.levels.map((level) => ({
        label: `${t('Level')} ${level.index} (${formatResolution(level.width, level.height)})`,
        url: level.url,
      })),
    [player.levels, t],
  )

  const load = async (entry: { label: string; url: string }) => {
    setViewing(entry)
    setLoading(true)
    setError(null)
    try {
      const response = await fetch(withConsoleVideoAccess(entry.url), {
        credentials: 'include',
      })
      const text = await response.text()
      if (!response.ok) {
        setError(`HTTP ${response.status}`)
      }
      setContent(text)
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
      setContent('')
    } finally {
      setLoading(false)
    }
  }

  const renderRow = (entry: {
    id?: string
    label: string
    url: string
    available?: boolean
  }) => (
    <div
      key={entry.url}
      className="flex flex-col gap-2 border-b border-border px-4 py-3 last:border-b-0 sm:flex-row sm:items-center"
    >
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <span className="text-[13px] font-medium text-foreground">
            {entry.label}
          </span>
          {entry.url === activeManifestUrl ? (
            <Badge variant="success" className="text-[10px] shrink-0">
              {t('Playing')}
            </Badge>
          ) : null}
          {entry.available === false ? (
            <Badge variant="info" className="text-[10px] shrink-0">
              {t('No ready renditions')}
            </Badge>
          ) : null}
        </div>
        <p className="mt-0.5 truncate font-mono text-[11px] text-muted-foreground">
          {entry.url}
        </p>
      </div>
      <div className="flex shrink-0 items-center gap-1.5">
        <Button
          variant="outline"
          size="sm"
          className="h-8 gap-1.5 text-[12px]"
          onClick={() => void load(entry)}
        >
          <FileText className="h-3.5 w-3.5" />
          {t('View')}
        </Button>
        <Button
          variant="outline"
          size="sm"
          className="h-8 w-8 p-0"
          aria-label={t('Copy URL')}
          onClick={() => void copyToClipboard('URL', entry.url)}
        >
          <Copy className="h-3.5 w-3.5" />
        </Button>
        <Button
          variant="outline"
          size="sm"
          className="h-8 w-8 p-0"
          aria-label={t('Open in new tab')}
          onClick={() =>
            window.open(withConsoleVideoAccess(entry.url), '_blank', 'noopener')
          }
        >
          <ExternalLink className="h-3.5 w-3.5" />
        </Button>
      </div>
    </div>
  )

  return (
    <div className="space-y-6">
      <DebugSection
        title={t('Master manifests')}
        description={t(
          'Public playback URLs. Clients need read access to the source file; the console adds admin mode.',
        )}
      >
        <div className="rounded-lg border border-border">
          {manifests.map(renderRow)}
        </div>
      </DebugSection>
      {levelPlaylists.length > 0 ? (
        <DebugSection title={t('Media playlists')}>
          <div className="rounded-lg border border-border">
            {levelPlaylists.map(renderRow)}
          </div>
        </DebugSection>
      ) : null}
      {viewing ? (
        <DebugSection
          title={viewing.label}
          actions={
            <Button
              variant="outline"
              size="sm"
              className="h-8 gap-1.5 text-[12px]"
              onClick={() => void load(viewing)}
              disabled={loading}
            >
              <RefreshCw
                className={cn('h-3.5 w-3.5', loading && 'animate-spin')}
              />
              {t('Reload')}
            </Button>
          }
        >
          {error ? (
            <p className="text-[12px] text-red-600 dark:text-red-400">
              {error}
            </p>
          ) : null}
          <pre className="max-h-[420px] overflow-auto rounded-lg border border-border bg-muted/30 p-3 font-mono text-[12px] leading-relaxed text-foreground">
            {loading ? t('Loading...') : content || '-'}
          </pre>
        </DebugSection>
      ) : null}
    </div>
  )
}

function VideoTab({ video }: { video: Models.Video }) {
  const t = useT()
  return (
    <div className="space-y-6">
      <DebugSection title={t('Container')}>
        <DebugGrid>
          <DebugItem label={t('Format')} value={video.format} />
          <DebugItem
            label={t('Duration')}
            value={formatVideoDuration(video.duration)}
          />
          <DebugItem
            label={t('Size')}
            value={video.size ? formatBytes(video.size) : '-'}
          />
          <DebugItem label={t('Source bucket')} value={video.bucketId} />
          <DebugItem label={t('Source file')} value={video.fileId} />
          <DebugItem label={t('Preview ID')} value={video.previewId} />
        </DebugGrid>
      </DebugSection>
      <DebugSection title={t('Video stream')}>
        <DebugGrid>
          <DebugItem
            label={t('Resolution')}
            value={formatResolution(video.width, video.height)}
          />
          <DebugItem label={t('Aspect ratio')} value={video.aspectRatio} />
          <DebugItem label={t('Codec')} value={video.videoCodec} />
          <DebugItem
            label={t('Format profile')}
            value={[video.videoFormat, video.videoFormatProfile]
              .filter(Boolean)
              .join(' / ')}
          />
          <DebugItem
            label={t('Bitrate')}
            value={formatBitrate(video.videoBitRate)}
          />
          <DebugItem
            label={t('Frame rate')}
            value={
              video.videoFrameRate
                ? `${video.videoFrameRate}${video.videoFrameRateMode ? ` (${video.videoFrameRateMode})` : ''}`
                : '-'
            }
          />
        </DebugGrid>
      </DebugSection>
      <DebugSection title={t('Audio stream')}>
        <DebugGrid>
          <DebugItem label={t('Codec')} value={video.audioCodec} />
          <DebugItem label={t('Format')} value={video.audioFormat} />
          <DebugItem
            label={t('Bitrate')}
            value={formatBitrate(video.audioBitRate)}
          />
          <DebugItem
            label={t('Sample rate')}
            value={video.audioSampleRate ? `${video.audioSampleRate} Hz` : '-'}
          />
        </DebugGrid>
      </DebugSection>
    </div>
  )
}

function ProcessingTab({
  video,
  renditions,
  subtitles,
}: {
  video: Models.Video
  renditions: Models.VideoRendition[]
  subtitles: Models.VideoSubtitle[]
}) {
  const t = useT()
  const downloadProgress =
    video.chunksTotal > 0 ? (video.chunksUploaded / video.chunksTotal) * 100 : 0
  return (
    <div className="space-y-6">
      <DebugSection
        title={t('Source')}
        description={t(
          'The worker downloads the Storage file into a working copy before encoding.',
        )}
      >
        <div className="flex flex-wrap items-center gap-3">
          <VideoStatusBadge status={video.status} />
          <span className="font-mono text-[12px] text-muted-foreground">
            {t('Chunks')} {video.chunksUploaded}/{video.chunksTotal}
          </span>
        </div>
        {video.status === 'downloading' ? (
          <ProgressBarRow value={downloadProgress} className="mb-0" />
        ) : null}
      </DebugSection>
      <DebugSection title={t('Renditions')}>
        {renditions.length === 0 ? (
          <EmptyDebug>{t('No renditions requested yet.')}</EmptyDebug>
        ) : (
          <div className="overflow-x-auto rounded-lg border border-border">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className={HEAD_CLASS}>{t('Name')}</TableHead>
                  <TableHead className={HEAD_CLASS}>{t('Output')}</TableHead>
                  <TableHead className={HEAD_CLASS}>{t('Status')}</TableHead>
                  <TableHead className={cn(HEAD_CLASS, 'min-w-[160px]')}>
                    {t('Progress')}
                  </TableHead>
                  <TableHead className={HEAD_CLASS}>{t('Started')}</TableHead>
                  <TableHead className={HEAD_CLASS}>{t('Elapsed')}</TableHead>
                  <TableHead className={HEAD_CLASS}>
                    {t('Target duration')}
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {renditions.map((rendition) => (
                  <TableRow key={rendition.$id}>
                    <TableCell className="px-4 py-3 font-mono text-[12px]">
                      {rendition.name}
                    </TableCell>
                    <TableCell className="px-4 py-3 font-mono text-[12px] uppercase">
                      {rendition.output}
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <VideoStatusBadge
                        status={rendition.status}
                        kind="rendition"
                      />
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <ProgressBarRow
                        value={
                          rendition.status === 'ready'
                            ? 100
                            : parseVideoProgress(rendition.progress)
                        }
                        className="mb-0"
                      />
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      {rendition.startedAt ? (
                        <DateTooltip
                          date={rendition.startedAt}
                          className="text-[12px]"
                        />
                      ) : (
                        '-'
                      )}
                    </TableCell>
                    <TableCell className="px-4 py-3 font-mono text-[12px]">
                      {rendition.startedAt
                        ? formatElapsed(
                            rendition.startedAt,
                            isVideoRenditionActive(rendition.status)
                              ? undefined
                              : rendition.endedAt || rendition.$updatedAt,
                          )
                        : '-'}
                    </TableCell>
                    <TableCell className="px-4 py-3 font-mono text-[12px]">
                      {rendition.targetDuration
                        ? `${rendition.targetDuration} s`
                        : '-'}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </DebugSection>
      <DebugSection title={t('Subtitles')}>
        {subtitles.length === 0 ? (
          <EmptyDebug>{t('No subtitles added yet.')}</EmptyDebug>
        ) : (
          <div className="flex flex-wrap gap-2">
            {subtitles.map((subtitle) => (
              <div
                key={subtitle.$id}
                className="flex items-center gap-2 rounded-md border border-border px-3 py-1.5"
              >
                <span className="text-[12px] font-medium">{subtitle.name}</span>
                <span className="font-mono text-[11px] text-muted-foreground">
                  {subtitle.code}
                </span>
                <VideoStatusBadge status={subtitle.status} kind="subtitle" />
              </div>
            ))}
          </div>
        )}
      </DebugSection>
    </div>
  )
}

function TimelineTab({
  timeline,
  loading,
  pending,
  canWrite,
  sourceReady,
  onGenerate,
  onSeek,
}: {
  timeline: { cues: VideoTimelineCue[]; url: string } | null | undefined
  loading: boolean
  pending: boolean
  canWrite: boolean
  sourceReady: boolean
  onGenerate: () => void
  onSeek: (seconds: number) => void
}) {
  const t = useT()
  if (loading) {
    return <EmptyDebug>{t('Loading...')}</EmptyDebug>
  }
  if (!timeline) {
    return (
      <div className="flex flex-col items-center gap-3 rounded-md border border-dashed border-border px-4 py-6 text-center">
        <p className="text-[12px] text-muted-foreground">
          {pending
            ? t(
                'Generating sprite timeline. This view refreshes automatically.',
              )
            : t(
                'No timeline yet. Generate sprite thumbnails for scrubbing previews and the video poster.',
              )}
        </p>
        <Button
          size="sm"
          className="h-8 gap-1.5 text-[12px]"
          disabled={!canWrite || !sourceReady || pending}
          onClick={onGenerate}
        >
          {pending ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : null}
          {t('Generate timeline')}
        </Button>
      </div>
    )
  }
  return (
    <DebugSection
      title={`${timeline.cues.length} ${t('thumbnails')}`}
      description={t('Select a thumbnail to seek the player.')}
      actions={
        <Button
          variant="outline"
          size="sm"
          className="h-8 w-8 p-0"
          aria-label={t('Copy URL')}
          onClick={() => void copyToClipboard('URL', timeline.url)}
        >
          <Copy className="h-3.5 w-3.5" />
        </Button>
      }
    >
      <div className="grid grid-cols-3 gap-2 sm:grid-cols-5 lg:grid-cols-8">
        {timeline.cues.map((cue) => (
          <button
            key={`${cue.start}-${cue.imageUrl}-${cue.x}-${cue.y}`}
            type="button"
            className="group overflow-hidden rounded-md border border-border text-start focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            onClick={() => onSeek(cue.start)}
          >
            <SpriteThumb cue={cue} />
            <span className="block px-1.5 py-1 font-mono text-[10px] text-muted-foreground group-hover:text-foreground">
              {formatPlaybackTime(cue.start)}
            </span>
          </button>
        ))}
      </div>
    </DebugSection>
  )
}

function SpriteThumb({ cue }: { cue: VideoTimelineCue }) {
  if (!cue.width || !cue.height) {
    return (
      <img
        src={cue.imageUrl}
        alt=""
        className="aspect-video w-full bg-black object-cover"
        loading="lazy"
      />
    )
  }
  return (
    <div
      className="w-full bg-black"
      style={{ aspectRatio: `${cue.width} / ${cue.height}` }}
    >
      <svg
        viewBox={`${cue.x} ${cue.y} ${cue.width} ${cue.height}`}
        className="h-full w-full"
        preserveAspectRatio="xMidYMid slice"
      >
        <image href={cue.imageUrl} />
      </svg>
    </div>
  )
}
