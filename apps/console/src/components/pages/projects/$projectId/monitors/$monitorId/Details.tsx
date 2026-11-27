/** A check older than this means the scheduler has stopped evaluating the monitor. */
export const STALE_AFTER_MS = 60 * 60 * 1000

/** Fields of `next` that differ from `previous`, so the update stays sparse. */
export function changedFields<Input extends object>(
  previous: Input,
  next: Input,
): Partial<Input> {
  const changes: Partial<Input> = {}
  for (const key of Object.keys(next) as (keyof Input)[]) {
    const before = previous[key]
    const after = next[key]
    const same =
      Array.isArray(before) && Array.isArray(after)
        ? [...before].sort().join() === [...after].sort().join()
        : typeof before === 'object' && typeof after === 'object'
          ? JSON.stringify(before) === JSON.stringify(after)
          : before === after
    if (!same) {
      Object.assign(changes, { [key]: after })
    }
  }
  return changes
}
