import type { AggregationResourceLike } from '@/lib/billing/project-breakdown-resources'

export type BillingStorageBreakdownSegmentId =
  | 'buckets'
  | 'databases'
  | 'functions'
  | 'sites'

export type BillingStorageBreakdownSegment = {
  id: BillingStorageBreakdownSegmentId
  /** English label; wrap with t() at render time. */
  label: string
  bytes: number
  color: string
}

const BILLING_STORAGE_BREAKDOWN_SPECS: ReadonlyArray<{
  id: BillingStorageBreakdownSegmentId
  label: string
  resourceIds: readonly string[]
  color: string
}> = [
  {
    id: 'buckets',
    label: 'Buckets',
    resourceIds: ['filesStorage'],
    color: 'var(--chart-brand)',
  },
  {
    id: 'databases',
    label: 'Databases',
    resourceIds: ['databasesStorage', 'backupsStorage'],
    color: 'var(--chart-2)',
  },
  {
    id: 'functions',
    label: 'Functions',
    resourceIds: ['functionsStorage'],
    color: 'var(--chart-3)',
  },
  {
    id: 'sites',
    label: 'Sites',
    resourceIds: ['sitesStorage'],
    color: 'var(--chart-4)',
  },
]

function aggregationResourceBytes(
  resources: AggregationResourceLike[],
  resourceId: string,
): number {
  const match = resources.find((r) => r.resourceId === resourceId)
  if (!match) return 0
  const n = Number(match.value)
  return Number.isFinite(n) ? Math.max(0, n) : 0
}

/**
 * Product-level storage segments from billing aggregation `resources`.
 * Uses the same families as project Usage → Storage (not builds/deployments
 * sub-metrics, which double-count against `sitesStorage` / `functionsStorage`).
 */
export function getBillingStorageBreakdownFromResources(
  resources: AggregationResourceLike[] | null | undefined,
): BillingStorageBreakdownSegment[] {
  const list = Array.isArray(resources) ? resources : []
  if (list.length === 0) return []

  return BILLING_STORAGE_BREAKDOWN_SPECS.map((spec) => ({
    id: spec.id,
    label: spec.label,
    color: spec.color,
    bytes: spec.resourceIds.reduce(
      (sum, resourceId) =>
        sum + aggregationResourceBytes(list, resourceId),
      0,
    ),
  })).filter((segment) => segment.bytes > 0)
}

export function shouldShowBillingStorageBreakdown(
  segments: BillingStorageBreakdownSegment[] | null | undefined,
  totalUsageBytes: number,
): boolean {
  if (!segments || segments.length === 0 || totalUsageBytes <= 0) {
    return false
  }
  return segments.some((segment) => segment.bytes > 0)
}
