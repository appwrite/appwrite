import { BillingPlanTier } from '@/lib/constants/billing-plan'
import { normalizeVisitorCountryCode } from '@/lib/visitor-country-shared'
import { PRICING_PLAN_COLUMNS } from './constants'
import { pricingPlans } from './plans'
import type {
  ComparisonCell,
  ComparisonTable,
  PlanId,
  PricingPlan,
} from './types'

/** ISO 3166-1 alpha-2 countries that can buy the Start plan. */
export const START_PLAN_ELIGIBLE_COUNTRIES = ['IN', 'NP'] as const

export type StartPlanEligibleCountry =
  (typeof START_PLAN_ELIGIBLE_COUNTRIES)[number]

export const START_PLAN_ID = BillingPlanTier.Start

export function normalizeCountryCode(
  countryCode: string | null | undefined,
): string | null {
  return normalizeVisitorCountryCode(countryCode)
}

export function isStartPlanEligibleCountry(
  countryCode: string | null | undefined,
): boolean {
  const normalized = normalizeCountryCode(countryCode)
  if (!normalized) return false
  return (START_PLAN_ELIGIBLE_COUNTRIES as readonly string[]).includes(
    normalized,
  )
}

type PlanIdentity = {
  $id?: string
  name?: string
}

export function isStartPlanRef(
  planRef: string | null | undefined,
  plan?: PlanIdentity | null,
): boolean {
  const id = (plan?.$id ?? planRef ?? '').trim().toLowerCase()
  if (id === START_PLAN_ID || id === 'start') return true
  return (plan?.name ?? '').trim().toLowerCase() === 'start'
}

type LocationGatedPlan = PlanIdentity & {
  eligibleCountries?: string[]
}

function planEligibleCountries(
  plan: LocationGatedPlan | null | undefined,
): string[] | null {
  const listed = plan?.eligibleCountries
  if (Array.isArray(listed) && listed.length > 0) {
    return listed.map((code) => code.trim().toUpperCase()).filter(Boolean)
  }
  if (isStartPlanRef(plan?.$id, plan)) {
    return [...START_PLAN_ELIGIBLE_COUNTRIES]
  }
  return null
}

/**
 * Hide country-gated catalogue entries (Start in IN/NP) until location is known.
 * Always keep the organization's current plan so existing subscribers can see it.
 */
export function filterBillingPlansByLocation(
  plans: Record<string, LocationGatedPlan> | null | undefined,
  countryCode: string | null | undefined,
  currentPlanRef?: string | null,
): Record<string, LocationGatedPlan> {
  if (!plans) return {}

  const country = normalizeCountryCode(countryCode)
  const currentId = currentPlanRef?.trim() ?? ''
  const filtered: Record<string, LocationGatedPlan> = {}

  for (const [key, plan] of Object.entries(plans)) {
    if (!plan) continue
    const countries = planEligibleCountries(plan)
    const isCurrent =
      key === currentId ||
      plan.$id === currentId ||
      (currentId.length > 0 && isStartPlanRef(currentId, plan) && isStartPlanRef(key, plan))

    if (!countries || isCurrent) {
      filtered[key] = plan
      continue
    }

    if (country && countries.includes(country)) {
      filtered[key] = plan
    }
  }

  return filtered
}

export const startPricingPlan: PricingPlan = {
  id: 'start',
  name: 'Start',
  price: '$10',
  pricePrefix: 'From',
  priceSuffix: '/month',
  description:
    'For developers who need production-ready included resources at an accessible price.',
  featuresIntro: 'Dedicated resources per project:',
  features: [
    '80GB bandwidth',
    '40GB storage',
    '1.5M executions',
    '100K monthly active users',
    'Email support',
    'Daily backups stored for 7 days',
    '10 databases, 10 buckets, and 10 functions per project',
    '25-minute builds',
  ],
  cta: 'Start project',
  ctaVariant: 'outline',
  href: '/sign-up',
  internal: true,
}

export function getVisiblePricingPlans(showStartPlan: boolean): PricingPlan[] {
  if (!showStartPlan) return [...pricingPlans]
  const [free, ...rest] = pricingPlans
  return [free, startPricingPlan, ...rest]
}

export function getPricingPlanColumns(showStartPlan: boolean): readonly {
  id: PlanId
  label: string
}[] {
  if (!showStartPlan) return PRICING_PLAN_COLUMNS
  return [
    { id: 'free', label: 'Free' },
    { id: 'start', label: 'Start' },
    { id: 'pro', label: 'Pro' },
    { id: 'enterprise', label: 'Enterprise' },
  ]
}

/**
 * Start differs from Pro on these comparison rows. Other rows inherit Pro.
 * Keys are comparison row titles.
 */
export const startComparisonOverrides: Record<string, ComparisonCell> = {
  'API bandwidth': '80GB / month',
  Storage: '40GB',
  Executions: '1.5M / month',
  'Execution logs': '300',
  'Additional projects': '$5',
  'Organization members': '3',
  'Connected websites and apps': '10 per project',
  Webhooks: '50 per project',
  Users: '100K MAU',
  Teams: '1,000 per project',
  Databases: '10 per project',
  Reads: '1000K / month',
  Writes: '500K / month',
  'Bulk operations': '280 rows / request',
  Buckets: '10 per project',
  'File size limit': '1GB',
  'Image transformations': '35 origin images / month',
  Functions: '10 per project',
  'GB-hours': '300 GB-hour / month',
  'Build duration': '25 minutes',
  'Concurrent connections': '350',
  'Realtime:Messages': '3.5M',
  Screenshots: '500 / month',
  'Firewall rules': '15 per project',
  'Organization roles': '-',
  'SOC-2, HIPAA, and BAA': '-',
}

export function withStartComparisonValues(
  tables: readonly ComparisonTable[],
): ComparisonTable[] {
  return tables.map((table) => ({
    ...table,
    rows: table.rows.map((row) => ({
      ...row,
      start:
        startComparisonOverrides[`${table.title}:${row.title}`] ??
        startComparisonOverrides[row.title] ??
        row.pro,
    })),
  }))
}
