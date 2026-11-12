import { NUMBERED_REFERENCE_VERSIONS } from './generated/versions'

export { NUMBERED_REFERENCE_VERSIONS }

function parseVersionParts(version: string): number[] {
  return version.split('.').map((part) => {
    if (part === 'x') return 0
    const parsed = Number.parseInt(part, 10)
    return Number.isFinite(parsed) ? parsed : 0
  })
}

export function compareReferenceVersionsDesc(a: string, b: string): number {
  const aParts = parseVersionParts(a)
  const bParts = parseVersionParts(b)
  const length = Math.max(aParts.length, bParts.length)

  for (let index = 0; index < length; index += 1) {
    const diff = (bParts[index] ?? 0) - (aParts[index] ?? 0)
    if (diff !== 0) return diff
  }

  return b.localeCompare(a)
}

/** Newest semver spec folder; used for Cloud example snippets. */
export const LATEST_EXAMPLES_VERSION = NUMBERED_REFERENCE_VERSIONS[0] ?? '1.9.x'

export const REFERENCE_VERSIONS = [
  'cloud',
  ...NUMBERED_REFERENCE_VERSIONS,
] as const

const referenceVersionSet = new Set<string>(REFERENCE_VERSIONS)

export function isDiscoveredReferenceVersion(value: string): boolean {
  return referenceVersionSet.has(value)
}
