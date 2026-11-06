import { useEffect, useMemo, useState, type ComponentType } from 'react'
import {
  Activity,
  Check,
  ClipboardCopy,
  Download,
  Eraser,
  FileCode2,
  Gauge,
  Layers,
  MonitorSmartphone,
  Network,
  Pause,
  Play,
  RefreshCw,
  RotateCcw,
  ScrollText,
  SquareArrowOutUpRight,
  X,
} from 'lucide-react'
import { MenuItemIcon } from '@/components/global/shared/ContextMenuIcon'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { formatBytes } from '@/lib/utils/mock-data'
import { formatBitrate } from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import { computeQoeMetrics, type QoeState } from './useQoeTracker'
import type { StreamPlayerState } from './useStreamPlayer'
import { EnvironmentSection } from './inspector/EnvironmentSection'
import { EventsSection } from './inspector/EventsSection'
import { ManifestsSection } from './inspector/ManifestsSection'
import { NetworkSection } from './inspector/NetworkSection'
import { OverviewSection } from './inspector/OverviewSection'
import { QualitySection } from './inspector/QualitySection'
import {
  buildSessionReport,
  buildSessionSummary,
  type SessionContext,
} from './inspector/session-report'
import {
  IconAction,
  InspectorPortalContext,
  TONE_DOT,
  TONE_TEXT,
  formatMs,
  levelName,
  scoreTone,
  type Tone,
} from './inspector/shared'

export type StreamManifest = {
  id: string
  label: string
  url: string
  available: boolean
}

export interface StreamDebugPanelProps {
  player: StreamPlayerState
  qoe: QoeState
  /** Physical pixel height of the video element, for upscaling checks. */
  renderedHeight: number | null
  context: SessionContext
  activeManifestUrl: string | null
  manifests: StreamManifest[]
  onSeek: (seconds: number) => void
  onSelectLevel: (level: number) => void
  onClearEvents: () => void
  /** Drops collected events, segments, and QoE history; playback continues. */
  onClearData: () => void
  /** Reloads the stream so startup and everything after it is measured again. */
  onRestartSession: () => void
  /** Rendered in a separate window: fill it and portal overlays into it. */
  detached?: boolean
  portalContainer?: HTMLElement
  onOpenWindow?: () => void
  onClose?: () => void
}

type SectionId =
  | 'overview'
  | 'quality'
  | 'network'
  | 'events'
  | 'manifests'
  | 'environment'

type SectionDef = {
  id: SectionId
  label: string
  description: string
  icon: ComponentType<{ className?: string }>
  badge?: { value: number; tone?: 'error' }
}

type PlaybackStatus = { label: string; tone: Tone; pulse: boolean }

