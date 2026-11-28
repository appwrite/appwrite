import {
  ALTERNATIVE_IDS,
  ALTERNATIVE_REGISTRY,
  getAlternativePath,
} from '@/lib/alternatives/registry'
import type { AlternativeId } from '@/lib/alternatives/types'
import { UNLAUNCHED_MARKETING_PATHS } from '@/lib/marketing/marketing-page-paths'
import {
  isProductReleased,
  type ProductReleaseFeatures,
} from '@/lib/products/availability'

/**
 * Whether a comparison page renders for these profile features. A comparison
 * that launches with a product returns 404 until that product is released.
 */
export function isAlternativeReleased(
  id: AlternativeId,
  features: ProductReleaseFeatures,
): boolean {
  const product = ALTERNATIVE_REGISTRY[id].launchesWith
  return product ? isProductReleased(product, features) : true
}

/**
 * Comparisons listed in static outputs (Markdown index, llms.txt, prebuilt
 * exports). An unlaunched page stays out until its path leaves
 * `UNLAUNCHED_MARKETING_PATHS` at launch, like the sitemap.
 */
export function getListedAlternativeIds(): AlternativeId[] {
  return ALTERNATIVE_IDS.filter(
    (id) => !UNLAUNCHED_MARKETING_PATHS.includes(getAlternativePath(id)),
  )
}
