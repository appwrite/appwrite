import type { ProductId } from '@/lib/products/types'

/** Brand palette tones. CSS variables for each live in `styles.css` under `[data-product-tone]`. */
export type ProductTone = 'pink' | 'purple' | 'mint' | 'orange'

export type ProductHeroLayout = 'split' | 'centered'

/**
 * - `alternate`: text and visual swap sides every section.
 * - `aligned`: text column always on the start side, wider visual.
 * - `rail`: timeline running down the start gutter with a node per section.
 */
export type ProductFeatureStyle = 'alternate' | 'aligned' | 'rail'

export type ProductTheme = {
  tone: ProductTone
  secondaryTone: ProductTone
  heroLayout: ProductHeroLayout
  featureStyle: ProductFeatureStyle
}

export const PRODUCT_THEMES: Record<ProductId, ProductTheme> = {
  auth: {
    tone: 'pink',
    secondaryTone: 'purple',
    heroLayout: 'split',
    featureStyle: 'alternate',
  },
  databases: {
    tone: 'purple',
    secondaryTone: 'mint',
    heroLayout: 'centered',
    featureStyle: 'aligned',
  },
  postgres: {
    tone: 'purple',
    secondaryTone: 'orange',
    heroLayout: 'split',
    featureStyle: 'aligned',
  },
  storage: {
    tone: 'mint',
    secondaryTone: 'purple',
    heroLayout: 'split',
    featureStyle: 'alternate',
  },
  functions: {
    tone: 'orange',
    secondaryTone: 'pink',
    heroLayout: 'centered',
    featureStyle: 'rail',
  },
  messaging: {
    tone: 'pink',
    secondaryTone: 'orange',
    heroLayout: 'split',
    featureStyle: 'aligned',
  },
  realtime: {
    tone: 'purple',
    secondaryTone: 'mint',
    heroLayout: 'centered',
    featureStyle: 'alternate',
  },
  sites: {
    tone: 'mint',
    secondaryTone: 'orange',
    heroLayout: 'centered',
    featureStyle: 'rail',
  },
  firewall: {
    tone: 'orange',
    secondaryTone: 'purple',
    heroLayout: 'centered',
    featureStyle: 'aligned',
  },
}

export function getProductTheme(productId: ProductId): ProductTheme {
  return PRODUCT_THEMES[productId]
}

/** Spread on a wrapper to scope `--tone-*` / `--tone2-*` CSS variables. */
export function productToneAttrs(theme: Pick<ProductTheme, 'tone' | 'secondaryTone'>) {
  return {
    'data-product-tone': theme.tone,
    'data-product-tone2': theme.secondaryTone,
  } as const
}
