export type PlanId = 'free' | 'pro' | 'enterprise'

export type ComparisonLinkCell = {
  text: string
  href: string
}

export type ComparisonCell = string | true | ComparisonLinkCell

export type ComparisonRow = {
  title: string
  info?: string
  free: ComparisonCell
  pro: ComparisonCell
  enterprise: ComparisonCell
}

export type ComparisonTable = {
  title: string
  rows: ComparisonRow[]
}

export type PricingPlan = {
  id: PlanId
  name: string
  price: string
  pricePrefix?: string
  priceSuffix?: string
  description: string
  featuresIntro?: string
  features: readonly string[]
  footnote?: string
  /** Short highlight shown under the price (above the fold). */
  callout?: string
  cta: string
  ctaVariant: 'brandCta' | 'outline'
  href: string
  internal: boolean
  popular?: boolean
}
