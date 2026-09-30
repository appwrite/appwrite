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

export function formatResolution(
  width: number | undefined | null,
  height: number | undefined | null,
): string {
  if (!width || !height) return '-'
  return `${width}×${height}`
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
