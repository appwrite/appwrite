import { useMemo, useState, type ReactNode } from 'react'
import {
  Check,
  Copy,
  ExternalLink,
  FileText,
  RefreshCw,
  SquareArrowOutUpRight,
  X,
} from 'lucide-react'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { cn } from '@/lib/utils'
import { formatBytes } from '@/lib/utils/mock-data'
import { formatBitrate, formatResolution } from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import { BufferVisualizer } from './BufferVisualizer'
import {
  withConsoleVideoAccess,
  type StreamPlayerState,
} from './useStreamPlayer'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'
const CELL_CLASS = 'px-4 py-3 font-mono text-[12px]'

export type StreamManifest = {
  id: string
  label: string
  url: string
  available: boolean
}

export interface StreamDebugPanelProps {
  player: StreamPlayerState
  activeManifestUrl: string | null
  manifests: StreamManifest[]
  onSeek: (seconds: number) => void
  onSelectLevel: (level: number) => void
  onClearEvents: () => void
  /** Rendered in a separate window: fill it and portal overlays into it. */
  detached?: boolean
  portalContainer?: HTMLElement
  onOpenWindow?: () => void
  onClose?: () => void
}

function IconAction({
  label,
  onClick,
  portalContainer,
  children,
}: {
  label: string
  onClick: () => void
  portalContainer?: HTMLElement
  children: ReactNode
}) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="h-7 w-7 shrink-0 text-muted-foreground hover:text-foreground"
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

function Empty({ children }: { children: ReactNode }) {
  return (
    <p className="rounded-md border border-dashed border-border px-4 py-6 text-center text-[12px] text-muted-foreground">
      {children}
    </p>
  )
}

export function StreamDebugPanel({
  player,
  activeManifestUrl,
  manifests,
  onSeek,
  onSelectLevel,
  onClearEvents,
  detached = false,
  portalContainer,
  onOpenWindow,
  onClose,
}: StreamDebugPanelProps) {
  const t = useT()
  const hasErrors = player.events.some((event) => event.kind === 'error')

  return (
    <div
      className={cn(
        'flex flex-col overflow-hidden bg-card/50',
        detached ? 'h-full bg-background' : 'rounded-xl border border-border',
      )}
    >
      <Tabs defaultValue="stats" className="min-h-0 flex-1 gap-0">
        <div className="flex shrink-0 items-center gap-2 border-b border-border px-3 py-2">
          <div className="min-w-0 flex-1 overflow-x-auto [scrollbar-width:none]">
            <TabsList>
              <TabsTrigger value="stats">{t('Stats')}</TabsTrigger>
              <TabsTrigger value="levels">
                {t('Levels')}
                {player.levels.length > 0 ? ` (${player.levels.length})` : ''}
              </TabsTrigger>
              <TabsTrigger value="network">{t('Network')}</TabsTrigger>
              <TabsTrigger value="events">
                {t('Events')}
                {hasErrors ? (
                  <span className="ms-1 inline-block h-1.5 w-1.5 rounded-full bg-red-500" />
                ) : null}
              </TabsTrigger>
              <TabsTrigger value="manifests">{t('Manifests')}</TabsTrigger>
            </TabsList>
          </div>
          {onOpenWindow ? (
            <IconAction
              label={t('Open in new window')}
              onClick={onOpenWindow}
              portalContainer={portalContainer}
            >
              <SquareArrowOutUpRight className="h-3.5 w-3.5" />
            </IconAction>
          ) : null}
          {onClose ? (
            <IconAction
              label={t('Close stream inspector')}
              onClick={onClose}
              portalContainer={portalContainer}
            >
              <X className="h-3.5 w-3.5" />
            </IconAction>
          ) : null}
        </div>
        <div
          className={cn(
            'min-h-0 flex-1 overflow-y-auto p-4',
            !detached && 'max-h-[min(60dvh,560px)]',
          )}
        >
          <TabsContent value="stats">
            <StatsTab player={player} onSeek={onSeek} />
          </TabsContent>
          <TabsContent value="levels">
            <LevelsTab player={player} onSelectLevel={onSelectLevel} />
          </TabsContent>
          <TabsContent value="network">
            <NetworkTab player={player} />
          </TabsContent>
          <TabsContent value="events">
            <EventsTab player={player} onClear={onClearEvents} />
          </TabsContent>
          <TabsContent value="manifests">
            <ManifestsTab
              manifests={manifests}
              activeManifestUrl={activeManifestUrl}
              player={player}
              portalContainer={portalContainer}
            />
          </TabsContent>
        </div>
      </Tabs>
    </div>
  )
}

function Metric({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="min-w-0 bg-card px-3 py-2.5">
      <dt className="text-[11px] text-muted-foreground">{label}</dt>
      <dd className="mt-0.5 truncate font-mono text-[12px] text-foreground">
        {value === '' || value == null ? '-' : value}
      </dd>
    </div>
  )
}

