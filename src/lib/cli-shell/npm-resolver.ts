/**
 * Browser npm dependency resolver for the in-terminal CLI install path.
 * Handles compound semver ranges (e.g. `>= 2.1.2 < 3`) that almostnode 0.2.14
 * does not parse correctly in its bundled resolver.
 */

import { Registry } from '@almostnode-internal/registry'

export interface ResolvedPackage {
  name: string
  version: string
  tarballUrl: string
  dependencies: Record<string, string>
}

export interface ResolveOptions {
  registry?: Registry
  includeDev?: boolean
  includeOptional?: boolean
  onProgress?: (message: string) => void
}

interface ResolveContext {
  registry: Registry
  resolved: Map<string, ResolvedPackage>
  resolving: Set<string>
  options: ResolveOptions
}

function parseVersion(version: string): {
  major: number
  minor: number
  patch: number
  prerelease?: string
} | null {
  const match = version.match(/^(\d+)\.(\d+)\.(\d+)(?:-(.+))?$/)
  if (!match) return null

  return {
    major: parseInt(match[1], 10),
    minor: parseInt(match[2], 10),
    patch: parseInt(match[3], 10),
    prerelease: match[4],
  }
}

function compareVersions(a: string, b: string): number {
  const parsedA = parseVersion(a)
  const parsedB = parseVersion(b)

  if (!parsedA || !parsedB) {
    return a.localeCompare(b)
  }

  if (parsedA.major !== parsedB.major) {
    return parsedA.major - parsedB.major
  }
  if (parsedA.minor !== parsedB.minor) {
    return parsedA.minor - parsedB.minor
  }
  if (parsedA.patch !== parsedB.patch) {
    return parsedA.patch - parsedB.patch
  }

  if (parsedA.prerelease && !parsedB.prerelease) return -1
  if (!parsedA.prerelease && parsedB.prerelease) return 1
  if (parsedA.prerelease && parsedB.prerelease) {
    return parsedA.prerelease.localeCompare(parsedB.prerelease)
  }

  return 0
}

function normalizeRangeVersion(version: string): string {
  const [core, ...prereleaseParts] = version.split('-')
  const parts = core.split('.')
  while (parts.length < 3) {
    parts.push('0')
  }
  const normalized = parts.slice(0, 3).join('.')
  return prereleaseParts.length > 0
    ? `${normalized}-${prereleaseParts.join('-')}`
    : normalized
}

function satisfies(version: string, range: string): boolean {
  const parsed = parseVersion(version)
  if (!parsed) return false

  if (parsed.prerelease && !range.includes('-')) {
    return false
  }

  range = range.trim()

  if (/^\d+\.\d+\.\d+/.test(range) && !range.includes(' ')) {
    const rangeMatch = range.match(/^(\d+\.\d+\.\d+(?:-[^\s]+)?)/)
    if (rangeMatch) {
      return compareVersions(version, rangeMatch[1]) === 0
    }
  }

  if (range === '*' || range === 'latest' || range === '') {
    return true
  }

  if (range.includes('||')) {
    return range.split('||').some((r) => satisfies(version, r.trim()))
  }

  if (range.includes(' - ')) {
    const [min, max] = range.split(' - ').map((s) => s.trim())
    return compareVersions(version, min) >= 0 && compareVersions(version, max) <= 0
  }

  const operatorMatches = range.match(
    /(>=|<=|>|<|=)\s*(\d+(?:\.\d+){0,2}(?:-[^\s]*)?)/g,
  )
  if (operatorMatches && operatorMatches.length > 1) {
    return operatorMatches.every((match) => {
      const m = match.match(/^(>=|<=|>|<|=)\s*(.+)$/)
      if (!m) return true
      const op = m[1]
      const ver = normalizeRangeVersion(m[2])
      switch (op) {
        case '>=':
          return compareVersions(version, ver) >= 0
        case '<=':
          return compareVersions(version, ver) <= 0
        case '>':
          return compareVersions(version, ver) > 0
        case '<':
          return compareVersions(version, ver) < 0
        case '=':
          return compareVersions(version, ver) === 0
        default:
          return compareVersions(version, ver) === 0
      }
    })
  }

  if (range.startsWith('^')) {
    const base = range.slice(1)
    const baseParsed = parseVersion(base)
    if (!baseParsed) return false

    if (parsed.major !== baseParsed.major) {
      return false
    }

    if (baseParsed.major === 0) {
      if (baseParsed.minor !== 0 && parsed.minor !== baseParsed.minor) {
        return false
      }
      if (baseParsed.minor === 0 && parsed.minor !== 0) {
        return false
      }
    }

    return compareVersions(version, base) >= 0
  }

  if (range.startsWith('~')) {
    const base = range.slice(1)
    const baseParsed = parseVersion(base)
    if (!baseParsed) return false

    if (parsed.major !== baseParsed.major || parsed.minor !== baseParsed.minor) {
      return false
    }

    return compareVersions(version, base) >= 0
  }

  if (range.startsWith('>=')) {
    const rest = range.slice(2).trim()
    if (/(^|\s)(>=|<=|>|<|=)\s*\d/.test(rest)) {
      const nestedMatches = range.match(
        /(>=|<=|>|<|=)\s*(\d+(?:\.\d+){0,2}(?:-[^\s]*)?)/g,
      )
      if (nestedMatches && nestedMatches.length > 1) {
        return nestedMatches.every((match) =>
          satisfies(version, match.trim()),
        )
      }
    }
    return compareVersions(version, normalizeRangeVersion(rest)) >= 0
  }

  if (range.startsWith('>')) {
    const base = normalizeRangeVersion(range.slice(1).trim())
    return compareVersions(version, base) > 0
  }

  if (range.startsWith('<=')) {
    const base = normalizeRangeVersion(range.slice(2).trim())
    return compareVersions(version, base) <= 0
  }

  if (range.startsWith('<')) {
    const base = normalizeRangeVersion(range.slice(1).trim())
    return compareVersions(version, base) < 0
  }

  if (
    range.includes('x') ||
    range.includes('X') ||
    /^\d+$/.test(range) ||
    /^\d+\.\d+$/.test(range)
  ) {
    const parts = range.replace(/[xX]/g, '').split('.').filter(Boolean)

    if (parts.length === 1) {
      return parsed.major === parseInt(parts[0], 10)
    }
    if (parts.length === 2) {
      return (
        parsed.major === parseInt(parts[0], 10) &&
        parsed.minor === parseInt(parts[1], 10)
      )
    }
  }

  if (range.includes(' ')) {
    const conditions = range.split(/\s+/).filter(Boolean)
    return conditions.every((r) => satisfies(version, r))
  }

  return compareVersions(version, range) === 0
}