function ResetMenu({
  container,
  onClearData,
  onRestartSession,
}: {
  container?: HTMLElement
  onClearData: () => void
  onRestartSession: () => void
}) {
  const t = useT()
  const label = t('Reset session data')
  return (
    <DropdownMenu modal={false}>
      <Tooltip>
        <TooltipTrigger asChild>
          <DropdownMenuTrigger asChild>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="h-7 w-7 shrink-0 text-muted-foreground hover:text-foreground"
              aria-label={label}
            >
              <RotateCcw className="h-3.5 w-3.5" />
            </Button>
          </DropdownMenuTrigger>
        </TooltipTrigger>
        <TooltipContent className="text-[12px]" container={container}>
          {label}
        </TooltipContent>
      </Tooltip>
      <DropdownMenuContent align="end" container={container} className="w-64">
        <DropdownMenuItem onSelect={onClearData} className="items-start">
          <MenuItemIcon icon={Eraser} />
          <span className="min-w-0 flex-1">
            <span className="block">{t('Clear collected data')}</span>
            <span className="block text-[11px] text-muted-foreground">
              {t('Empties charts, segments, and events. Playback continues.')}
            </span>
          </span>
        </DropdownMenuItem>
        <DropdownMenuItem onSelect={onRestartSession} className="items-start">
          <MenuItemIcon icon={RefreshCw} />
          <span className="min-w-0 flex-1">
            <span className="block">{t('Restart session')}</span>
            <span className="block text-[11px] text-muted-foreground">
              {t('Reloads the stream and measures everything from startup.')}
            </span>
          </span>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

function usePlaybackStatus(
  player: StreamPlayerState,
  qoe: QoeState,
): PlaybackStatus {
  const t = useT()
  if (player.fatalError) {
    return { label: t('Failed'), tone: 'poor', pulse: false }
  }
  if (!player.loadStartedAt) {
    return { label: t('Idle'), tone: 'neutral', pulse: false }
  }
  if (player.firstFrameAt == null) {
    return { label: t('Loading'), tone: 'fair', pulse: true }
  }
  if (qoe.stalled) {
    return { label: t('Buffering'), tone: 'fair', pulse: true }
  }
  if (player.stats?.paused) {
    return { label: t('Paused'), tone: 'neutral', pulse: false }
  }
  return { label: t('Playing'), tone: 'good', pulse: true }
}

export function StreamDebugPanel({
  player: livePlayer,
  qoe: liveQoe,
  renderedHeight,
  context,
  activeManifestUrl,
  manifests,
  onSeek,
  onSelectLevel,
  onClearEvents,
  onClearData,
  onRestartSession,
  detached = false,
  portalContainer,
  onOpenWindow,
  onClose,
}: StreamDebugPanelProps) {
  const t = useT()
  const view = portalContainer?.ownerDocument.defaultView ?? window
  const [section, setSection] = useState<SectionId>('overview')
  const [frozen, setFrozen] = useState<{
    player: StreamPlayerState
    qoe: QoeState
  } | null>(null)
  const [copied, setCopied] = useState(false)

  const player = frozen?.player ?? livePlayer
  const qoe = frozen?.qoe ?? liveQoe
  const metrics = useMemo(
    () => computeQoeMetrics(qoe, player, renderedHeight),
    [qoe, player, renderedHeight],
  )
  const status = usePlaybackStatus(player, qoe)

  const errorCount = player.events.filter((e) => e.kind === 'error').length
  const sections: SectionDef[] = [
    {
      id: 'overview',
      label: t('Overview'),
      description: t(
        'Experience score, diagnostics, and live charts for this playback session.',
      ),
      icon: Gauge,
    },
    {
      id: 'quality',
      label: t('Quality'),
      description: t(
        'The bitrate ladder, which rendition is playing, and every adaptive switch.',
      ),
      icon: Layers,
      badge: player.levels.length ? { value: player.levels.length } : undefined,
    },
    {
      id: 'network',
      label: t('Network'),
      description: t(
        'Every segment request with timing, throughput, and a waterfall.',
      ),
      icon: Network,
      badge: player.fragments.length
        ? { value: player.fragments.length }
        : undefined,
    },
    {
      id: 'events',
      label: t('Events'),
      description: t('The player event log, newest first.'),
      icon: ScrollText,
      badge: errorCount
        ? { value: errorCount, tone: 'error' }
        : player.events.length
          ? { value: player.events.length }
          : undefined,
    },
    {
      id: 'manifests',
      label: t('Manifests'),
      description: t(
        'Master and media playlists as the player fetched them, with syntax highlighting.',
      ),
      icon: FileCode2,
    },
    {
      id: 'environment',
      label: t('Device'),
      description: t(
        'What this browser and screen can decode and display, checked against this stream.',
      ),
      icon: MonitorSmartphone,
    },
  ]
  const active = sections.find((s) => s.id === section) ?? sections[0]

  useEffect(() => {
    if (!detached) return
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.metaKey || event.ctrlKey || event.altKey) return
      const target = event.target as HTMLElement | null
      if (
        target?.closest('input, textarea, select, [contenteditable="true"]')
      ) {
        return
      }
      const index = Number(event.key) - 1
      const next = SECTION_ORDER[index]
      if (next) {
        event.preventDefault()
        setSection(next)
      }
    }
    view.addEventListener('keydown', onKeyDown)
    return () => view.removeEventListener('keydown', onKeyDown)
  }, [detached, view])

  const toggleFrozen = () =>
    setFrozen((current) =>
      current ? null : { player: livePlayer, qoe: liveQoe },
    )

  const copySummary = async () => {
    try {
      await view.navigator.clipboard.writeText(
        buildSessionSummary(context, player, metrics),
      )
      setCopied(true)
      view.setTimeout(() => setCopied(false), 1500)
    } catch {
      setCopied(false)
    }
  }

  const exportSession = () => {
    const report = buildSessionReport(
      context,
      player,
      qoe,
      metrics,
      renderedHeight,
    )
    const blob = new Blob([JSON.stringify(report, null, 2)], {
      type: 'application/json',
    })
    const url = URL.createObjectURL(blob)
    const anchor = view.document.createElement('a')
    anchor.href = url
    anchor.download = `stream-session-${context.videoId}-${new Date()
      .toISOString()
      .replace(/[:.]/g, '-')}.json`
    view.document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()
    view.setTimeout(() => URL.revokeObjectURL(url), 1000)
  }

  const actions = (
    <div className="flex shrink-0 items-center gap-0.5">
      <IconAction
        label={frozen ? t('Resume live updates') : t('Pause live updates')}
        onClick={toggleFrozen}
      >
        {frozen ? (
          <Play className="h-3.5 w-3.5" />
        ) : (
          <Pause className="h-3.5 w-3.5" />
        )}
      </IconAction>
      <IconAction label={t('Copy summary')} onClick={() => void copySummary()}>
        {copied ? (
          <Check className="h-3.5 w-3.5" />
        ) : (
          <ClipboardCopy className="h-3.5 w-3.5" />
        )}
      </IconAction>
      <IconAction label={t('Export session')} onClick={exportSession}>
        <Download className="h-3.5 w-3.5" />
      </IconAction>
      <ResetMenu
        container={portalContainer}
        onClearData={() => {
          setFrozen(null)
          onClearData()
        }}
        onRestartSession={() => {
          setFrozen(null)
          onRestartSession()
        }}
      />
      {onOpenWindow ? (
        <IconAction label={t('Open in new window')} onClick={onOpenWindow}>
          <SquareArrowOutUpRight className="h-3.5 w-3.5" />
        </IconAction>
      ) : null}
      {onClose ? (
        <IconAction label={t('Close inspector')} onClick={onClose}>
          <X className="h-3.5 w-3.5" />
        </IconAction>
      ) : null}
    </div>
  )

  const content = (
    <SectionContent
      section={active.id}
      player={player}
      qoe={qoe}
      metrics={metrics}
      renderedHeight={renderedHeight}
      activeManifestUrl={activeManifestUrl}
      manifests={manifests}
      onSeek={onSeek}
      onSelectLevel={onSelectLevel}
      onClearEvents={onClearEvents}
    />
  )

  if (!detached) {
    return (
      <InspectorPortalContext.Provider value={portalContainer}>
        <div className="flex flex-col overflow-hidden rounded-xl border border-border bg-card/50">
          <div className="flex shrink-0 items-center gap-3 border-b border-border px-3 py-2">
            <StatusPill status={status} frozen={Boolean(frozen)} />
            <div className="min-w-0 flex-1 overflow-x-auto [scrollbar-width:none]">
              <div className="flex items-center gap-0.5">
                {sections.map((item) => (
                  <NavButton
                    key={item.id}
                    item={item}
                    active={item.id === active.id}
                    onSelect={() => setSection(item.id)}
                    compact
                  />
                ))}
              </div>
            </div>
            <ScoreChip
              score={metrics.scores?.overall ?? null}
              failed={Boolean(player.fatalError)}
            />
            {actions}
          </div>
          <div className="max-h-[min(75dvh,820px)] min-h-0 overflow-y-auto p-4">
            {content}
          </div>
        </div>
      </InspectorPortalContext.Provider>
    )
  }

  const stats = player.stats
  const level =
    stats && stats.currentLevel >= 0
      ? player.levels[stats.currentLevel]
      : undefined
  const transferred = player.fragments.reduce((sum, f) => sum + f.bytes, 0)

  return (
    <InspectorPortalContext.Provider value={portalContainer}>
      <div className="flex h-full min-h-0 flex-col bg-background text-foreground">
        <header className="flex h-12 shrink-0 items-center gap-3 border-b border-border px-4">
          <div className="flex min-w-0 items-center gap-2">
            <span className="flex size-6 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
              <Activity className="h-3.5 w-3.5" />
            </span>
            <span className="shrink-0 text-[13px] font-semibold">
              {t('Inspector')}
            </span>
            <span className="text-muted-foreground/50">/</span>
            <span className="min-w-0 truncate text-[13px] text-muted-foreground">
              {context.videoName}
            </span>
          </div>
          <StatusPill status={status} frozen={Boolean(frozen)} />
          <span className="hidden shrink-0 rounded-md border border-border px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground md:inline">
            {context.output.toUpperCase()}
            {player.engine
              ? ` · ${player.engine === 'native' ? t('Native') : player.engine} ${player.playerVersion ?? ''}`
              : ''}
          </span>
          <div className="ms-auto flex items-center gap-3">
            <ScoreChip
              score={metrics.scores?.overall ?? null}
              failed={Boolean(player.fatalError)}
            />
            {actions}
          </div>
        </header>

        <div className="flex min-h-0 flex-1">
          <nav className="flex w-56 shrink-0 flex-col border-e border-border bg-card/30">
            <div className="flex-1 space-y-0.5 overflow-y-auto p-2">
              {sections.map((item, index) => (
                <NavButton
                  key={item.id}
                  item={item}
                  active={item.id === active.id}
                  onSelect={() => setSection(item.id)}
                  shortcut={String(index + 1)}
                />
              ))}
            </div>
            <dl className="space-y-2.5 border-t border-border p-4">
              <LiveMetric
                label={t('Rendition')}
                value={
                  level
                    ? levelName(level)
                    : stats?.videoHeight
                      ? `${stats.videoHeight}p`
                      : '-'
                }
              />
              <LiveMetric
                label={t('Bandwidth')}
                value={formatBitrate(stats?.bandwidthEstimate)}
              />
              <LiveMetric
                label={t('Buffered ahead')}
                value={stats ? `${stats.bufferedAhead.toFixed(1)} s` : '-'}
              />
              <LiveMetric
                label={t('Watch time')}
                value={formatMs(qoe.watchMs)}
              />
            </dl>
          </nav>

          <main className="min-w-0 flex-1 overflow-y-auto">
            <div className="mx-auto w-full max-w-[1400px] space-y-5 px-6 py-5">
              <div>
                <h2 className="text-[17px] font-semibold text-foreground">
                  {active.label}
                </h2>
                <p className="mt-1 text-[13px] text-muted-foreground">
                  {active.description}
                </p>
              </div>
              {content}
            </div>
          </main>
        </div>

        <footer className="flex h-8 shrink-0 items-center gap-4 overflow-x-auto border-t border-border px-4 font-mono text-[11px] text-muted-foreground [scrollbar-width:none]">
          <span className="inline-flex shrink-0 items-center gap-1.5">
            <span
              className={cn('size-1.5 rounded-full', TONE_DOT[status.tone])}
            />
            {status.label}
          </span>
          <FooterItem
            label={t('Session')}
            value={
              player.loadStartedAt
                ? formatMs(Date.now() - player.loadStartedAt)
                : '-'
            }
          />
          <FooterItem
            label={t('Segments')}
            value={`${player.fragments.length} · ${formatBytes(transferred)}`}
          />
          <FooterItem label={t('Stalls')} value={String(metrics.stallCount)} />
          <FooterItem
            label={t('Dropped frames')}
            value={stats ? String(stats.droppedFrames) : '-'}
          />
          {errorCount > 0 ? (
            <FooterItem
              label={t('Errors')}
              value={String(errorCount)}
              className="text-red-600 dark:text-red-400"
            />
          ) : null}
          <span className="ms-auto hidden shrink-0 lg:inline">
            {t('Press 1 to 6 to switch sections')}
          </span>
        </footer>
      </div>
    </InspectorPortalContext.Provider>
  )
}

