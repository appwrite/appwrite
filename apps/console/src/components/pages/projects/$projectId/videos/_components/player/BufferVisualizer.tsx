import {
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import { formatBytes } from '@/lib/utils/mock-data'
import { formatBitrate } from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import type {
  PlaybackStats,
  StreamSegmentInfo,
  StreamVariantInfo,
} from './useStreamPlayer'

const HISTORY_SAMPLES = 120
const HEALTHY_AHEAD_SEC = 10
const LOW_AHEAD_SEC = 3
const SPARK_W = 160
const SPARK_H = 36

type BufferVisualizerProps = {
  stats: PlaybackStats
  fragments: StreamSegmentInfo[]
  levels: StreamVariantInfo[]
  onSeek?: (seconds: number) => void
  compact?: boolean
  className?: string
}

function pct(time: number, duration: number): number {
  if (!Number.isFinite(duration) || duration <= 0) return 0
  return Math.min(100, Math.max(0, (time / duration) * 100))
}

function formatClock(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds < 0) return '0:00'
  const total = Math.floor(seconds)
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = String(total % 60).padStart(2, '0')
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`
}

/**
 * Segment colors encode quality, not identity: one hue, brighter for higher
 * bitrates, so the lane reads as "how good was each downloaded piece".
 */
function qualityColors(levels: StreamVariantInfo[]): Map<number, string> {
  const ranked = levels
    .map((level, index) => ({ index, bitrate: level.bitrate }))
    .sort((a, b) => a.bitrate - b.bitrate)
  const colors = new Map<number, string>()
  ranked.forEach(({ index }, rank) => {
    const strength =
      ranked.length > 1
        ? 30 + Math.round((70 * rank) / (ranked.length - 1))
        : 100
    colors.set(
      index,
      `color-mix(in oklab, var(--chart-1) ${strength}%, var(--muted))`,
    )
  })
  return colors
}

function levelLabel(level: StreamVariantInfo | undefined): string {
  if (!level) return '-'
  return level.height ? `${level.height}p` : formatBitrate(level.bitrate)
}

type Health = 'full' | 'healthy' | 'low' | 'critical'

const HEALTH_FILL: Record<Health, string> = {
  full: 'bg-emerald-500',
  healthy: 'bg-emerald-500',
  low: 'bg-amber-500',
  critical: 'bg-red-500',
}

function bufferHealth(stats: PlaybackStats): Health {
  const end = stats.currentTime + stats.bufferedAhead
  if (stats.duration - end < 0.5) return 'full'
  if (stats.bufferedAhead >= HEALTHY_AHEAD_SEC) return 'healthy'
  if (stats.bufferedAhead >= LOW_AHEAD_SEC) return 'low'
  return 'critical'
}

export function BufferVisualizer({
  stats,
  fragments,
  levels,
  onSeek,
  compact = false,
  className,
}: BufferVisualizerProps) {
  const t = useT()
  const duration = stats.duration
  const [history, setHistory] = useState<number[]>([])

  useEffect(() => {
    setHistory((prev) => [...prev, stats.bufferedAhead].slice(-HISTORY_SAMPLES))
  }, [stats])

  const { videoSegments, audioSegments, totalBytes, usedLevels } =
    useMemo(() => {
      const ordered = [...fragments].sort((a, b) => a.at - b.at)
      const used = new Set<number>()
      let bytes = 0
      for (const frag of ordered) {
        bytes += frag.bytes
        if (frag.mediaKind !== 'audio') used.add(frag.level)
      }
      return {
        videoSegments: ordered.filter((f) => f.mediaKind !== 'audio'),
        audioSegments: ordered.filter((f) => f.mediaKind === 'audio'),
        totalBytes: bytes,
        usedLevels: [...used].filter((l) => l >= 0).sort((a, b) => a - b),
      }
    }, [fragments])

  if (!Number.isFinite(duration) || duration <= 0) return null

  const health = bufferHealth(stats)
  const colors = qualityColors(levels)
  const seconds = stats.bufferedAhead.toFixed(1)
  const summary = {
    full: t('The rest of the video is downloaded, so playback cannot stall.'),
    healthy: t(
      '{seconds} s is downloaded ahead of the playhead, enough to ride out network hiccups.',
    ).replace('{seconds}', seconds),
    low: t(
      'Only {seconds} s is downloaded ahead. Playback may stall if the network slows down.',
    ).replace('{seconds}', seconds),
    critical: t(
      'Almost nothing is downloaded ahead of the playhead. Playback is likely to stall.',
    ),
  }[health]
  const currentLevel =
    stats.currentLevel >= 0 ? levels[stats.currentLevel] : undefined
  const quality = currentLevel
    ? `${levelLabel(currentLevel)} · ${formatBitrate(currentLevel.bitrate)}`
    : stats.videoHeight
      ? `${stats.videoHeight}p`
      : '-'
  const hasSegments = videoSegments.length > 0 || audioSegments.length > 0
  const hasAudioLane = audioSegments.length > 0

  return (
    <div
      className={cn(
        'bg-card',
        compact ? 'rounded-lg border border-border' : 'border-t border-border',
        className,
      )}
    >
      <div className="flex flex-wrap items-center gap-x-7 gap-y-3 px-4 pt-3.5 pb-3">
        <div className="min-w-0 basis-full space-y-1.5 lg:basis-auto lg:max-w-[340px]">
          <HealthBadge health={health} />
          <p className="text-[12px] leading-snug text-muted-foreground">
            {summary}
          </p>
        </div>
        <Stat label={t('Buffered ahead')} value={`${seconds} s`} />
        <Stat label={t('Quality')} value={quality} />
        <Stat
          label={t('Bandwidth')}
          value={formatBitrate(stats.bandwidthEstimate)}
        />
        {hasSegments ? (
          <Stat
            label={t('Segments')}
            value={`${fragments.length} · ${formatBytes(totalBytes)}`}
          />
        ) : null}
        <div className="ms-auto hidden sm:block">
          <Sparkline samples={history} label={t('Buffer ahead, last 60s')} />
        </div>
      </div>

      <div dir="ltr" className="space-y-2 px-4 pb-4">
        <Lane label={t('Buffer')}>
          <BufferTrack stats={stats} health={health} onSeek={onSeek} />
        </Lane>
        {hasSegments ? (
          <Lane label={hasAudioLane ? t('Video') : t('Segments')}>
            <SegmentLane
              segments={videoSegments}
              levels={levels}
              colors={colors}
              duration={duration}
              position={stats.currentTime}
            />
          </Lane>
        ) : null}
        {hasAudioLane ? (
          <Lane label={t('Audio')}>
            <SegmentLane
              segments={audioSegments}
              levels={levels}
              colors={colors}
              duration={duration}
              position={stats.currentTime}
              audio
            />
          </Lane>
        ) : null}
        <Axis duration={duration} />
        <Legend
          health={health}
          levels={levels}
          usedLevels={usedLevels}
          colors={colors}
        />
      </div>
    </div>
  )
}

function HealthBadge({ health }: { health: Health }) {
  const t = useT()
  const config = {
    full: {
      variant: 'success',
      label: t('Fully buffered'),
      dot: 'bg-emerald-500',
    },
    healthy: { variant: 'success', label: t('Healthy'), dot: 'bg-emerald-500' },
    low: { variant: 'warning', label: t('Low buffer'), dot: 'bg-amber-500' },
    critical: {
      variant: 'error',
      label: t('Buffer critical'),
      dot: 'bg-red-500',
    },
  } as const
  const { variant, label, dot } = config[health]
  return (
    <Badge variant={variant} className="gap-1.5 text-[11px]">
      <span className="relative flex size-1.5">
        {health !== 'full' ? (
          <span
            className={cn(
              'absolute inline-flex h-full w-full animate-ping rounded-full opacity-60',
              dot,
            )}
          />
        ) : null}
        <span
          className={cn('relative inline-flex size-1.5 rounded-full', dot)}
        />
      </span>
      {label}
    </Badge>
  )
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <div className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
        {label}
      </div>
      <div className="mt-0.5 truncate font-mono text-[13px] tabular-nums text-foreground">
        {value}
      </div>
    </div>
  )
}

function Lane({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-center gap-3">
      <span className="w-14 shrink-0 truncate text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
        {label}
      </span>
      <div className="min-w-0 flex-1">{children}</div>
    </div>
  )
}

function BufferTrack({
  stats,
  health,
  onSeek,
}: {
  stats: PlaybackStats
  health: Health
  onSeek?: (seconds: number) => void
}) {
  const trackRef = useRef<HTMLDivElement>(null)
  const [hoverTime, setHoverTime] = useState<number | null>(null)
  const { duration, currentTime, bufferedRanges } = stats
  const aheadRange = bufferedRanges.find(
    ([s, e]) => currentTime >= s && currentTime <= e,
  )

  const timeAt = (clientX: number) => {
    const rect = trackRef.current?.getBoundingClientRect()
    if (!rect || rect.width === 0) return 0
    const ratio = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width))
    return ratio * duration
  }

  return (
    <div
      ref={trackRef}
      className={cn('group relative h-6', onSeek && 'cursor-pointer')}
      onPointerMove={(e) => setHoverTime(timeAt(e.clientX))}
      onPointerLeave={() => setHoverTime(null)}
      onClick={(e) => onSeek?.(timeAt(e.clientX))}
    >
      <div className="absolute inset-x-0 top-1/2 h-2 -translate-y-1/2 overflow-hidden rounded-full bg-muted transition-[height] group-hover:h-2.5">
        {bufferedRanges.map(([start, end]) => (
          <div
            key={`${start}-${end}`}
            className="absolute inset-y-0 bg-emerald-500/30"
            style={{
              left: `${pct(start, duration)}%`,
              width: `${pct(end - start, duration)}%`,
            }}
          />
        ))}
        {aheadRange ? (
          <div
            className={cn('absolute inset-y-0', HEALTH_FILL[health])}
            style={{
              left: `${pct(currentTime, duration)}%`,
              width: `${pct(aheadRange[1] - currentTime, duration)}%`,
            }}
          />
        ) : null}
        <div
          className="absolute inset-y-0 left-0 bg-foreground/45"
          style={{ width: `${pct(currentTime, duration)}%` }}
        />
      </div>
      {hoverTime != null ? (
        <>
          <div
            className="pointer-events-none absolute inset-y-1 w-px bg-foreground/40"
            style={{ left: `${pct(hoverTime, duration)}%` }}
          />
          <div
            className="pointer-events-none absolute -top-6 z-20 -translate-x-1/2 rounded-md border border-border bg-popover px-1.5 py-0.5 font-mono text-[10px] tabular-nums text-popover-foreground shadow-sm"
            style={{ left: `${pct(hoverTime, duration)}%` }}
          >
            {formatClock(hoverTime)}
          </div>
        </>
      ) : null}
      <div
        className="pointer-events-none absolute top-1/2 z-10 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-background bg-foreground shadow-sm ring-4 ring-foreground/15 transition-transform group-hover:scale-110"
        style={{ left: `${pct(currentTime, duration)}%` }}
      />
    </div>
  )
}

function SegmentLane({
  segments,
  levels,
  colors,
  duration,
  position,
  audio = false,
}: {
  segments: StreamSegmentInfo[]
  levels: StreamVariantInfo[]
  colors: Map<number, string>
  duration: number
  position: number
  audio?: boolean
}) {
  const t = useT()
  const [hovered, setHovered] = useState<StreamSegmentInfo | null>(null)
  const hoveredLevel = hovered ? levels[hovered.level] : undefined
  const colorOf = (seg: StreamSegmentInfo) =>
    audio
      ? 'var(--muted-foreground)'
      : (colors.get(seg.level) ?? 'var(--muted-foreground)')

  return (
    <div className="relative h-4">
      <div className="absolute inset-0 rounded-[3px] bg-muted/50" />
      {segments.map((seg) => (
        <div
          key={`${seg.sn}-${seg.at}`}
          className={cn(
            'absolute inset-y-0 rounded-[2px] transition-opacity',
            hovered && hovered !== seg ? 'opacity-40' : 'opacity-90',
          )}
          style={{
            left: `${pct(seg.start, duration)}%`,
            width: `max(2px, calc(${pct(seg.duration, duration)}% - 1px))`,
            background: colorOf(seg),
          }}
          onPointerEnter={() => setHovered(seg)}
          onPointerLeave={() => setHovered(null)}
        />
      ))}
      <div
        className="pointer-events-none absolute -inset-y-0.5 z-10 w-px bg-foreground/70"
        style={{ left: `${pct(position, duration)}%` }}
      />
      {hovered ? (
        <div
          className="pointer-events-none absolute bottom-full z-30 mb-1.5 w-max -translate-x-1/2 rounded-md border border-border bg-popover px-2.5 py-1.5 text-[11px] text-popover-foreground shadow-md"
          style={{
            left: `clamp(80px, ${pct(hovered.start + hovered.duration / 2, duration)}%, calc(100% - 80px))`,
          }}
        >
          <div className="flex items-center gap-1.5 font-medium">
            <span
              className="size-2 rounded-[2px]"
              style={{ background: colorOf(hovered) }}
            />
            {audio
              ? t('Audio')
              : `${levelLabel(hoveredLevel)} · ${formatBitrate(hoveredLevel?.bitrate)}`}
          </div>
          <div className="mt-0.5 font-mono text-[10px] tabular-nums text-muted-foreground">
            {formatClock(hovered.start)} -{' '}
            {formatClock(hovered.start + hovered.duration)}
            {' · '}
            {formatBytes(hovered.bytes)}
            {' · '}
            {Math.round(hovered.loadMs)} ms
          </div>
        </div>
      ) : null}
    </div>
  )
}

function Axis({ duration }: { duration: number }) {
  const ticks = [0, 0.25, 0.5, 0.75, 1]
  return (
    <div className="ms-[68px] relative h-3.5">
      {ticks.map((tick) => (
        <span
          key={tick}
          className={cn(
            'absolute top-0 font-mono text-[10px] tabular-nums text-muted-foreground',
            tick === 0
              ? ''
              : tick === 1
                ? '-translate-x-full'
                : '-translate-x-1/2',
          )}
          style={{ left: `${tick * 100}%` }}
        >
          {formatClock(duration * tick)}
        </span>
      ))}
    </div>
  )
}

function Legend({
  health,
  levels,
  usedLevels,
  colors,
}: {
  health: Health
  levels: StreamVariantInfo[]
  usedLevels: number[]
  colors: Map<number, string>
}) {
  const t = useT()
  const byQuality = [...usedLevels].sort(
    (a, b) => (levels[b]?.bitrate ?? 0) - (levels[a]?.bitrate ?? 0),
  )
  return (
    <div className="ms-[68px] space-y-1.5 pt-1 text-[11px] text-muted-foreground">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5">
        <span className="font-medium text-foreground/80">{t('Buffer')}</span>
        <LegendSwatch
          className={HEALTH_FILL[health]}
          label={t('Ready to play')}
        />
        <LegendSwatch className="bg-emerald-500/30" label={t('Downloaded')} />
        <LegendSwatch className="bg-foreground/45" label={t('Played')} />
        <LegendSwatch className="bg-muted" label={t('Not downloaded')} />
      </div>
      {byQuality.length > 0 ? (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5">
          <span className="font-medium text-foreground/80">
            {t('Segment quality')}
          </span>
          {byQuality.map((level) => (
            <LegendSwatch
              key={level}
              color={colors.get(level)}
              label={`${levelLabel(levels[level])} · ${formatBitrate(levels[level]?.bitrate)}`}
            />
          ))}
          {byQuality.length > 1 ? (
            <span>{t('Brighter means higher quality.')}</span>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}

function LegendSwatch({
  label,
  className,
  color,
}: {
  label: string
  className?: string
  color?: string
}) {
  return (
    <span className="inline-flex items-center gap-1.5">
      <span
        className={cn('size-2 rounded-[2px]', className)}
        style={color ? { background: color } : undefined}
      />
      <span className="font-mono tabular-nums">{label}</span>
    </span>
  )
}

function Sparkline({ samples, label }: { samples: number[]; label: string }) {
  const gradientId = useId()
  const max = Math.max(HEALTHY_AHEAD_SEC * 1.5, ...samples)
  const offset = HISTORY_SAMPLES - samples.length
  const points = samples.map((value, i) => {
    const x = ((offset + i) / (HISTORY_SAMPLES - 1)) * SPARK_W
    const y = SPARK_H - (value / max) * (SPARK_H - 2) - 1
    return [x, y] as const
  })
  const line = points.map(([x, y], i) => `${i ? 'L' : 'M'}${x},${y}`).join(' ')
  const area =
    points.length > 1
      ? `${line} L${points[points.length - 1][0]},${SPARK_H} L${points[0][0]},${SPARK_H} Z`
      : ''
  const goalY = SPARK_H - (HEALTHY_AHEAD_SEC / max) * (SPARK_H - 2) - 1

  return (
    <div>
      <div className="mb-1 text-end text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
        {label}
      </div>
      <svg
        width={SPARK_W}
        height={SPARK_H}
        viewBox={`0 0 ${SPARK_W} ${SPARK_H}`}
        className="block overflow-visible"
      >
        <defs>
          <linearGradient id={gradientId} x1="0" x2="0" y1="0" y2="1">
            <stop
              offset="0%"
              stopColor="var(--color-emerald-500)"
              stopOpacity={0.35}
            />
            <stop
              offset="100%"
              stopColor="var(--color-emerald-500)"
              stopOpacity={0}
            />
          </linearGradient>
        </defs>
        <line
          x1={0}
          x2={SPARK_W}
          y1={goalY}
          y2={goalY}
          stroke="var(--border)"
          strokeDasharray="3 3"
        />
        {area ? <path d={area} fill={`url(#${gradientId})`} /> : null}
        {points.length > 1 ? (
          <path
            d={line}
            fill="none"
            stroke="var(--color-emerald-500)"
            strokeWidth={1.5}
            strokeLinejoin="round"
            strokeLinecap="round"
          />
        ) : null}
        {points.length > 0 ? (
          <circle
            cx={points[points.length - 1][0]}
            cy={points[points.length - 1][1]}
            r={2.5}
            fill="var(--color-emerald-500)"
          />
        ) : null}
      </svg>
    </div>
  )
}
