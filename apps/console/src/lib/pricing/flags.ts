import type { ConsoleProfileFeatures } from '@/lib/console-profiles'

/**
 * Pricing for products still behind a Console feature flag: the comparison
 * table (by title) and FAQ entries stay hidden until the flag is on, so the
 * page and /pricing.md never announce a product before its launch.
 */
export type PricingFlag = keyof Pick<
  ConsoleProfileFeatures,
  'analytics' | 'videos' | 'alerts' | 'reviews' | 'zero'
>

const COMPARISON_TABLE_FLAGS: Record<string, PricingFlag> = {
  Analytics: 'analytics',
  Videos: 'videos',
  Monitors: 'alerts',
  Reviews: 'reviews',
}

export function withFlaggedComparison<T extends { title: string }>(
  tables: readonly T[],
  features: Pick<ConsoleProfileFeatures, PricingFlag>,
): T[] {
  return tables.filter((table) => {
    const flag = COMPARISON_TABLE_FLAGS[table.title]
    return !flag || features[flag]
  })
}