const SECTION_ORDER: SectionId[] = [
  'overview',
  'quality',
  'network',
  'events',
  'manifests',
  'environment',
]

function SectionContent({
  section,
  player,
  qoe,
  metrics,
  renderedHeight,
  activeManifestUrl,
  manifests,
  onSeek,
  onSelectLevel,
  onClearEvents,
}: {
  section: SectionId
  player: StreamPlayerState
  qoe: QoeState
  metrics: ReturnType<typeof computeQoeMetrics>
  renderedHeight: number | null
  activeManifestUrl: string | null
  manifests: StreamManifest[]
  onSeek: (seconds: number) => void
  onSelectLevel: (level: number) => void
  onClearEvents: () => void
}) {
  switch (section) {
    case 'quality':
      return (
        <QualitySection
          player={player}
          qoe={qoe}
          onSelectLevel={onSelectLevel}
        />
      )
    case 'network':
      return <NetworkSection player={player} />
    case 'events':
      return <EventsSection player={player} onClear={onClearEvents} />
    case 'manifests':
      return (
        <ManifestsSection
          manifests={manifests}
          activeManifestUrl={activeManifestUrl}
          player={player}
        />
      )
    case 'environment':
      return (
        <EnvironmentSection player={player} renderedHeight={renderedHeight} />
      )
    default:
      return (
        <OverviewSection
          player={player}
          qoe={qoe}
          metrics={metrics}
          renderedHeight={renderedHeight}
          onSeek={onSeek}
        />
      )
  }
}

