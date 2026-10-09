import { getSeoSiteOrigin } from '@/lib/marketing/site-origin'
import type { ProductId } from '@/lib/products/types'

/** 2× classic Open Graph canvas (1200×630). */
export const PRODUCT_OG_IMAGE_WIDTH = 2400
export const PRODUCT_OG_IMAGE_HEIGHT = 1260

export function getProductOgImagePath(
  productId: ProductId,
): `/images/products/${ProductId}/og.avif` {
  return `/images/products/${productId}/og.avif`
}

export function getProductOgImageUrl(
  productId: ProductId,
  siteOrigin?: string,
): string {
  return `${getSeoSiteOrigin(siteOrigin)}${getProductOgImagePath(productId)}`
}
