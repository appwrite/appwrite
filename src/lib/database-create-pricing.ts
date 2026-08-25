import type { Models } from '@appwrite.io/console'

/** Fraction-of-base-tier rates for dedicated database add-ons. */
export type DedicatedDatabaseCreatePricing = {
  haReplicaRate: number
  pitrRate: number
  haReplicaLabel: string
  pitrLabel: string
}

export type DedicatedDatabaseMonthlyCost = {
  baseUsd: number
  haReplicasUsd: number
  pitrUsd: number
  totalUsd: number
}

type PlanUsageEntry = {
  price?: number
  name?: string
  invoiceDesc?: string
}

type PlanUsageMap = Record<string, PlanUsageEntry | undefined>

export const DEFAULT_HA_REPLICA_RATE = 0.5
export const DEFAULT_PITR_RATE = 0.2

/** Resolve HA replica and PITR pricing multipliers from org plan (preferred) or API spec pricing. */
export function getDedicatedDatabaseCreatePricing(
  plan: Models.BillingPlan | null | undefined,
  apiPricing: Models.DedicatedDatabaseSpecificationPricing | null | undefined,
): DedicatedDatabaseCreatePricing {
  const usage = plan?.usage as PlanUsageMap | undefined

  const haReplicaRate =
    usage?.dedicatedDbHaReplica?.price ??
    apiPricing?.replicaRate ??
    DEFAULT_HA_REPLICA_RATE

  const pitrRate =
    usage?.dedicatedDbPitr?.price ??
    apiPricing?.pitrRate ??
    DEFAULT_PITR_RATE

  return {
    haReplicaRate,
    pitrRate,
    haReplicaLabel:
      usage?.dedicatedDbHaReplica?.name?.trim() || 'HA replicas',
    pitrLabel: usage?.dedicatedDbPitr?.name?.trim() || 'Point-in-time recovery',
  }
}

export function calculateDedicatedDatabaseMonthlyCost(params: {
  basePriceUsd: number
  replicaCount: number
  pitrEnabled: boolean
  pricing: DedicatedDatabaseCreatePricing
}): DedicatedDatabaseMonthlyCost {
  const baseUsd = Math.max(0, params.basePriceUsd)
  const haReplicasUsd =
    baseUsd * params.pricing.haReplicaRate * Math.max(0, params.replicaCount)
  const pitrUsd = params.pitrEnabled
    ? baseUsd * params.pricing.pitrRate
    : 0

  return {
    baseUsd,
    haReplicasUsd,
    pitrUsd,
    totalUsd: baseUsd + haReplicasUsd + pitrUsd,
  }
}

/** English source for the included compute-credit callout. Amount comes from the plan. */
export function formatDatabaseComputeCreditsNote(amountUsd: number): string {
  const rounded = Number.isInteger(amountUsd)
    ? amountUsd.toFixed(0)
    : amountUsd.toFixed(2)
  return `$${rounded} of compute credits for database usage included every month.`
}

/** Marketing fallback when no organization plan is available. */
export const DATABASE_COMPUTE_CREDITS_NOTE = formatDatabaseComputeCreditsNote(10)

export function formatDedicatedMonthlyPrice(amountUsd: number): string {
  const rounded =
    Number.isInteger(amountUsd) ? amountUsd.toFixed(0) : amountUsd.toFixed(2)
  return `$${rounded}/mo`
}

export function formatDedicatedAddonPrice(amountUsd: number): string {
  if (amountUsd <= 0) return formatDedicatedMonthlyPrice(0)
  const rounded =
    Number.isInteger(amountUsd) ? amountUsd.toFixed(0) : amountUsd.toFixed(2)
  return `+$${rounded}/mo`
}

export function formatDedicatedMonthlyTotal(amountUsd: number): string {
  return formatDedicatedMonthlyPrice(amountUsd)
}

export const MAX_DEDICATED_DB_HA_REPLICA_COUNT = 5

export const DEDICATED_DB_HA_REPLICA_OPTIONS = [
  {
    count: 0,
    label: 'None',
    description:
      'Primary instance only. Suitable for development and workloads that can tolerate brief downtime.',
  },
  {
    count: 1,
    label: '1 replica',
    description:
      'One read replica to offload queries and reduce recovery time if the primary fails.',
  },
  {
    count: 2,
    label: '2 replicas',
    description:
      'Two read replicas for higher throughput and smoother operation during maintenance.',
  },
  {
    count: 3,
    label: '3 replicas',
    description:
      'Three read replicas for production workloads with sustained read demand.',
  },
  {
    count: 4,
    label: '4 replicas',
    description:
      'Four read replicas for large-scale read traffic and increased failover capacity.',
  },
  {
    count: 5,
    label: '5 replicas',
    description:
      'Maximum self-serve replica count for high-traffic production environments.',
  },
] as const
