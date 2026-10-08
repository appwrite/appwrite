import { ArrowDown, ArrowRight, ArrowUp, Lock, Unlock } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { formatBitrate, formatResolution } from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import type { QoeState } from '../useQoeTracker'
import type { StreamPlayerState } from '../useStreamPlayer'
import {
  Empty,
  SectionCard,
  formatMs,
  formatPercent,
  levelColor,
  levelName,
} from './shared'

function formatOffset(ms: number): string {
  const total = Math.max(0, Math.round(ms / 1000))
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`
}

export function QualitySection({
  player,
  qoe,
  onSelectLevel,
}: {
  player: StreamPlayerState
  qoe: QoeState
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

  const stats = player.stats
  const current = stats?.currentLevel ?? -1
  const loading = stats?.loadLevel ?? -1
  const auto = stats?.autoLevelEnabled ?? true
  const bandwidth = stats?.bandwidthEstimate ?? null
  const maxBitrate = Math.max(...player.levels.map((l) => l.bitrate), 1)
  const scaleMax = Math.max(maxBitrate, bandwidth ?? 0) * 1.05
  const ladder = [...player.levels].sort((a, b) => b.bitrate - a.bitrate)
  const totalLevelMs = Object.values(qoe.levelMs).reduce((a, b) => a + b, 0)
  const segmentsByLevel = new Map<number, number>()
  for (const frag of player.fragments) {
    if (frag.mediaKind === 'audio') continue
    segmentsByLevel.set(frag.level, (segmentsByLevel.get(frag.level) ?? 0) + 1)
  }

  return (
    <div className="space-y-4">
      <SectionCard
        title={t('Bitrate ladder')}
        description={t(
          'Every rendition in the manifest. The marker shows the current bandwidth estimate: renditions to its left fit the connection.',
        )}
        actions={
          <Button
            variant="outline"
            size="sm"
            className="h-7 gap-1.5 text-[12px]"
            disabled={auto}
            onClick={() => onSelectLevel(-1)}
          >
            <Unlock className="h-3.5 w-3.5" />
            {t('Auto quality')}
          </Button>
        }
        bodyClassName="p-0"
      >
        <div dir="ltr" className="divide-y divide-border">
          {ladder.map((level) => {
            const isCurrent = level.index === current
            const isLoading = level.index === loading && !isCurrent
            const locked = !auto && isCurrent
            const fits = bandwidth != null && level.bitrate <= bandwidth
            const share =
              totalLevelMs > 0
                ? (qoe.levelMs[level.index] ?? 0) / totalLevelMs
                : 0
            return (
              <div
                key={level.index}
                className={cn(
                  'grid grid-cols-[88px_minmax(0,1fr)_auto] items-center gap-4 px-4 py-3',
                  isCurrent && 'bg-muted/40',
                )}
              >
                <div className="min-w-0">
                  <div className="flex items-center gap-1.5">
                    <span
                      className="size-2 shrink-0 rounded-[2px]"
                      style={{ background: levelColor(level.index) }}
                    />
                    <span className="font-mono text-[13px] font-semibold text-foreground">
                      {levelName(level)}
                    </span>
                  </div>
                  <div className="mt-0.5 truncate font-mono text-[10px] text-muted-foreground">
                    {formatResolution(level.width, level.height)}
                    {level.frameRate ? ` · ${level.frameRate}fps` : ''}
                  </div>
                </div>

                <div className="min-w-0">
                  <div className="relative h-5">
                    <div className="absolute inset-y-1 inset-x-0 rounded-full bg-muted/60" />
                    <div
                      className={cn(
                        'absolute inset-y-1 left-0 rounded-full transition-[width]',
                        !fits && bandwidth != null && 'opacity-40',
                      )}
                      style={{
                        width: `${(level.bitrate / scaleMax) * 100}%`,
                        background: levelColor(level.index),
                      }}
                    />
                    {bandwidth != null ? (
                      <div
                        className="absolute -inset-y-0.5 w-0.5 rounded-full bg-foreground"
                        style={{
                          left: `${Math.min(100, (bandwidth / scaleMax) * 100)}%`,
                        }}
                      />
                    ) : null}
                  </div>
                  <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 font-mono text-[10px] text-muted-foreground">
                    <span className="text-foreground">
                      {formatBitrate(level.bitrate)}
                    </span>
                    {level.averageBitrate > 0 &&
                    level.averageBitrate !== level.bitrate ? (
                      <span>
                        {t('Average')}: {formatBitrate(level.averageBitrate)}
                      </span>
                    ) : null}
                    <span className="truncate">
                      {[level.videoCodec, level.audioCodec]
                        .filter(Boolean)
                        .join(', ')}
                    </span>
                    <span>
                      {t('Watch time')}: {formatPercent(share, 0)}
                    </span>
                    <span>
                      {t('Segments')}: {segmentsByLevel.get(level.index) ?? 0}
                    </span>
                  </div>
                </div>

                <div className="flex items-center gap-2">
                  {isCurrent ? (
                    <Badge variant="success" className="text-[10px] shrink-0">
                      {t('Playing')}
                    </Badge>
                  ) : isLoading ? (
                    <Badge variant="info" className="text-[10px] shrink-0">
                      {t('Loading')}
                    </Badge>
                  ) : null}
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-7 gap-1.5 text-[12px]"
                    disabled={locked}
                    onClick={() => onSelectLevel(level.index)}
                  >
                    <Lock className="h-3.5 w-3.5" />
                    {t('Lock')}
                  </Button>
                </div>
              </div>
            )
          })}
        </div>
      </SectionCard>

      <div className="grid gap-4 xl:grid-cols-2">
        <SectionCard
          title={t('Time per rendition')}
          description={t('Share of watch time spent on each rendition.')}
        >
          {totalLevelMs <= 0 ? (
            <Empty>{t('Play the video to measure time per rendition.')}</Empty>
          ) : (
            <div className="space-y-3">
              <div
                dir="ltr"
                className="flex h-3 overflow-hidden rounded-full bg-muted"
              >
                {ladder.map((level) => {
                  const ms = qoe.levelMs[level.index] ?? 0
                  return ms > 0 ? (
                    <div
                      key={level.index}
                      style={{
                        width: `${(ms / totalLevelMs) * 100}%`,
                        background: levelColor(level.index),
                      }}
                      title={`${levelName(level)} · ${formatMs(ms)}`}
                    />
                  ) : null
                })}
              </div>
              <div className="flex flex-wrap gap-x-4 gap-y-1.5 text-[11px]">
                {ladder
                  .filter((level) => (qoe.levelMs[level.index] ?? 0) > 0)
                  .map((level) => (
                    <span
                      key={level.index}
                      className="inline-flex items-center gap-1.5 text-muted-foreground"
                    >
                      <span
                        className="size-2 rounded-[2px]"
                        style={{ background: levelColor(level.index) }}
                      />
                      <span className="font-mono text-foreground">
                        {levelName(level)}
                      </span>
                      {formatMs(qoe.levelMs[level.index])}
                    </span>
                  ))}
              </div>
            </div>
          )}
        </SectionCard>

        <SectionCard
          title={t('Switch history')}
          description={t('Every rendition change, newest first.')}
          bodyClassName="p-0"
        >
          {qoe.switches.length === 0 ? (
            <div className="p-4">
              <Empty>{t('No rendition switches yet.')}</Empty>
            </div>
          ) : (
            <ul className="max-h-[260px] divide-y divide-border overflow-y-auto">
              {[...qoe.switches].reverse().map((sw) => (
                <li
                  key={`${sw.t}-${sw.from}-${sw.to}`}
                  className="flex items-center gap-3 px-4 py-2 font-mono text-[12px]"
                >
                  <span className="w-12 shrink-0 text-muted-foreground">
                    {formatOffset(sw.t)}
                  </span>
                  {sw.direction === 'up' ? (
                    <ArrowUp className="h-3.5 w-3.5 shrink-0 text-emerald-500" />
                  ) : (
                    <ArrowDown className="h-3.5 w-3.5 shrink-0 text-amber-500" />
                  )}
                  <span dir="ltr" className="inline-flex items-center gap-1.5">
                    <span style={{ color: levelColor(sw.from) }}>
                      {levelName(player.levels[sw.from])}
                    </span>
                    <ArrowRight className="h-3 w-3 text-muted-foreground" />
                    <span style={{ color: levelColor(sw.to) }}>
                      {levelName(player.levels[sw.to])}
                    </span>
                  </span>
                  <span className="ms-auto text-[11px] text-muted-foreground">
                    {formatBitrate(player.levels[sw.to]?.bitrate)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </SectionCard>
      </div>

      {Object.keys(player.levelDetails).length > 0 ? (
        <SectionCard title={t('Playlists')} bodyClassName="p-0">
          <div className="divide-y divide-border">
            {Object.values(player.levelDetails).map((details) => (
              <div
                key={details.level}
                className="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-2.5 font-mono text-[12px]"
              >
                <span className="w-16 font-semibold text-foreground">
                  {levelName(player.levels[details.level])}
                </span>
                <span className="text-muted-foreground">
                  {details.live ? t('Live') : (details.type ?? 'VOD')}
                </span>
                <span className="text-muted-foreground">
                  {t('Target duration')}: {details.targetDuration}s
                </span>
                <span className="text-muted-foreground">
                  {t('Segments')}: {details.fragments}
                </span>
                {details.version != null ? (
                  <span className="text-muted-foreground">
                    v{details.version}
                  </span>
                ) : null}
              </div>
            ))}
          </div>
        </SectionCard>
      ) : null}
    </div>
  )
}
