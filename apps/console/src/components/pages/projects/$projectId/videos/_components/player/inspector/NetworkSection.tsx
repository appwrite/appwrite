import { useMemo, useState } from 'react'
import { Check, Copy, ExternalLink } from 'lucide-react'
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
import { formatBitrate } from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import {
  withConsoleVideoAccess,
  type StreamPlayerState,
  type StreamSegmentInfo,
} from '../useStreamPlayer'
import {
  CELL_CLASS,
  Empty,
  FilterChips,
  HEAD_CLASS,
  IconAction,
  Kpi,
  KpiGrid,
  formatMs,
  levelColor,
  levelName,
  percentile,
  useInspectorWindow,
  type Tone,
} from './shared'

type Filter = 'all' | 'video' | 'audio'

function segmentKey(frag: StreamSegmentInfo): string {
  return `${frag.type}-${frag.level}-${frag.sn}-${frag.at}`
}

function throughput(frag: StreamSegmentInfo): number | null {
  return frag.loadMs > 0 ? (frag.bytes * 8 * 1000) / frag.loadMs : null
}

export function NetworkSection({ player }: { player: StreamPlayerState }) {
  const t = useT()
  const view = useInspectorWindow()
  const [filter, setFilter] = useState<Filter>('all')
  const [selectedKey, setSelectedKey] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)

  const fragments = player.fragments
  const counts = useMemo(
    () => ({
      all: fragments.length,
      video: fragments.filter((f) => f.mediaKind !== 'audio').length,
      audio: fragments.filter((f) => f.mediaKind === 'audio').length,
    }),
    [fragments],
  )
  const visible = useMemo(
    () =>
      fragments.filter((f) =>
        filter === 'all'
          ? true
          : filter === 'audio'
            ? f.mediaKind === 'audio'
            : f.mediaKind !== 'audio',
      ),
    [fragments, filter],
  )

  const summary = useMemo(() => {
    const bytes = visible.reduce((sum, f) => sum + f.bytes, 0)
    const loadMs = visible.reduce((sum, f) => sum + f.loadMs, 0)
    return {
      bytes,
      throughput: loadMs > 0 ? (bytes * 8 * 1000) / loadMs : null,
      ttfb: percentile(
        visible.map((f) => f.firstByteMs).filter((v) => v > 0),
        0.5,
      ),
      p95: percentile(
        visible.map((f) => f.loadMs),
        0.95,
      ),
    }
  }, [visible])

  const range = useMemo(() => {
    if (visible.length === 0) return { start: 0, span: 1 }
    const starts = visible.map((f) => f.at - f.loadMs)
    const start = Math.min(...starts)
    const end = Math.max(...visible.map((f) => f.at))
    return { start, span: Math.max(1, end - start) }
  }, [visible])

  if (fragments.length === 0) {
    return <Empty>{t('No segments loaded yet.')}</Empty>
  }

  const loadStart = player.loadStartedAt ?? range.start
  const selected = visible.find((f) => segmentKey(f) === selectedKey) ?? null
  const ttfbTone: Tone =
    summary.ttfb == null
      ? 'neutral'
      : summary.ttfb < 200
        ? 'good'
        : summary.ttfb < 600
          ? 'fair'
          : 'poor'

  const copy = async (url: string) => {
    try {
      await view.navigator.clipboard.writeText(url)
      setCopied(true)
      view.setTimeout(() => setCopied(false), 1500)
    } catch {
      setCopied(false)
    }
  }

  return (
    <div className="space-y-4">
      <KpiGrid className="xl:grid-cols-5">
        <Kpi label={t('Requests')} value={String(visible.length)} />
        <Kpi label={t('Transferred')} value={formatBytes(summary.bytes)} />
        <Kpi
          label={t('Average throughput')}
          value={formatBitrate(summary.throughput)}
        />
        <Kpi
          label={t('Median time to first byte')}
          value={formatMs(summary.ttfb)}
          tone={ttfbTone}
        />
        <Kpi label={t('Slowest 5% load time')} value={formatMs(summary.p95)} />
      </KpiGrid>

      <div className="flex flex-wrap items-center gap-3">
        <FilterChips
          value={filter}
          onChange={setFilter}
          options={[
            { id: 'all', label: t('All'), count: counts.all },
            { id: 'video', label: t('Video'), count: counts.video },
            { id: 'audio', label: t('Audio'), count: counts.audio },
          ]}
        />
        <div className="ms-auto flex items-center gap-3 text-[11px] text-muted-foreground">
          <span className="inline-flex items-center gap-1.5">
            <span className="h-2 w-3 rounded-[2px] bg-foreground/25" />
            {t('Waiting for first byte')}
          </span>
          <span className="inline-flex items-center gap-1.5">
            <span className="h-2 w-3 rounded-[2px] bg-[var(--chart-1)]" />
            {t('Downloading')}
          </span>
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border border-border">
        <div className="max-h-[440px] overflow-auto">
          <Table>
            <TableHeader className="sticky top-0 z-10 bg-card">
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className={HEAD_CLASS}>{t('Time')}</TableHead>
                <TableHead className={HEAD_CLASS}>{t('Type')}</TableHead>
                <TableHead className={HEAD_CLASS}>{t('Rendition')}</TableHead>
                <TableHead className={HEAD_CLASS}>{t('Media time')}</TableHead>
                <TableHead className={cn(HEAD_CLASS, 'text-right')}>
                  {t('Size')}
                </TableHead>
                <TableHead className={cn(HEAD_CLASS, 'text-right')}>
                  {t('Time to first byte')}
                </TableHead>
                <TableHead className={cn(HEAD_CLASS, 'text-right')}>
                  {t('Load time')}
                </TableHead>
                <TableHead className={cn(HEAD_CLASS, 'text-right')}>
                  {t('Throughput')}
                </TableHead>
                <TableHead className={cn(HEAD_CLASS, 'min-w-[200px]')}>
                  {t('Waterfall')}
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {visible.map((frag) => {
                const key = segmentKey(frag)
                const requestStart = frag.at - frag.loadMs
                const left = ((requestStart - range.start) / range.span) * 100
                const width = Math.max(0.6, (frag.loadMs / range.span) * 100)
                const ttfbShare =
                  frag.loadMs > 0
                    ? Math.min(1, frag.firstByteMs / frag.loadMs)
                    : 0
                const isAudio = frag.mediaKind === 'audio'
                return (
                  <TableRow
                    key={key}
                    onClick={() =>
                      setSelectedKey(selectedKey === key ? null : key)
                    }
                    className={cn(
                      'cursor-pointer',
                      selectedKey === key && 'bg-muted/60',
                    )}
                  >
                    <TableCell
                      className={cn(CELL_CLASS, 'text-muted-foreground')}
                    >
                      +{formatMs(requestStart - loadStart)}
                    </TableCell>
                    <TableCell className={CELL_CLASS}>
                      {isAudio ? t('Audio') : t('Video')}
                    </TableCell>
                    <TableCell className={CELL_CLASS}>
                      {isAudio ? (
                        '-'
                      ) : (
                        <span className="inline-flex items-center gap-1.5">
                          <span
                            className="size-2 rounded-[2px]"
                            style={{ background: levelColor(frag.level) }}
                          />
                          {levelName(player.levels[frag.level])}
                        </span>
                      )}
                    </TableCell>
                    <TableCell className={CELL_CLASS}>
                      {frag.start.toFixed(1)}s +{frag.duration.toFixed(1)}s
                    </TableCell>
                    <TableCell className={cn(CELL_CLASS, 'text-right')}>
                      {formatBytes(frag.bytes)}
                    </TableCell>
                    <TableCell className={cn(CELL_CLASS, 'text-right')}>
                      {formatMs(frag.firstByteMs)}
                    </TableCell>
                    <TableCell className={cn(CELL_CLASS, 'text-right')}>
                      {formatMs(frag.loadMs)}
                    </TableCell>
                    <TableCell className={cn(CELL_CLASS, 'text-right')}>
                      {formatBitrate(throughput(frag))}
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <div
                        dir="ltr"
                        className="relative h-2.5 rounded-sm bg-muted/50"
                      >
                        <div
                          className="absolute inset-y-0 flex overflow-hidden rounded-sm"
                          style={{ left: `${left}%`, width: `${width}%` }}
                        >
                          <div
                            className="h-full bg-foreground/25"
                            style={{ width: `${ttfbShare * 100}%` }}
                          />
                          <div
                            className="h-full flex-1"
                            style={{
                              background: isAudio
                                ? 'var(--muted-foreground)'
                                : levelColor(frag.level),
                            }}
                          />
                        </div>
                      </div>
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </div>
        {selected ? (
          <div className="flex items-center gap-2 border-t border-border bg-muted/30 px-4 py-2.5">
            <span className="text-[12px] font-medium text-foreground">
              {t('Request URL')}
            </span>
            <span className="min-w-0 flex-1 truncate font-mono text-[11px] text-muted-foreground">
              {selected.url}
            </span>
            <IconAction
              label={t('Copy URL')}
              onClick={() => void copy(selected.url)}
            >
              {copied ? (
                <Check className="h-3.5 w-3.5" />
              ) : (
                <Copy className="h-3.5 w-3.5" />
              )}
            </IconAction>
            <IconAction
              label={t('Open in new tab')}
              onClick={() =>
                view.open(
                  withConsoleVideoAccess(selected.url),
                  '_blank',
                  'noopener',
                )
              }
            >
              <ExternalLink className="h-3.5 w-3.5" />
            </IconAction>
          </div>
        ) : null}
      </div>
    </div>
  )
}
