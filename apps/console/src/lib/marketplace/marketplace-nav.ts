import type { LucideIcon } from 'lucide-react'
import {
  // BookOpen and FileText back the commented-out sidebar links below;
  // LayoutGrid backs the commented-out Catalog nav item.
  // BookOpen,
  Compass,
  // FileText,
  // LayoutGrid,
  Package,
  Plus,
} from 'lucide-react'
import {
  MARKETPLACE_CATEGORY_ICONS,
  MARKETPLACE_CATEGORY_LABELS,
  MARKETPLACE_CATEGORY_ORDER,
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
          description: 'Browse official apps, or explore by category.',
        },
        // Hidden for now — Explore already lists every published app.
        // {
        //   id: 'catalog',
        //   label: 'Catalog',
        //   icon: LayoutGrid,
        //   keywords: ['catalog', 'browse', 'all', 'integrations'],
        //   description: 'Explore all apps available in the marketplace.',
        // },
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
    label: 'Create app',
    icon: Plus,
    action: 'add-app',
  },
  // Hidden for now, but kept for when the docs pages are ready.
  // {
  //   id: 'docs',
  //   label: 'Documentation',
  //   icon: BookOpen,
  //   href: '/docs',
  //   external: true,
  // },
  // {
  //   id: 'publisher-guidelines',
  //   label: 'Publisher guidelines',
  //   icon: FileText,
  //   action: 'publisher-guidelines',
  // },
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