function findBestVersion(versions: string[], range: string): string | null {
  const sorted = [...versions].sort((a, b) => compareVersions(b, a))

  for (const version of sorted) {
    if (satisfies(version, range)) {
      return version
    }
  }

  return null
}

export async function resolveDependencies(
  packageName: string,
  versionRange: string = 'latest',
  options: ResolveOptions = {},
): Promise<Map<string, ResolvedPackage>> {
  const registry = options.registry || new Registry()
  const context: ResolveContext = {
    registry,
    resolved: new Map(),
    resolving: new Set(),
    options,
  }

  await resolvePackage(packageName, versionRange, context)

  return context.resolved
}

async function resolvePackage(
  packageName: string,
  versionRange: string,
  context: ResolveContext,
): Promise<void> {
  const { registry, resolved, resolving, options } = context
  const key = `${packageName}@${versionRange}`

  if (resolving.has(key)) {
    return
  }

  if (resolved.has(packageName)) {
    const existing = resolved.get(packageName)!
    if (satisfies(existing.version, versionRange)) {
      return
    }
    return
  }

  resolving.add(key)

  try {
    options.onProgress?.(`Resolving ${packageName}@${versionRange}`)

    const manifest = await registry.getPackageManifest(packageName)
    const versions = Object.keys(manifest.versions)
    let targetVersion: string

    if (versionRange === 'latest' || versionRange === '*') {
      targetVersion = manifest['dist-tags'].latest
    } else if (manifest['dist-tags'][versionRange]) {
      targetVersion = manifest['dist-tags'][versionRange]
    } else {
      const best = findBestVersion(versions, versionRange)
      if (!best) {
        throw new Error(
          `No matching version found for ${packageName}@${versionRange}`,
        )
      }
      targetVersion = best
    }

    const versionData = manifest.versions[targetVersion]

    const resolvedPackage: ResolvedPackage = {
      name: packageName,
      version: targetVersion,
      tarballUrl: versionData.dist.tarball,
      dependencies: versionData.dependencies || {},
    }

    resolved.set(packageName, resolvedPackage)

    const deps: Record<string, string> = {}

    if (versionData.peerDependencies) {
      const meta = versionData.peerDependenciesMeta || {}
      for (const [name, range] of Object.entries(versionData.peerDependencies)) {
        if (!meta[name]?.optional) {
          deps[name] = String(range)
        }
      }
    }

    Object.assign(deps, versionData.dependencies)

    if (options.includeOptional && versionData.optionalDependencies) {
      Object.assign(deps, versionData.optionalDependencies)
    }

    const depEntries = Object.entries(deps)
    if (depEntries.length > 0) {
      const CONCURRENCY = 8
      for (let i = 0; i < depEntries.length; i += CONCURRENCY) {
        const batch = depEntries.slice(i, i + CONCURRENCY)
        await Promise.all(
          batch.map(([depName, depRange]) =>
            resolvePackage(depName, depRange, context),
          ),
        )
      }
    }
  } finally {
    resolving.delete(key)
  }
}
