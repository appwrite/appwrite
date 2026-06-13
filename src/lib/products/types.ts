import type { LucideIcon } from 'lucide-react'
import type { ComponentType, SVGProps } from 'react'
import type { MarketingFaqItem } from '@/components/pages/marketing/MarketingFaqSection'
import type {
  MarketingFeatureIcon,
  MarketingFeatureItem,
  MarketingStatItem,
} from '@/components/pages/marketing/MarketingSections'

export type ProductIcon = LucideIcon | ComponentType<SVGProps<SVGSVGElement>>

export type ProductId =
  | 'auth'
  | 'databases'
  | 'storage'
  | 'functions'
  | 'messaging'
  | 'sites'

export type ProductGroup = 'build' | 'deploy'

export type ProductRegistryItem = {
  id: ProductId
  name: string
  group: ProductGroup
  path: `/products/${ProductId}`
  icon: ProductIcon
  tagline: string
  docsPath: string
}

export type ProductIntegrationItem = {
  productId: ProductId
  title: string
  description: string
}

export type ProductFeatureGridSection = {
  type: 'feature-grid'
  title: string
  description?: string
  items: MarketingFeatureItem[]
  columns?: 2 | 3 | 4
  muted?: boolean
}

export type ProductMethodCardSection = {
  type: 'method-cards'
  title: string
  description?: string
  items: {
    title: string
    description: string
    icon: MarketingFeatureIcon
    href: string
  }[]
}

export type ProductCompareSection = {
  type: 'compare'
  title: string
  description?: string
  muted?: boolean
  items: {
    title: string
    description: string
    icon: ProductIcon
    bullets: string[]
  }[]
}

export type ProductStepsSection = {
  type: 'steps'
  title: string
  description?: string
  items: {
    title: string
    description: string
  }[]
  muted?: boolean
}

export type ProductUniqueSection =
  | ProductFeatureGridSection
  | ProductMethodCardSection
  | ProductCompareSection
  | ProductStepsSection

export type ProductPageContent = {
  id: ProductId
  metaDescription: string
  hero: {
    title: string
    description: string
    stats?: MarketingStatItem[]
  }
  capabilities: {
    title: string
    description?: string
    items: MarketingFeatureItem[]
  }
  visual: {
    title: string
    description: string
  }
  uniqueSections: ProductUniqueSection[]
  integrations: {
    title: string
    description: string
    items: ProductIntegrationItem[]
  }
  faq: MarketingFaqItem[]
  cta: {
    title: string
    description: string
  }
}
