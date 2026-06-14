export type ProductFeatureLayout = 'split' | 'stacked'

export type ProductFeatureBrandLightTone = 'pink' | 'purple' | 'teal' | 'orange'

export type ProductFeatureContent = {
  id: string
  title: string
  description: string
  docsHref: string
  docsLabel: string
  layout?: ProductFeatureLayout
  /** Centered stacked layout: title, companion, and a compact visual below. */
  centered?: boolean
  /** Render companion content only (no mock console visual). */
  hideVisual?: boolean
  /** Brand ambient wash in the section. */
  brandLight?: ProductFeatureBrandLightTone
  /** Where the brand wash originates. Defaults to top. */
  brandLightPosition?: 'top' | 'bottom'
  /** Dot grid background used on marketing hero sections. */
  dottedBackground?: boolean
  /** Use full section width (max-w-7xl) for companion content below stacked title. */
  wideCompanion?: boolean
  /** Hide the docs link below companion content (when CTA is inline). */
  hideDocsLink?: boolean
  /** Remove bottom section padding so companion content can meet the section border. */
  flushBottom?: boolean
}
