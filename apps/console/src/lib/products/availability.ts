import type { ConsoleProfileFeatures } from '@/lib/console-profiles'
import type { ProductNavItemId } from '@/lib/products/types'

/** Whether an unreleased product's marketing page, nav entry, and cards are visible. */
export function isProductReleased(
  id: ProductNavItemId,
  features: Pick<ConsoleProfileFeatures, 'videos'>,
): boolean {
  if (id === 'videos') return features.videos
  return true
}
