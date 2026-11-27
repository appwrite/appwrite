/**
 * The bandwidth an adaptive stream starts from, before it has measured any.
 *
 * Players pick their first rendition from this estimate. hls.js assumes
 * 500 kbps and tests on the lowest rendition, so every film opened on 360p
 * and stepped up a few seconds in. Starting from what this browser measured
 * last time, or what the connection reports, opens at the quality the viewer
 * will settle on; the player still steps down at once if the network is slower.
 */

/** Bits per second assumed when nothing better is known: a typical broadband line. */
export const DEFAULT_START_BANDWIDTH = 5_000_000

const MIN_BANDWIDTH = 200_000
const MAX_BANDWIDTH = 1_000_000_000

const STORAGE_KEY = 'videos:bandwidth'

function plausible(bitsPerSecond: number | null | undefined): number | null {
  if (typeof bitsPerSecond !== 'number' || !Number.isFinite(bitsPerSecond)) {
    return null
  }
  if (bitsPerSecond < MIN_BANDWIDTH || bitsPerSecond > MAX_BANDWIDTH)
    return null
  return bitsPerSecond
}

/**
 * The starting estimate in bits per second: the last measurement in this
 * browser, else the connection's reported downlink (Mbps), else the default.
 */
export function startBandwidth(
  saved: number | null | undefined,
  downlinkMbps: number | null | undefined,
): number {
  return (
    plausible(saved) ??
    plausible(
      typeof downlinkMbps === 'number' ? downlinkMbps * 1_000_000 : null,
    ) ??
    DEFAULT_START_BANDWIDTH
  )
}

export function readSavedBandwidth(): number | null {
  try {
    const value = window.localStorage.getItem(STORAGE_KEY)
    return value === null ? null : plausible(Number(value))
  } catch {
    return null
  }
}

export function saveBandwidth(bitsPerSecond: number): void {
  const value = plausible(bitsPerSecond)
  if (value === null) return
  try {
    window.localStorage.setItem(STORAGE_KEY, String(Math.round(value)))
  } catch {
    // Storage can be blocked; the next visit starts from the connection instead.
  }
}

/** The connection's reported downlink in Mbps, where the browser shares it. */
export function readDownlinkMbps(): number | null {
  const connection = (
    navigator as Navigator & { connection?: { downlink?: number } }
  ).connection
  return typeof connection?.downlink === 'number' ? connection.downlink : null
}
