export type NativeDbEngine = 'mysql' | 'postgres'

export type NativeIndexBuildSeverity = 'moderate' | 'significant' | 'major'

export type NativeIndexBuildEstimateInput = {
  estimatedRows?: number | string | null
  totalBytes?: number | string | null
  algorithm?: string | null
  unique?: boolean
  hasCondition?: boolean
}

export type NativeIndexBuildEstimate = {
  severity: NativeIndexBuildSeverity
  estimatedRows: number | null
  totalBytes: number | null
  factors: NativeIndexBuildFactor[]
}

export type NativeIndexBuildFactor =
  | 'unique-index'
  | 'slow-algorithm'
  | 'partial-index'

const ROW_THRESHOLDS = [50_000, 500_000, 5_000_000] as const
const BYTE_THRESHOLDS_MB = [50, 500, 5 * 1024] as const

const SLOW_INDEX_ALGORITHMS = new Set(['gin', 'gist', 'spgist'])

function parseNativeTableStat(
  value: number | string | null | undefined,
): number | null {
  if (value == null || value === '') return null
  const parsed =
    typeof value === 'string' ? Number.parseFloat(value) : Number(value)
  return Number.isFinite(parsed) && parsed >= 0 ? parsed : null
}

function tierFromThresholds(
  value: number | null,
  thresholds: readonly number[],
): number {
  if (value == null) return 0
  if (value < thresholds[0]) return 0
  if (value < thresholds[1]) return 1
  if (value < thresholds[2]) return 2
  return 3
}

function severityFromTier(tier: number): NativeIndexBuildSeverity | null {
  if (tier <= 0) return null
  if (tier === 1) return 'moderate'
  if (tier === 2) return 'significant'
  return 'major'
}

function formatNativeTableBytes(
  bytes: number | string | null | undefined,
): string {
  const value = parseNativeTableStat(bytes)
  if (value == null) return '-'
  if (value < 1024) return `${value} B`
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`
  if (value < 1024 * 1024 * 1024) {
    return `${(value / (1024 * 1024)).toFixed(1)} MB`
  }
  return `${(value / (1024 * 1024 * 1024)).toFixed(2)} GB`
}

function formatNativeEstimatedRows(rows: number | null): string {
  if (rows == null) return '-'
  return Math.round(rows).toLocaleString()
}

export function getNativeIndexBuildEstimate(
  input: NativeIndexBuildEstimateInput,
): NativeIndexBuildEstimate | null {
  const estimatedRows = parseNativeTableStat(input.estimatedRows)
  const totalBytes = parseNativeTableStat(input.totalBytes)

  if (estimatedRows == null && totalBytes == null) {
    return null
  }

  const byteThresholds = BYTE_THRESHOLDS_MB.map(
    (megabytes) => megabytes * 1024 * 1024,
  )
  const rowTier = tierFromThresholds(estimatedRows, ROW_THRESHOLDS)
  const byteTier = tierFromThresholds(totalBytes, byteThresholds)

  let tier = Math.max(rowTier, byteTier)

  const algorithm = input.algorithm?.trim().toLowerCase() ?? 'btree'
  if (input.unique) tier += 1
  if (SLOW_INDEX_ALGORITHMS.has(algorithm)) tier += 1
  if (input.hasCondition) tier -= 1

  tier = Math.max(0, Math.min(3, tier))

  const severity = severityFromTier(tier)
  if (!severity) return null

  const factors: NativeIndexBuildFactor[] = []

  if (input.hasCondition) {
    factors.push('partial-index')
  }
  if (input.unique) {
    factors.push('unique-index')
  }
  if (SLOW_INDEX_ALGORITHMS.has(algorithm)) {
    factors.push('slow-algorithm')
  }

  return {
    severity,
    estimatedRows,
    totalBytes,
    factors,
  }
}

export {
  formatNativeEstimatedRows,
  formatNativeTableBytes,
  SLOW_INDEX_ALGORITHMS,
}
