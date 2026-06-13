export type ProductFeatureLayout = 'split' | 'stacked'

export type ProductFeatureContent = {
  id: string
  title: string
  description: string
  docsHref: string
  docsLabel: string
  layout?: ProductFeatureLayout
}