function NavButton({
  item,
  active,
  onSelect,
  shortcut,
  compact = false,
}: {
  item: SectionDef
  active: boolean
  onSelect: () => void
  shortcut?: string
  compact?: boolean
}) {
  const Icon = item.icon
  return (
    <button
      type="button"
      onClick={onSelect}
      aria-current={active ? 'page' : undefined}
      className={cn(
        'group flex shrink-0 items-center gap-2 rounded-md text-[12px] font-medium transition-colors',
        compact ? 'h-7 px-2' : 'h-8 w-full px-2.5',
        active
          ? 'bg-muted text-foreground'
          : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground',
      )}
    >
      <Icon className="h-3.5 w-3.5 shrink-0" />
      <span className={cn('truncate', !compact && 'flex-1 text-start')}>
        {item.label}
      </span>
      {item.badge ? (
        <span
          className={cn(
            'rounded px-1 font-mono text-[10px] tabular-nums',
            item.badge.tone === 'error'
              ? 'bg-red-500/15 text-red-600 dark:text-red-400'
              : 'bg-muted-foreground/10 text-muted-foreground',
          )}
        >
          {item.badge.value}
        </span>
      ) : null}
      {shortcut ? (
        <kbd className="hidden rounded border border-border px-1 font-mono text-[10px] text-muted-foreground/70 group-hover:inline">
          {shortcut}
        </kbd>
      ) : null}
    </button>
  )
}