function StatsTab({
  player,
  onSeek,
}: {
  player: StreamPlayerState
  onSeek: (seconds: number) => void
}) {
  const t = useT()
  const { stats } = player

  if (!stats) {
    return <Empty>{t('Start playback to collect stream statistics.')}</Empty>
  }

  const level =
    stats.currentLevel >= 0 ? player.levels[stats.currentLevel] : undefined
  const engine =
    player.engine === 'shaka'
      ? `Shaka ${player.playerVersion ?? ''}`
      : player.engine === 'hls.js'
        ? `hls.js ${player.playerVersion ?? ''}`
        : player.engine === 'native'
          ? t('Native video element')
          : '-'
  const firstFrame =
    player.firstFrameAt && player.loadStartedAt
      ? `${player.firstFrameAt - player.loadStartedAt} ms`
      : '-'
  const dropped =
    stats.totalFrames > 0
      ? `${stats.droppedFrames} (${((stats.droppedFrames / stats.totalFrames) * 100).toFixed(1)}%)`
      : String(stats.droppedFrames)
  const currentLevel = level
    ? `${formatResolution(level.width, level.height)} · ${formatBitrate(level.bitrate)}`
    : '-'

  return (
    <div className="space-y-4">
      {player.fatalError ? (
        <div className="rounded-md border border-red-500/30 bg-red-500/5 px-3 py-2 font-mono text-[12px] text-red-600 dark:text-red-400">
          {player.fatalError}
        </div>
      ) : null}
      <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-4">
        <Metric label={t('Engine')} value={engine} />
        <Metric label={t('Time to first frame')} value={firstFrame} />
        <Metric
          label={t('Decoded resolution')}
          value={formatResolution(stats.videoWidth, stats.videoHeight)}
        />
        <Metric
          label={t('Current level')}
          value={
            level
              ? `${currentLevel} (${stats.autoLevelEnabled ? t('Automatic') : t('Manual')})`
              : '-'
          }
        />
        <Metric
          label={t('Bandwidth estimate')}
          value={formatBitrate(stats.bandwidthEstimate)}
        />
        <Metric
          label={t('Buffered ahead')}
          value={`${stats.bufferedAhead.toFixed(1)} s`}
        />
        <Metric label={t('Dropped frames')} value={dropped} />
        <Metric
          label={t('Codecs')}
          value={
            level
              ? [level.videoCodec, level.audioCodec].filter(Boolean).join(', ')
              : '-'
          }
        />
      </dl>
      {Number.isFinite(stats.duration) && stats.duration > 0 ? (
        <BufferVisualizer
          stats={stats}
          fragments={player.fragments}
          levels={player.levels}
          onSeek={onSeek}
          compact
        />
      ) : null}
    </div>
  )
}

