import type { DocsTocItem } from '@/lib/docs/types'

export type IntegrationPlatform = 'Cloud' | 'Self-hosted'

export type IntegrationCategorySlug =
  | 'mcp'
  | 'ai'
  | 'auth'
  | 'databases'
  | 'logging'
  | 'messaging'
  | 'payments'
  | 'search'
  | 'sites'
  | 'storage'
  | 'deployments'

export type IntegrationProduct = {
  avatar: string
  vendor: string
  description: string
}

export type IntegrationMeta = {
  slug: string
  href: string
  title: string
  description: string
  date: string
  category: IntegrationCategorySlug
  cover: string
  featured?: boolean
  isPartner?: boolean
  isNew?: boolean
  platform: IntegrationPlatform[]
  product: IntegrationProduct
  images: string[]
}

export type Integration = IntegrationMeta & {
  content: string
  toc: DocsTocItem[]
}

export type IntegrationCategory = {
  slug: IntegrationCategorySlug
  heading: string
  description: string
}

export type IntegrationCategoryGroup = {
  category: IntegrationCategorySlug
  heading: string
  description: string
  integrations: IntegrationMeta[]
}

export type IntegrationsCatalog = {
  list: IntegrationMeta[]
  featured: IntegrationMeta[]
  categories: IntegrationCategory[]
  platforms: IntegrationPlatform[]
  grouped: IntegrationCategoryGroup[]
}

export type IntegrationsSearch = {
  search?: string
  category?: string
  platform?: string
}
