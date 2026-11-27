import type { ConsoleProfileFeatures } from '@/lib/console-profiles'
import type { ProductNavItemId } from '@/lib/products/types'

export type ProductReleaseFeatures = Pick<
  ConsoleProfileFeatures,
  'videos' | 'zero' | 'alerts' | 'beep' | 'reviews'
>

/** Whether an unreleased product's marketing page, nav entry, and cards are visible. */
export function isProductReleased(
  id: ProductNavItemId,
  features: ProductReleaseFeatures,
): boolean {
  if (id === 'videos') return features.videos
  if (id === 'zero') return features.zero
  // Monitors ships with the alerts it raises, behind the same flag as its Console pages.
  if (id === 'monitors') return features.alerts
  if (id === 'beep') return features.beep
  if (id === 'reviews') return features.reviews
  return true
}
