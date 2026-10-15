/** Remaining time until `deadline` in ms, never negative. */
export function getRemainingMs(deadline: string, now: number): number {
  const end = Date.parse(deadline)
  if (Number.isNaN(end)) return 0
  return Math.max(0, end - now)
}

/** Compact duration such as `42 min`, `1h 5m` or `30 sec`. */
export function formatRemaining(ms: number): string {
  const totalSeconds = Math.ceil(ms / 1000)
  const hours = Math.floor(totalSeconds / 3600)
  const minutes = Math.floor((totalSeconds % 3600) / 60)
  const seconds = totalSeconds % 60
  if (hours > 0) return `${hours}h ${minutes}m`
  if (minutes > 0) return `${minutes} min`
  return `${seconds} sec`
}
