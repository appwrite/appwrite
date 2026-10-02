import { useMemo } from 'react'
import {
  AlertTriangle,
  ArrowDown,
  ArrowUp,
  CheckCircle2,
  Info,
  XCircle,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { formatBitrate, formatResolution } from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import { BufferVisualizer } from '../BufferVisualizer'
import type { QoeMetrics, QoeState } from '../useQoeTracker'
import type { StreamPlayerState } from '../useStreamPlayer'
import { ChartLegend, TimeSeriesChart } from './TimeSeriesChart'
import {
  Empty,
  Kpi,
  KpiGrid,
  SectionCard,
  TONE_DOT,
  TONE_TEXT,
  formatMs,
  formatPercent,
  levelName,
  scoreTone,
  type Tone,
} from './shared'

const HEALTHY_BUFFER_SEC = 10

type Finding = {
  tone: 'error' | 'warning' | 'info' | 'ok'
  title: string
  metric?: string
  description: string
}

export function OverviewSection({
  player,
  qoe,
  metrics,
  renderedHeight,
  onSeek,
}: {
  player: StreamPlayerState
  qoe: QoeState
  metrics: QoeMetrics
  renderedHeight: number | null
  onSeek: (seconds: number) => void
}) {
  const t = useT()
  const { stats } = player

  const findings = useFindings(player, metrics)

  if (!stats && !player.fatalError) {
    return <Empty>{t('Start playback to collect stream statistics.')}</Empty>
  }

  const scores = metrics.scores
  const startupTone: Tone =
    metrics.startupMs == null
      ? 'neutral'
      : metrics.startupMs <= 1000
        ? 'good'
        : metrics.startupMs <= 3000
          ? 'fair'
          : 'poor'
  const stallTone: Tone =
    metrics.stallCount === 0
      ? 'good'
      : metrics.rebufferRatio < 0.02
        ? 'fair'
        : 'poor'
  const droppedTone: Tone =
    metrics.droppedRatio < 0.01
      ? 'good'
      : metrics.droppedRatio < 0.05
        ? 'fair'
        : 'poor'
  const upscaleTone: Tone =
    metrics.upscale == null
      ? 'neutral'
      : metrics.upscale <= 1.1
        ? 'good'
        : metrics.upscale <= 1.6
          ? 'fair'
          : 'poor'
  const bitrateShare =
    metrics.averageBitrate && metrics.maxBitrate
      ? metrics.averageBitrate / metrics.maxBitrate
      : null

  return (
    <div className="space-y-4">
      <div className="grid gap-4 lg:grid-cols-[minmax(0,340px)_minmax(0,1fr)]">
        <SectionCard
          title={t('Experience score')}
          description={t('How this session feels to a viewer, from 0 to 100.')}
        >
          <ScorePanel scores={scores} failed={Boolean(player.fatalError)} />
        </SectionCard>
        <SectionCard
          title={t('Diagnostics')}
          description={t(
            'Issues detected in this session and how to fix them.',
          )}
          bodyClassName="p-0"
        >
          <ul className="divide-y divide-border">
            {findings.map((finding) => (
              <FindingRow key={finding.title} finding={finding} />
            ))}
          </ul>
        </SectionCard>
      </div>

      <KpiGrid>
        <Kpi
          label={t('Time to first frame')}
          value={formatMs(metrics.startupMs)}
          tone={startupTone}
          hint={
            player.manifestLoadedAt && player.loadStartedAt
              ? `${t('Manifest')}: ${formatMs(player.manifestLoadedAt - player.loadStartedAt)}`
              : undefined
          }
        />
        <Kpi
          label={t('Rebuffering')}
          value={formatPercent(metrics.rebufferRatio)}
          tone={stallTone}
          hint={`${t('Stalls')}: ${metrics.stallCount} · ${formatMs(metrics.stallMs)}`}
        />
        <Kpi
          label={t('Average bitrate')}
          value={formatBitrate(metrics.averageBitrate)}
          hint={
            bitrateShare != null
              ? `${t('Share of top rendition')}: ${formatPercent(bitrateShare, 0)}`
              : undefined
          }
        />
        <Kpi
          label={t('Quality switches')}
          value={
            <span className="inline-flex items-center gap-2">
              <span className="inline-flex items-center gap-0.5">
                <ArrowUp className="h-3.5 w-3.5 text-emerald-500" />
                {metrics.upSwitches}
              </span>
              <span className="inline-flex items-center gap-0.5">
                <ArrowDown className="h-3.5 w-3.5 text-amber-500" />
                {metrics.downSwitches}
              </span>
            </span>
          }
          hint={
            stats?.autoLevelEnabled === false
              ? t('Locked by you')
              : t('Automatic')
          }
        />
        <Kpi
          label={t('Dropped frames')}
          value={formatPercent(metrics.droppedRatio, 2)}
          tone={stats && stats.totalFrames > 0 ? droppedTone : 'neutral'}
          hint={
            stats ? `${stats.droppedFrames} / ${stats.totalFrames}` : undefined
          }
        />
        <Kpi
          label={t('Upscaling')}
          value={
            metrics.upscale != null ? `${metrics.upscale.toFixed(2)}×` : '-'
          }
          tone={upscaleTone}
          hint={
            stats && renderedHeight
              ? `${stats.videoHeight}p → ${Math.round(renderedHeight)}p`
              : undefined
          }
        />
        <Kpi
          label={t('Bandwidth estimate')}
          value={formatBitrate(stats?.bandwidthEstimate)}
        />
        <Kpi
          label={t('Watch time')}
          value={formatMs(qoe.watchMs)}
          hint={`${t('Seeks')}: ${qoe.seeks}`}
        />
      </KpiGrid>

      <div className="grid gap-4 xl:grid-cols-2">
        <BandwidthChart player={player} qoe={qoe} />
        <BufferChart qoe={qoe} />
      </div>

      {stats && Number.isFinite(stats.duration) && stats.duration > 0 ? (
        <SectionCard
          title={t('Media timeline')}
          description={t(
            'What is buffered and which rendition each segment came from. Select a point to seek.',
          )}
          bodyClassName="p-0"
        >
          <BufferVisualizer
            stats={stats}
            fragments={player.fragments}
            levels={player.levels}
            onSeek={onSeek}
            className="border-t-0"
          />
        </SectionCard>
      ) : null}

      <SessionDetails player={player} renderedHeight={renderedHeight} />
    </div>
  )
}

function ScorePanel({
  scores,
  failed,
}: {
  scores: QoeMetrics['scores']
  failed: boolean
}) {
  const t = useT()
  const overall = failed ? 0 : (scores?.overall ?? null)
  const tone = failed ? 'poor' : scoreTone(overall)
  const label =
    overall == null
      ? t('Waiting for playback')
      : failed
        ? t('Playback failed')
        : overall >= 90
          ? t('Excellent')
          : overall >= 75
            ? t('Good')
            : overall >= 60
              ? t('Fair')
              : t('Poor')
  const parts: Array<{ label: string; value: number | null; hint: string }> = [
    {
      label: t('Startup'),
      value: scores?.startup ?? null,
      hint: t('Time until the first frame'),
    },
    {
      label: t('Smoothness'),
      value: scores?.smoothness ?? null,
      hint: t('Stalls and time spent rebuffering'),
    },
    {
      label: t('Picture quality'),
      value: scores?.quality ?? null,
      hint: t('Bitrate played and upscaling'),
    },
    {
      label: t('Stability'),
      value: scores?.stability ?? null,
      hint: t('Frames dropped while decoding'),
    },
  ]

  return (
    <div className="flex items-center gap-5">
      <ScoreRing value={overall} tone={tone} />
      <div className="min-w-0 flex-1 space-y-2.5">
        <p className={cn('text-[14px] font-semibold', TONE_TEXT[tone])}>
          {label}
        </p>
        {parts.map((part) => {
          const partTone = scoreTone(part.value)
          return (
            <div key={part.label} title={part.hint}>
              <div className="flex items-center justify-between text-[11px]">
                <span className="text-muted-foreground">{part.label}</span>
                <span className="font-mono tabular-nums text-foreground">
                  {part.value ?? '-'}
                </span>
              </div>
              <div className="mt-1 h-1 overflow-hidden rounded-full bg-muted">
                <div
                  className={cn(
                    'h-full rounded-full transition-[width]',
                    TONE_DOT[partTone],
                  )}
                  style={{ width: `${part.value ?? 0}%` }}
                />
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}

function ScoreRing({ value, tone }: { value: number | null; tone: Tone }) {
  const size = 104
  const stroke = 8
  const r = (size - stroke) / 2
  const c = 2 * Math.PI * r
  const color =
    tone === 'good'
      ? 'rgb(16 185 129)'
      : tone === 'fair'
        ? 'rgb(245 158 11)'
        : tone === 'poor'
          ? 'rgb(239 68 68)'
          : 'var(--muted-foreground)'
  return (
    <div className="relative shrink-0" style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90">
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke="var(--muted)"
          strokeWidth={stroke}
        />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke={color}
          strokeWidth={stroke}
          strokeLinecap="round"
          strokeDasharray={c}
          strokeDashoffset={c * (1 - (value ?? 0) / 100)}
          className="transition-[stroke-dashoffset] duration-500"
        />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <span className="font-mono text-[28px] font-semibold leading-none tabular-nums text-foreground">
          {value ?? '-'}
        </span>
        <span className="mt-1 text-[10px] text-muted-foreground">/ 100</span>
      </div>
    </div>
  )
}

function useFindings(
  player: StreamPlayerState,
  metrics: QoeMetrics,
): Finding[] {
  const t = useT()
  return useMemo(() => {
    const list: Finding[] = []
    const stats = player.stats
    const bitrates = player.levels.map((l) => l.bitrate).filter((b) => b > 0)
    const lowest = bitrates.length ? Math.min(...bitrates) : null

    if (player.fatalError) {
      list.push({
        tone: 'error',
        title: t('Playback failed'),
        description: player.fatalError,
      })
    }
    if (metrics.startupMs != null && metrics.startupMs > 2500) {
      list.push({
        tone: metrics.startupMs > 5000 ? 'error' : 'warning',
        title: t('Slow startup'),
        metric: formatMs(metrics.startupMs),
        description: t(
          'Viewers wait too long for the first frame. Shorter segments or a lower starting rendition help.',
        ),
      })
    }
    if (metrics.stallCount > 0) {
      list.push({
        tone: metrics.rebufferRatio > 0.02 ? 'error' : 'warning',
        title: t('Playback stalled'),
        metric: `${metrics.stallCount} · ${formatMs(metrics.stallMs)}`,
        description: t(
          'The buffer ran empty. Compare bandwidth with the bitrate below to see if the ladder needs a lower rung.',
        ),
      })
    }
    if (
      lowest != null &&
      stats?.bandwidthEstimate != null &&
      stats.bandwidthEstimate > 0 &&
      stats.bandwidthEstimate < lowest * 1.2
    ) {
      list.push({
        tone: 'warning',
        title: t('Bandwidth below the lowest rendition'),
        metric: formatBitrate(stats.bandwidthEstimate),
        description: t(
          'Even the smallest rendition barely fits this connection. Add a lower bitrate rendition for slow networks.',
        ),
      })
    }
    if (metrics.upscale != null && metrics.upscale > 1.6) {
      list.push({
        tone: 'warning',
        title: t('Video is upscaled'),
        metric: `${metrics.upscale.toFixed(1)}×`,
        description: t(
          'The player is larger than the rendition it receives, so the picture looks soft. Add a higher resolution rendition.',
        ),
      })
    }
    if (metrics.droppedRatio > 0.05) {
      list.push({
        tone: 'warning',
        title: t('Frames are being dropped'),
        metric: formatPercent(metrics.droppedRatio),
        description: t(
          'This device struggles to decode the stream. A lower frame rate or a lighter codec helps.',
        ),
      })
    }
    if (metrics.downSwitches >= 3) {
      list.push({
        tone: 'warning',
        title: t('Quality keeps dropping'),
        metric: `${metrics.downSwitches} ↓`,
        description: t(
          'The player switched down several times. Closer bitrate steps between renditions make switches less visible.',
        ),
      })
    }
    if (player.engine !== 'native' && player.levels.length === 1) {
      list.push({
        tone: 'info',
        title: t('Single rendition'),
        description: t(
          'With one rendition the player cannot adapt to the network. Add more renditions for adaptive streaming.',
        ),
      })
    }
    if (player.engine === 'native' && player.levels.length === 0) {
      list.push({
        tone: 'info',
        title: t('Progressive playback'),
        description: t(
          'The original file plays as a single download, without adaptive bitrate.',
        ),
      })
    }
    if (list.length === 0) {
      list.push({
        tone: 'ok',
        title: t('No issues detected'),
        description: t(
          'Startup, smoothness, and picture quality all look healthy.',
        ),
      })
    }
    return list
  }, [player, metrics, t])
}

function FindingRow({ finding }: { finding: Finding }) {
  const Icon =
    finding.tone === 'error'
      ? XCircle
      : finding.tone === 'warning'
        ? AlertTriangle
        : finding.tone === 'info'
          ? Info
          : CheckCircle2
  return (
    <li className="flex gap-3 px-4 py-3">
      <Icon
        className={cn(
          'mt-0.5 h-4 w-4 shrink-0',
          finding.tone === 'error' && 'text-red-500',
          finding.tone === 'warning' && 'text-amber-500',
          finding.tone === 'info' && 'text-muted-foreground',
          finding.tone === 'ok' && 'text-emerald-500',
        )}
      />
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-[13px] font-medium text-foreground">
            {finding.title}
          </span>
          {finding.metric ? (
            <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] tabular-nums text-foreground">
              {finding.metric}
            </span>
          ) : null}
        </div>
        <p className="mt-0.5 break-words text-[12px] text-muted-foreground">
          {finding.description}
        </p>
      </div>
    </li>
  )
}

function BandwidthChart({
  player,
  qoe,
}: {
  player: StreamPlayerState
  qoe: QoeState
}) {
  const t = useT()
  const times = qoe.samples.map((s) => s.t)
  const bands = qoe.stalls.map((s) => ({
    start: s.start,
    end: s.end ?? times[times.length - 1] ?? s.start,
  }))
  const references = player.levels
    .filter((l) => l.bitrate > 0)
    .map((l) => ({ value: l.bitrate, label: levelName(l) }))
  const series = [
    {
      id: 'bandwidth',
      label: t('Bandwidth'),
      color: 'var(--chart-2)',
      values: qoe.samples.map((s) => s.bandwidth),
      area: true,
    },
    {
      id: 'bitrate',
      label: t('Playing bitrate'),
      color: 'var(--chart-1)',
      values: qoe.samples.map((s) => s.bitrate),
      step: true,
    },
  ]
  return (
    <SectionCard
      title={t('Bandwidth and bitrate')}
      description={t(
        'Network throughput against the rendition being played. Dashed lines are your renditions; red marks stalls.',
      )}
    >
      {times.length < 2 ? (
        <Empty>{t('Collecting samples...')}</Empty>
      ) : (
        <div className="space-y-2">
          <TimeSeriesChart
            times={times}
            series={series}
            references={references}
            bands={bands}
            yFormat={(v) => formatBitrate(v)}
            height={190}
          />
          <ChartLegend
            items={series.map((s) => ({ label: s.label, color: s.color }))}
          />
        </div>
      )}
    </SectionCard>
  )
}

function BufferChart({ qoe }: { qoe: QoeState }) {
  const t = useT()
  const times = qoe.samples.map((s) => s.t)
  const bands = qoe.stalls.map((s) => ({
    start: s.start,
    end: s.end ?? times[times.length - 1] ?? s.start,
  }))
  const series = [
    {
      id: 'buffer',
      label: t('Buffered ahead'),
      color: 'var(--primary)',
      values: qoe.samples.map((s) => s.bufferAhead),
      area: true,
    },
  ]
  return (
    <SectionCard
      title={t('Buffer health')}
      description={t(
        'Seconds of video ready ahead of the playhead. Below the target line, stalls become likely.',
      )}
    >
      {times.length < 2 ? (
        <Empty>{t('Collecting samples...')}</Empty>
      ) : (
        <div className="space-y-2">
          <TimeSeriesChart
            times={times}
            series={series}
            references={[{ value: HEALTHY_BUFFER_SEC, label: t('Target') }]}
            bands={bands}
            yFormat={(v) => `${v.toFixed(v < 10 ? 1 : 0)} s`}
            height={190}
          />
          <ChartLegend
            items={series.map((s) => ({ label: s.label, color: s.color }))}
          />
        </div>
      )}
    </SectionCard>
  )
}

function SessionDetails({
  player,
  renderedHeight,
}: {
  player: StreamPlayerState
  renderedHeight: number | null
}) {
  const t = useT()
  const { stats } = player
  const level =
    stats && stats.currentLevel >= 0
      ? player.levels[stats.currentLevel]
      : undefined
  const engine =
    player.engine === 'shaka'
      ? `Shaka Player ${player.playerVersion ?? ''}`
      : player.engine === 'hls.js'
        ? `hls.js ${player.playerVersion ?? ''}`
        : player.engine === 'native'
          ? t('Native video element')
          : '-'
  const rows: Array<[string, string]> = [
    [t('Engine'), engine],
    [
      t('Decoded resolution'),
      stats ? formatResolution(stats.videoWidth, stats.videoHeight) : '-',
    ],
    [
      t('Rendered height'),
      renderedHeight ? `${Math.round(renderedHeight)}px` : '-',
    ],
    [
      t('Codecs'),
      level
        ? [level.videoCodec, level.audioCodec].filter(Boolean).join(', ') || '-'
        : '-',
    ],
    [t('Frame rate'), level?.frameRate ? `${level.frameRate} fps` : '-'],
    [t('Playback rate'), stats ? `${stats.playbackRate}×` : '-'],
    [
      t('Live latency'),
      stats?.latency != null ? `${stats.latency.toFixed(2)} s` : '-',
    ],
    [t('Audio tracks'), String(player.audioTracks.length)],
    [t('Subtitle tracks'), String(player.subtitleTracks.length)],
  ]
  return (
    <SectionCard title={t('Session details')} bodyClassName="p-0">
      <dl className="grid divide-border sm:grid-cols-2 xl:grid-cols-3">
        {rows.map(([label, value]) => (
          <div
            key={label}
            className="flex items-center justify-between gap-3 border-b border-border px-4 py-2.5"
          >
            <dt className="text-[12px] text-muted-foreground">{label}</dt>
            <dd className="truncate font-mono text-[12px] text-foreground">
              {value}
            </dd>
          </div>
        ))}
      </dl>
    </SectionCard>
  )
}
