import { describe, expect, test } from 'bun:test'
import { PRODUCT_IDS } from '@/lib/products/registry'
import {
  getProductOgImagePath,
  getProductOgImageUrl,
  PRODUCT_OG_IMAGE_HEIGHT,
  PRODUCT_OG_IMAGE_WIDTH,
} from '@/lib/products/og-image'

describe('product OG images', () => {
  test('maps every product to a static cover path', () => {
    for (const productId of PRODUCT_IDS) {
      expect(getProductOgImagePath(productId)).toBe(
        `/images/products/${productId}/og.avif`,
      )
    }
  })

  test('builds an absolute OG URL from the site origin', () => {
    expect(getProductOgImageUrl('auth', 'https://appwrite.io')).toBe(
      'https://appwrite.io/images/products/auth/og.avif',
    )
  })

  test('uses a 2x Open Graph canvas', () => {
    expect(PRODUCT_OG_IMAGE_WIDTH).toBe(2400)
    expect(PRODUCT_OG_IMAGE_HEIGHT).toBe(1260)
  })
})