function LevelsTab({
  player,
  onSelectLevel,
}: {
  player: StreamPlayerState
  onSelectLevel: (level: number) => void
}) {
  const t = useT()
  if (player.levels.length === 0) {
    return (
      <Empty>
        {player.engine === 'native'
          ? t('Quality levels are only available for adaptive streams.')
          : t('No levels parsed yet.')}
      </Empty>
    )
  }
  const current = player.stats?.currentLevel ?? -1
  const auto = player.stats?.autoLevelEnabled ?? true

  return (
    <div className="overflow-x-auto rounded-lg border border-border">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={HEAD_CLASS}>{t('Resolution')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Bitrate')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Codecs')}</TableHead>
            <TableHead className={cn(HEAD_CLASS, 'w-[100px] text-right')}>
              <Button
                variant="outline"
                size="sm"
                className="h-7 text-[12px] normal-case tracking-normal"
                disabled={auto}
                onClick={() => onSelectLevel(-1)}
              >
                {t('Auto quality')}
              </Button>
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {player.levels.map((level) => {
            const isCurrent = level.index === current
            const locked = !auto && isCurrent
            return (
              <TableRow key={level.index}>
                <TableCell className={CELL_CLASS}>
                  {formatResolution(level.width, level.height)}
                  {level.frameRate ? ` @ ${level.frameRate}fps` : ''}
                  {isCurrent ? (
                    <Badge
                      variant="success"
                      className="ms-2 text-[10px] shrink-0"
                    >
                      {t('Playing')}
                    </Badge>
                  ) : null}
                </TableCell>
                <TableCell className={CELL_CLASS}>
                  {formatBitrate(level.bitrate)}
                </TableCell>
                <TableCell className={CELL_CLASS}>
                  {[level.videoCodec, level.audioCodec]
                    .filter(Boolean)
                    .join(', ') || '-'}
                </TableCell>
                <TableCell className="px-4 py-3 text-right">
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-7 text-[12px]"
                    disabled={locked}
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

function NetworkTab({ player }: { player: StreamPlayerState }) {
  const t = useT()
  if (player.fragments.length === 0) {
    return <Empty>{t('No segments loaded yet.')}</Empty>
  }
  return (
    <div className="overflow-x-auto rounded-lg border border-border">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={HEAD_CLASS}>{t('Type')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Level')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Start')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Size')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Load time')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Throughput')}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {player.fragments.map((frag) => (
            <TableRow key={`${frag.type}-${frag.level}-${frag.sn}-${frag.at}`}>
              <TableCell className={CELL_CLASS} title={frag.url}>
                {frag.type}
              </TableCell>
              <TableCell className={CELL_CLASS}>{frag.level}</TableCell>
              <TableCell className={CELL_CLASS}>
                {frag.start.toFixed(1)} s
              </TableCell>
              <TableCell className={CELL_CLASS}>
                {formatBytes(frag.bytes)}
              </TableCell>
              <TableCell className={CELL_CLASS}>
                {Math.round(frag.loadMs)} ms
              </TableCell>
              <TableCell className={CELL_CLASS}>
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
  player: StreamPlayerState
  onClear: () => void
}) {
  const t = useT()
  if (player.events.length === 0) {
    return <Empty>{t('No events recorded yet.')}</Empty>
  }
  return (
    <div className="space-y-2">
      <div className="flex justify-end">
        <Button
          variant="outline"
          size="sm"
          className="h-7 text-[12px]"
          onClick={onClear}
        >
          {t('Clear')}
        </Button>
      </div>
      <div className="rounded-lg border border-border bg-muted/20 font-mono text-[12px]">
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
    </div>
  )
}

type ManifestEntry = { label: string; url: string; available?: boolean }

function ManifestsTab({
  manifests,
  activeManifestUrl,
  player,
  portalContainer,
}: {
  manifests: StreamManifest[]
  activeManifestUrl: string | null
  player: StreamPlayerState
  portalContainer?: HTMLElement
}) {
  const t = useT()
  const [viewing, setViewing] = useState<ManifestEntry | null>(null)
  const [content, setContent] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [copiedUrl, setCopiedUrl] = useState<string | null>(null)
  const view = portalContainer?.ownerDocument.defaultView ?? window

  const entries: ManifestEntry[] = useMemo(
    () => [
      ...manifests,
      ...player.levels
        .filter((level) => level.url)
        .map((level) => ({
          label: `${t('Level')} ${formatResolution(level.width, level.height)}`,
          url: level.url,
        })),
    ],
    [manifests, player.levels, t],
  )

  const load = async (entry: ManifestEntry) => {
    setViewing(entry)
    setLoading(true)
    setError(null)
    try {
      const response = await fetch(withConsoleVideoAccess(entry.url), {
        credentials: 'include',
      })
      if (!response.ok) setError(`HTTP ${response.status}`)
      setContent(await response.text())
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
      setContent('')
    } finally {
      setLoading(false)
    }
  }

  const copy = async (url: string) => {
    try {
      await view.navigator.clipboard.writeText(url)
      setCopiedUrl(url)
      view.setTimeout(() => setCopiedUrl(null), 1500)
    } catch {
      setError(t('Failed to copy'))
    }
  }

  return (
    <div className="space-y-4">
      <div className="rounded-lg border border-border">
        {entries.map((entry) => (
          <div
            key={entry.url}
            className={cn(
              'flex items-center gap-2 border-b border-border px-3 py-2 last:border-b-0',
              entry.available === false && 'opacity-50',
            )}
          >
            <span className="w-28 shrink-0 truncate text-[12px] font-medium text-foreground">
              {entry.label}
            </span>
            <span className="min-w-0 flex-1 truncate font-mono text-[11px] text-muted-foreground">
              {entry.url}
            </span>
            {entry.url === activeManifestUrl ? (
              <Badge variant="success" className="text-[10px] shrink-0">
                {t('Playing')}
              </Badge>
            ) : null}
            <Button
              variant="outline"
              size="sm"
              className="h-7 gap-1.5 text-[12px]"
              onClick={() => void load(entry)}
            >
              <FileText className="h-3.5 w-3.5" />
              {t('View')}
            </Button>
            <IconAction
              label={t('Copy URL')}
              onClick={() => void copy(entry.url)}
              portalContainer={portalContainer}
            >
              {copiedUrl === entry.url ? (
                <Check className="h-3.5 w-3.5" />
              ) : (
                <Copy className="h-3.5 w-3.5" />
              )}
            </IconAction>
            <IconAction
              label={t('Open in new tab')}
              onClick={() =>
                view.open(
                  withConsoleVideoAccess(entry.url),
                  '_blank',
                  'noopener',
                )
              }
              portalContainer={portalContainer}
            >
              <ExternalLink className="h-3.5 w-3.5" />
            </IconAction>
          </div>
        ))}
      </div>
      {viewing ? (
        <div className="space-y-2">
          <div className="flex items-center justify-between gap-2">
            <span className="text-[12px] font-semibold text-foreground">
              {viewing.label}
            </span>
            <IconAction
              label={t('Reload')}
              onClick={() => void load(viewing)}
              portalContainer={portalContainer}
            >
              <RefreshCw
                className={cn('h-3.5 w-3.5', loading && 'animate-spin')}
              />
            </IconAction>
          </div>
          {error ? (
            <p className="text-[12px] text-red-600 dark:text-red-400">
              {error}
            </p>
          ) : null}
          <pre className="overflow-auto rounded-lg border border-border bg-muted/30 p-3 font-mono text-[12px] leading-relaxed text-foreground">
            {loading ? t('Loading...') : content || '-'}
          </pre>
        </div>
      ) : null}
    </div>
  )
}
