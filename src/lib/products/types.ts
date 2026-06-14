import type { LucideIcon } from 'lucide-react'
import type { ComponentType, SVGProps } from 'react'
import type { MarketingFaqItem } from '@/components/pages/marketing/MarketingFaqSection'
import type { MarketingStatItem } from '@/components/pages/marketing/MarketingSections'

export type ProductIcon = LucideIcon | ComponentType<SVGProps<SVGSVGElement>>

export type ProductId =
  | 'auth'
  | 'databases'
  | 'storage'
  | 'functions'
  | 'messaging'
  | 'sites'

export type ProductGroup = 'build' | 'deploy'

export type ProductNavGroup = ProductGroup | 'protect'

export type ProductNavItemId =
  | ProductId
  | 'realtime'
  | 'domains'
  | 'firewall'
  | 'advisor'

export type ProductNavItem = {
  id: ProductNavItemId
  name: string
  group: ProductNavGroup
  href: string
  icon: ProductIcon
  tagline: string
}

export type ProductRegistryItem = {
  id: ProductId
  name: string
  group: ProductGroup
  path: `/products/${ProductId}`
  icon: ProductIcon
  tagline: string
  docsPath: string
}

export type ProductPageContent = {
  id: ProductId
  metaDescription: string
  hero: {
    title: string
    description: string
    stats?: MarketingStatItem[]
  }
  faq: MarketingFaqItem[]
  cta: {
    title: string
    description: string
  }
}
