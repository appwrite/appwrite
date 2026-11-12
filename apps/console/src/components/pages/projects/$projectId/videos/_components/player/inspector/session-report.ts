import { formatBitrate } from '@/lib/utils/video-format'
import type { QoeMetrics, QoeState } from '../useQoeTracker'
import type { StreamPlayerState } from '../useStreamPlayer'
import { formatMs, formatPercent, levelDescription } from './shared'

export type SessionContext = {
  videoId: string
  videoName: string
  output: string
  manifestUrl: string | null
}

export function buildSessionReport(
  context: SessionContext,
  player: StreamPlayerState,
  qoe: QoeState,
  metrics: QoeMetrics,
  renderedHeight: number | null,
) {
  return {
    generatedAt: new Date().toISOString(),
    video: { id: context.videoId, name: context.videoName },
    stream: {
      output: context.output,
      manifestUrl: context.manifestUrl,
      engine: player.engine,
      engineVersion: player.playerVersion,
      levels: player.levels,
      levelDetails: player.levelDetails,
      audioTracks: player.audioTracks,
      subtitleTracks: player.subtitleTracks,
    },
    timing: {
      loadStartedAt: player.loadStartedAt,
      manifestLoadedAt: player.manifestLoadedAt,
      firstFrameAt: player.firstFrameAt,
    },
    experience: { ...metrics, watchMs: qoe.watchMs, seeks: qoe.seeks },
    stats: player.stats,
    fatalError: player.fatalError,
    stalls: qoe.stalls,
    switches: qoe.switches,
    samples: qoe.samples,
    segments: player.fragments,
    events: player.events,
    device: {
      userAgent: navigator.userAgent,
      screen: {
        width: window.screen.width,
        height: window.screen.height,
        pixelRatio: window.devicePixelRatio,
      },
      renderedHeight,
      connection:
        (
          navigator as Navigator & {
            connection?: {
              effectiveType?: string
              downlink?: number
              rtt?: number
            }
          }
        ).connection ?? null,
    },
  }
}

/** Plain text that reads well when pasted into an issue or chat. */
export function buildSessionSummary(
  context: SessionContext,
  player: StreamPlayerState,
  metrics: QoeMetrics,
): string {
  const stats = player.stats
  const level =
    stats && stats.currentLevel >= 0
      ? player.levels[stats.currentLevel]
      : undefined
  const lines = [
    `Stream session: ${context.videoName} (${context.videoId})`,
    `Format: ${context.output.toUpperCase()} · ${player.engine ?? '-'} ${player.playerVersion ?? ''}`.trim(),
    context.manifestUrl ? `Manifest: ${context.manifestUrl}` : null,
    `Experience score: ${metrics.scores?.overall ?? '-'} / 100`,
    `Time to first frame: ${formatMs(metrics.startupMs)}`,
    `Rebuffering: ${formatPercent(metrics.rebufferRatio)} (${metrics.stallCount} stalls, ${formatMs(metrics.stallMs)})`,
    `Average bitrate: ${formatBitrate(metrics.averageBitrate)}`,
    `Current rendition: ${levelDescription(level)}`,
    `Switches: ${metrics.upSwitches} up, ${metrics.downSwitches} down`,
    `Dropped frames: ${formatPercent(metrics.droppedRatio, 2)}`,
    `Bandwidth estimate: ${formatBitrate(stats?.bandwidthEstimate)}`,
    player.fatalError ? `Fatal error: ${player.fatalError}` : null,
    `Browser: ${navigator.userAgent}`,
  ]
  return lines.filter(Boolean).join('\n')
}
