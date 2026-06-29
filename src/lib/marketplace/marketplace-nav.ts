import type { LucideIcon } from 'lucide-react'
import {
  BookOpen,
  Compass,
  FileText,
  LayoutGrid,
  Package,
  Plus,
} from 'lucide-react'
import {
  MARKETPLACE_CATEGORY_ICONS,
  MARKETPLACE_CATEGORY_LABELS,
  MARKETPLACE_CATEGORY_ORDER,
  type MarketplaceApp,
  type MarketplaceAppCategory,
} from '@/lib/marketplace/types'

export { MARKETPLACE_CATEGORY_ORDER }

export type MarketplaceNavId =
  | 'explore'
  | 'catalog'
  | 'my-apps'
  | `category:${MarketplaceAppCategory}`

export type MarketplaceNavItem = {
  id: MarketplaceNavId
  label: string
  icon: LucideIcon
  keywords?: string[]
  description?: string
}

export type MarketplaceNavGroup = {
  id: string
  label: string
  items: MarketplaceNavItem[]
}

export type MarketplaceLinkItem = {
  id: string
  label: string
  icon: LucideIcon
  href?: string
  external?: boolean
  action?: 'add-app' | 'publisher-guidelines'
}

export function buildMarketplaceNavGroups(): MarketplaceNavGroup[] {
  return [
    {
      id: 'discover',
      label: 'Discover',
      items: [
        {
          id: 'explore',
          label: 'Explore',
          icon: Compass,
          keywords: ['explore', 'overview', 'home', 'discover', 'featured'],
          description:
            'Featured apps, popular integrations, and browse by category.',
        },
        {
          id: 'catalog',
          label: 'Catalog',
          icon: LayoutGrid,
          keywords: ['catalog', 'browse', 'all', 'integrations'],
          description: 'Explore all apps available in the marketplace.',
        },
      ],
    },
    {
      id: 'categories',
      label: 'Categories',
      items: MARKETPLACE_CATEGORY_ORDER.map((category) => ({
        id: `category:${category}` as MarketplaceNavId,
        label: MARKETPLACE_CATEGORY_LABELS[category],
        icon: MARKETPLACE_CATEGORY_ICONS[category],
        keywords: [category, MARKETPLACE_CATEGORY_LABELS[category]],
        description: `Apps in ${MARKETPLACE_CATEGORY_LABELS[category].toLowerCase()}.`,
      })),
    },
    {
      id: 'workspace',
      label: 'Your apps',
      items: [
        {
          id: 'my-apps',
          label: 'My apps',
          icon: Package,
          keywords: ['my', 'owned', 'published', 'draft'],
          description: 'Apps published by your organization.',
        },
      ],
    },
  ]
}

export const MARKETPLACE_SIDEBAR_LINKS: MarketplaceLinkItem[] = [
  {
    id: 'add-app',
    label: 'Add app',
    icon: Plus,
    action: 'add-app',
  },
  {
    id: 'docs',
    label: 'Documentation',
    icon: BookOpen,
    href: '/docs',
    external: true,
  },
  {
    id: 'publisher-guidelines',
    label: 'Publisher guidelines',
    icon: FileText,
    action: 'publisher-guidelines',
  },
]

export function getMarketplaceNavItem(
  navId: MarketplaceNavId,
  groups: MarketplaceNavGroup[] = buildMarketplaceNavGroups(),
): MarketplaceNavItem | undefined {
  for (const group of groups) {
    const item = group.items.find((i) => i.id === navId)
    if (item) return item
  }
  return undefined
}

export function getAppsForMarketplaceNav(
  navId: MarketplaceNavId,
  catalog: MarketplaceApp[],
  owned: MarketplaceApp[],
): MarketplaceApp[] {
  switch (navId) {
    case 'explore':
      return catalog
    case 'catalog':
      return catalog
    case 'my-apps':
      return owned
    default:
      if (navId.startsWith('category:')) {
        const category = navId.slice('category:'.length) as MarketplaceAppCategory
        return catalog.filter((a) => a.category === category)
      }
      return catalog
  }
}

export function countAppsForNav(
  navId: MarketplaceNavId,
  catalog: MarketplaceApp[],
  owned: MarketplaceApp[],
): number {
  return getAppsForMarketplaceNav(navId, catalog, owned).length
}
