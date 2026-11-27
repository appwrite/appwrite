/**
 * Versions Cloud accepts as an upgrade target, oldest first. Mirrors
 * `Engine::getSupportedVersions()` in Cloud; the API has no endpoint for it.
 */
export const POSTGRES_ENGINE_VERSIONS = ['17', '18', '19'] as const
export const MYSQL_ENGINE_VERSIONS = ['8.0', '8.4'] as const

/** Compare dotted numeric versions (`18`, `8.4`, `18.1`). */
export function compareEngineVersions(a: string, b: string): number {
  const left = a.trim().split('.')
  const right = b.trim().split('.')
  const length = Math.max(left.length, right.length)
  for (let index = 0; index < length; index += 1) {
    const diff =
      (Number.parseInt(left[index] ?? '0', 10) || 0) -
      (Number.parseInt(right[index] ?? '0', 10) || 0)
    if (diff !== 0) return diff
  }
  return 0
}

/** Supported versions newer than `current`, oldest first. Empty when unknown. */
export function newerEngineVersions(
  current: string | null | undefined,
  supported: readonly string[],
): string[] {
  const version = current?.trim()
  if (!version) return []
  return supported.filter(
    (candidate) => compareEngineVersions(candidate, version) > 0,
  )
}
