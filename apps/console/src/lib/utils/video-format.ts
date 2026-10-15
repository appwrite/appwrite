/** Formatting helpers for Videos probe metadata. */

/** Video durations are reported in milliseconds. */
export function formatVideoDuration(ms: number | undefined | null): string {
  if (!ms || ms <= 0 || !Number.isFinite(ms)) return '-'
  const totalSeconds = Math.round(ms / 1000)
  const hours = Math.floor(totalSeconds / 3600)
  const minutes = Math.floor((totalSeconds % 3600) / 60)
  const seconds = totalSeconds % 60
  const mm = String(minutes).padStart(hours > 0 ? 2 : 1, '0')
  const ss = String(seconds).padStart(2, '0')
  return hours > 0 ? `${hours}:${mm}:${ss}` : `${mm}:${ss}`
}

/** Format a playback position in seconds (player clock) as `m:ss.mmm`. */
export function formatPlaybackTime(seconds: number | undefined | null): string {
  if (seconds == null || !Number.isFinite(seconds) || seconds < 0) return '-'
  const minutes = Math.floor(seconds / 60)
  const rest = seconds - minutes * 60
  return `${minutes}:${rest.toFixed(3).padStart(6, '0')}`
}

/** Probe and profile bitrates are in bits per second (probe) or kbps (profiles). */
export function formatBitrate(
  value: number | undefined | null,
  unit: 'bps' | 'kbps' = 'bps',
): string {
  if (!value || value <= 0 || !Number.isFinite(value)) return '-'
  const bps = unit === 'kbps' ? value * 1000 : value
  if (bps >= 1_000_000) return `${(bps / 1_000_000).toFixed(2)} Mbps`
  if (bps >= 1000) return `${Math.round(bps / 1000)} kbps`
  return `${Math.round(bps)} bps`
}

/** Quality name and tier use the short edge, so portrait video gets the same labels. */
export function formatQuality(width: number, height: number): string {
  return `${Math.min(width, height)}p`
}

export function getQualityTier(width: number, height: number): string {
  const edge = Math.min(width, height)
  if (edge >= 2160) return '4K UHD'
  if (edge >= 1440) return 'QHD'
  if (edge >= 1080) return 'Full HD'
  if (edge >= 720) return 'HD'
  return 'SD'
}

export function formatResolution(
  width: number | undefined | null,
  height: number | undefined | null,
): string {
  if (!width || !height) return '-'
  return `${width}×${height}`
}

const DEFAULT_VIEWPORT_ASPECT = { width: 16, height: 9 } as const

function parseAspectRatioLabel(
  value: string | undefined | null,
): { width: number; height: number } | null {
  if (!value?.trim()) return null
  const match = value.trim().match(/^(\d+(?:\.\d+)?)\s*:\s*(\d+(?:\.\d+)?)$/)
  if (!match) return null
  const width = Number(match[1])
  const height = Number(match[2])
  if (
    !Number.isFinite(width) ||
    !Number.isFinite(height) ||
    width <= 0 ||
    height <= 0
  ) {
    return null
  }
  return { width, height }
}

/** Pixel dimensions for player layout (API metadata, then aspect ratio label). */
export function resolveVideoViewportAspect(
  width: number | undefined | null,
  height: number | undefined | null,
  aspectRatio?: string | null,
): { width: number; height: number } {
  if (width && height && width > 0 && height > 0) {
    return { width, height }
  }
  return parseAspectRatioLabel(aspectRatio) ?? DEFAULT_VIEWPORT_ASPECT
}

/**
 * Inline styles so the player frame matches video aspect ratio up to `maxHeight`.
 * Width shrinks for tall/narrow sources when height hits the cap.
 */
export function getVideoPlayerViewportStyle(
  aspect: { width: number; height: number },
  maxHeight: string,
): {
  aspectRatio: string
  maxHeight: string
  width: string
} {
  const { width, height } = aspect
  return {
    aspectRatio: `${width} / ${height}`,
    maxHeight,
    width: `min(100%, calc(${maxHeight} * ${width} / ${height}))`,
  }
}

/** Elapsed time between two ISO datetimes, e.g. `1m 12s`. */
export function formatElapsed(
  start: string | undefined | null,
  end?: string | undefined | null,
): string {
  if (!start) return '-'
  const startMs = Date.parse(start)
  const endMs = end ? Date.parse(end) : Date.now()
  if (!Number.isFinite(startMs) || !Number.isFinite(endMs)) return '-'
  const seconds = Math.max(0, Math.round((endMs - startMs) / 1000))
  if (seconds < 60) return `${seconds}s`
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes}m ${seconds % 60}s`
  return `${Math.floor(minutes / 60)}h ${minutes % 60}m`
}
