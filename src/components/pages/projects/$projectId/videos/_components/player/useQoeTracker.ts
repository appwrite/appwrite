import { useEffect, useRef, useState, type RefObject } from 'react'
import type { StreamPlayerState } from './useStreamPlayer'

const MAX_SAMPLES = 600

export type QoeSample = {
  /** Milliseconds since the source started loading. */
  t: number
  bandwidth: number | null
  bitrate: number | null
  level: number
  bufferAhead: number
  droppedFrames: number
  playing: boolean
}

export type QoeStall = { start: number; end: number | null }

export type QoeSwitch = {
  t: number
  from: number
  to: number
  direction: 'up' | 'down'
}

export type QoeState = {
  samples: QoeSample[]
  stalls: QoeStall[]
  switches: QoeSwitch[]
  seeks: number
  /** Milliseconds spent actually playing (not paused, not stalled). */
  watchMs: number
  /** Milliseconds played per level index. */
  levelMs: Record<number, number>
  /** Bitrate-weighted play time, for the average played bitrate. */
  bitrateMs: number
  stalled: boolean
}

const EMPTY: QoeState = {
  samples: [],
  stalls: [],
  switches: [],
  seeks: 0,
  watchMs: 0,
  levelMs: {},
  bitrateMs: 0,
  stalled: false,
}

export type QoeScores = {
  overall: number
  startup: number
  smoothness: number
  quality: number
  stability: number
}

export type QoeMetrics = {
  startupMs: number | null
  stallCount: number
  stallMs: number
  rebufferRatio: number
  averageBitrate: number | null
  maxBitrate: number | null
  upSwitches: number
  downSwitches: number
  droppedRatio: number
  /** Rendered pixels per decoded pixel; above 1 means the browser upscales. */
  upscale: number | null
  scores: QoeScores | null
}

function stallDuration(stall: QoeStall, now: number): number {
  return Math.max(0, (stall.end ?? now) - stall.start)
}

function linearScore(value: number, best: number, worst: number): number {
  if (value <= best) return 100
  if (value >= worst) return 0
  return Math.round(100 * (1 - (value - best) / (worst - best)))
}

export function computeQoeMetrics(
  qoe: QoeState,
  player: StreamPlayerState,
  renderedHeight: number | null,
): QoeMetrics {
  const now = player.loadStartedAt ? Date.now() - player.loadStartedAt : 0
  const startupMs =
    player.firstFrameAt && player.loadStartedAt
      ? player.firstFrameAt - player.loadStartedAt
      : null
  const stallMs = qoe.stalls.reduce((sum, s) => sum + stallDuration(s, now), 0)
  const rebufferRatio =
    qoe.watchMs + stallMs > 0 ? stallMs / (qoe.watchMs + stallMs) : 0
  const levelBitrates = player.levels.map((l) => l.bitrate).filter((b) => b > 0)
  const maxBitrate = levelBitrates.length ? Math.max(...levelBitrates) : null
  const averageBitrate =
    qoe.watchMs > 0 && qoe.bitrateMs > 0 ? qoe.bitrateMs / qoe.watchMs : null
  const stats = player.stats
  const droppedRatio =
    stats && stats.totalFrames > 0 ? stats.droppedFrames / stats.totalFrames : 0
  const upscale =
    stats && stats.videoHeight > 0 && renderedHeight
      ? renderedHeight / stats.videoHeight
      : null

  let scores: QoeScores | null = null
  if (startupMs != null) {
    const startup = linearScore(startupMs, 500, 8000)
    const smoothness = Math.min(
      linearScore(rebufferRatio, 0, 0.1),
      linearScore(qoe.stalls.length, 0, 10),
    )
    const bitrateScore =
      averageBitrate && maxBitrate ? (averageBitrate / maxBitrate) * 100 : 100
    const upscalePenalty =
      upscale && upscale > 1.1 ? linearScore(upscale, 1.1, 3) : 100
    const quality = Math.round(Math.min(bitrateScore, upscalePenalty))
    const stability = linearScore(droppedRatio, 0, 0.1)
    const overall = player.fatalError
      ? 0
      : Math.round(
          startup * 0.2 + smoothness * 0.35 + quality * 0.3 + stability * 0.15,
        )
    scores = { overall, startup, smoothness, quality, stability }
  }

  return {
    startupMs,
    stallCount: qoe.stalls.length,
    stallMs,
    rebufferRatio,
    averageBitrate,
    maxBitrate,
    upSwitches: qoe.switches.filter((s) => s.direction === 'up').length,
    downSwitches: qoe.switches.filter((s) => s.direction === 'down').length,
    droppedRatio,
    upscale,
    scores,
  }
}