function StatusPill({
  status,
  frozen,
}: {
  status: PlaybackStatus
  frozen: boolean
}) {
  const t = useT()
  return (
    <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-border px-2 py-0.5 text-[11px] font-medium">
      <span className="relative flex size-1.5">
        {status.pulse && !frozen ? (
          <span
            className={cn(
              'absolute inline-flex h-full w-full animate-ping rounded-full opacity-60',
              TONE_DOT[status.tone],
            )}
          />
        ) : null}
        <span
          className={cn(
            'relative inline-flex size-1.5 rounded-full',
            TONE_DOT[status.tone],
          )}
        />
      </span>
      <span className={TONE_TEXT[status.tone]}>{status.label}</span>
      {frozen ? (
        <span className="text-muted-foreground">· {t('Snapshot')}</span>
      ) : null}
    </span>
  )
}

function ScoreChip({
  score,
  failed,
}: {
  score: number | null
  failed: boolean
}) {
  const t = useT()
  const value = failed ? 0 : score
  const tone = failed ? 'poor' : scoreTone(value)
  return (
    <span
      className="hidden shrink-0 items-center gap-1.5 text-[11px] text-muted-foreground sm:inline-flex"
      title={t('Experience score')}
    >
      <Gauge className="h-3.5 w-3.5" />
      <span
        className={cn(
          'font-mono text-[13px] font-semibold tabular-nums',
          TONE_TEXT[tone],
        )}
      >
        {value ?? '-'}
      </span>
    </span>
  )
}

function LiveMetric({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-2">
      <dt className="truncate text-[11px] text-muted-foreground">{label}</dt>
      <dd className="shrink-0 font-mono text-[11px] tabular-nums text-foreground">
        {value}
      </dd>
    </div>
  )
}

function FooterItem({
  label,
  value,
  className,
}: {
  label: string
  value: string
  className?: string
}) {
  return (
    <span
      className={cn('inline-flex shrink-0 items-center gap-1.5', className)}
    >
      <span className="text-muted-foreground/70">{label}</span>
      <span className="tabular-nums text-foreground">{value}</span>
    </span>
  )
}