/**
 * Accumulates quality-of-experience data from player stats ticks and media
 * element events. Resets whenever a new source starts loading.
 */
export function useQoeTracker(
  videoRef: RefObject<HTMLVideoElement | null>,
  player: StreamPlayerState,
): QoeState {
  const [qoe, setQoe] = useState<QoeState>(EMPTY)
  const lastTickRef = useRef<number | null>(null)
  const { loadStartedAt, firstFrameAt, stats, levels } = player

  useEffect(() => {
    setQoe(EMPTY)
    lastTickRef.current = null
  }, [loadStartedAt])

  useEffect(() => {
    const video = videoRef.current
    if (!video || !loadStartedAt) return
    const since = () => Date.now() - loadStartedAt
    let seeking = false

    const onWaiting = () => {
      if (seeking || !video.currentTime || video.paused) return
      setQoe((prev) =>
        prev.stalled
          ? prev
          : {
              ...prev,
              stalled: true,
              stalls: [...prev.stalls, { start: since(), end: null }],
            },
      )
    }
    const endStall = () => {
      setQoe((prev) => {
        if (!prev.stalled) return prev
        const stalls = prev.stalls.slice()
        const last = stalls[stalls.length - 1]
        if (last && last.end == null) {
          stalls[stalls.length - 1] = { ...last, end: since() }
        }
        return { ...prev, stalled: false, stalls }
      })
    }
    const onSeeking = () => {
      seeking = true
      setQoe((prev) => ({ ...prev, seeks: prev.seeks + 1 }))
    }
    const onSeeked = () => {
      seeking = false
    }

    video.addEventListener('waiting', onWaiting)
    video.addEventListener('playing', endStall)
    video.addEventListener('pause', endStall)
    video.addEventListener('seeking', onSeeking)
    video.addEventListener('seeked', onSeeked)
    return () => {
      video.removeEventListener('waiting', onWaiting)
      video.removeEventListener('playing', endStall)
      video.removeEventListener('pause', endStall)
      video.removeEventListener('seeking', onSeeking)
      video.removeEventListener('seeked', onSeeked)
    }
  }, [videoRef, loadStartedAt])

  useEffect(() => {
    if (!stats || !loadStartedAt) return
    const now = Date.now()
    const last = lastTickRef.current
    lastTickRef.current = now
    const elapsed = last ? Math.min(2000, now - last) : 0
    const level = levels[stats.currentLevel]
    const bitrate = level?.bitrate ?? null

    setQoe((prev) => {
      const playing =
        !stats.paused &&
        !prev.stalled &&
        firstFrameAt != null &&
        stats.readyState >= 3
      const sample: QoeSample = {
        t: now - loadStartedAt,
        bandwidth: stats.bandwidthEstimate,
        bitrate,
        level: stats.currentLevel,
        bufferAhead: stats.bufferedAhead,
        droppedFrames: stats.droppedFrames,
        playing,
      }
      const prevLevel = prev.samples[prev.samples.length - 1]?.level
      const switches =
        prevLevel != null &&
        prevLevel >= 0 &&
        stats.currentLevel >= 0 &&
        prevLevel !== stats.currentLevel
          ? [
              ...prev.switches,
              {
                t: sample.t,
                from: prevLevel,
                to: stats.currentLevel,
                direction:
                  (levels[stats.currentLevel]?.bitrate ?? 0) >=
                  (levels[prevLevel]?.bitrate ?? 0)
                    ? ('up' as const)
                    : ('down' as const),
              },
            ]
          : prev.switches
      const playedMs = playing ? elapsed : 0
      return {
        ...prev,
        samples: [...prev.samples, sample].slice(-MAX_SAMPLES),
        switches,
        watchMs: prev.watchMs + playedMs,
        bitrateMs: prev.bitrateMs + (bitrate ?? 0) * playedMs,
        levelMs:
          playedMs > 0 && stats.currentLevel >= 0
            ? {
                ...prev.levelMs,
                [stats.currentLevel]:
                  (prev.levelMs[stats.currentLevel] ?? 0) + playedMs,
              }
            : prev.levelMs,
      }
    })
  }, [stats, loadStartedAt, firstFrameAt, levels])

  return qoe
}
