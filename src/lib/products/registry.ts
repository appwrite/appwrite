import {
  BotMessageSquare,
  Database,
  Folder,
  Globe,
  MessageSquare,
  Radio,
  ScanSearch,
  Search,
  Shield,
  Users,
  Zap,
} from 'lucide-react'
import { PostgresElephantIcon } from '@/components/pages/projects/$projectId/databases/_components/database-mascot-icons'
import type {
  ProductId,
  ProductNavItem,
  ProductNavItemId,
  ProductRegistryItem,
} from '@/lib/products/types'

export const PRODUCT_IDS = [
  'auth',
  'databases',
  'postgres',
  'storage',
  'functions',
  'messaging',
  'realtime',
  'sites',
  'firewall',
] as const satisfies readonly ProductId[]

export const PRODUCT_REGISTRY: Record<ProductId, ProductRegistryItem> = {
  auth: {
    id: 'auth',
    name: 'Auth',
    group: 'build',
    path: '/products/auth',
    icon: Users,
    tagline: 'Email, OAuth, SMS, MFA, teams, and sessions.',
    docsPath: '/docs/products/auth',
  },
  databases: {
    id: 'databases',
    name: 'Databases',
    group: 'build',
    path: '/products/databases',
    icon: Database,
    tagline: 'Managed PostgreSQL, TablesDB, DocumentsDB, VectorsDB, MySQL.',
    docsPath: '/docs/products/databases',
  },
  postgres: {
    id: 'postgres',
    name: 'PostgreSQL',
    group: 'build',
    path: '/products/postgres',
    icon: PostgresElephantIcon,
    tagline: 'Managed PostgreSQL with pooling, replicas, and PITR.',
    docsPath: '/docs/products/databases/postgresql',
  },
  storage: {
    id: 'storage',
    name: 'Storage',
    group: 'build',
    path: '/products/storage',
    icon: Folder,
    tagline: 'Upload, transform, and deliver files on CDN.',
    docsPath: '/docs/products/storage',
  },
  functions: {
    id: 'functions',
    name: 'Functions',
    group: 'build',
    path: '/products/functions',
    icon: Zap,
    tagline: 'APIs, cron jobs, and event handlers at scale.',
    docsPath: '/docs/products/functions',
  },
  messaging: {
    id: 'messaging',
    name: 'Messaging',
    group: 'build',
    path: '/products/messaging',
    icon: MessageSquare,
    tagline: 'Email, SMS, and push with topics and targets.',
    docsPath: '/docs/products/messaging',
  },
  realtime: {
    id: 'realtime',
    name: 'Realtime',
    group: 'build',
    path: '/products/realtime',
    icon: Radio,
    tagline: 'Live events, channels, and presence.',
    docsPath: '/docs/apis/realtime',
  },
  sites: {
    id: 'sites',
    name: 'Sites',
    group: 'deploy',
    path: '/products/sites',
    icon: Globe,
    tagline: 'Static, SSR, and CSR deploys from Git.',
    docsPath: '/docs/products/sites',
  },
  firewall: {
    id: 'firewall',
    name: 'Firewall',
    group: 'protect',
    path: '/products/firewall',
    icon: Shield,
    tagline: 'Project rules to deny, rate limit, and redirect traffic.',
    docsPath: '/docs/products/firewall',
  },
}

export const BUILD_PRODUCT_IDS = PRODUCT_IDS.filter(
  (id) => PRODUCT_REGISTRY[id].group === 'build',
)

export const DEPLOY_PRODUCT_IDS = PRODUCT_IDS.filter(
  (id) => PRODUCT_REGISTRY[id].group === 'deploy',
)

export const PRODUCT_NAV_ITEM_IDS = [
  'auth',
  'databases',
  'postgres',
  'storage',
  'functions',
  'messaging',
  'realtime',
  'agent',
  'sites',
  'domains',
  'firewall',
  'advisor',
] as const satisfies readonly ProductNavItemId[]

export const PRODUCT_NAV_REGISTRY: Record<ProductNavItemId, ProductNavItem> = {
  auth: {
    id: 'auth',
    name: PRODUCT_REGISTRY.auth.name,
    group: 'build',
    href: PRODUCT_REGISTRY.auth.path,
    icon: PRODUCT_REGISTRY.auth.icon,
    tagline: PRODUCT_REGISTRY.auth.tagline,
  },
  databases: {
    id: 'databases',
    name: PRODUCT_REGISTRY.databases.name,
    group: 'build',
    href: PRODUCT_REGISTRY.databases.path,
    icon: PRODUCT_REGISTRY.databases.icon,
    tagline: PRODUCT_REGISTRY.databases.tagline,
  },
  postgres: {
    id: 'postgres',
    name: PRODUCT_REGISTRY.postgres.name,
    group: 'build',
    href: PRODUCT_REGISTRY.postgres.path,
    icon: PRODUCT_REGISTRY.postgres.icon,
    tagline: PRODUCT_REGISTRY.postgres.tagline,
  },
  storage: {
    id: 'storage',
    name: PRODUCT_REGISTRY.storage.name,
    group: 'build',
    href: PRODUCT_REGISTRY.storage.path,
    icon: PRODUCT_REGISTRY.storage.icon,
    tagline: PRODUCT_REGISTRY.storage.tagline,
  },
  functions: {
    id: 'functions',
    name: PRODUCT_REGISTRY.functions.name,
    group: 'build',
    href: PRODUCT_REGISTRY.functions.path,
    icon: PRODUCT_REGISTRY.functions.icon,
    tagline: PRODUCT_REGISTRY.functions.tagline,
  },
  messaging: {
    id: 'messaging',
    name: PRODUCT_REGISTRY.messaging.name,
    group: 'build',
    href: PRODUCT_REGISTRY.messaging.path,
    icon: PRODUCT_REGISTRY.messaging.icon,
    tagline: PRODUCT_REGISTRY.messaging.tagline,
  },
  realtime: {
    id: 'realtime',
    name: PRODUCT_REGISTRY.realtime.name,
    group: 'build',
    href: PRODUCT_REGISTRY.realtime.path,
    icon: PRODUCT_REGISTRY.realtime.icon,
    tagline: PRODUCT_REGISTRY.realtime.tagline,
  },
  agent: {
    id: 'agent',
    name: 'Agent',
    group: 'build',
    href: '/docs/products/agent',
    icon: BotMessageSquare,
    tagline: 'Chat to inspect your project and take approved actions.',
  },
  sites: {
    id: 'sites',
    name: PRODUCT_REGISTRY.sites.name,
    group: 'deploy',
    href: PRODUCT_REGISTRY.sites.path,
    icon: PRODUCT_REGISTRY.sites.icon,
    tagline: PRODUCT_REGISTRY.sites.tagline,
  },
  domains: {
    id: 'domains',
    name: 'Domains',
    group: 'deploy',
    href: '/domains',
    icon: Search,
    tagline: 'Search, buy, transfer, and manage domains.',
  },
  firewall: {
    id: 'firewall',
    name: PRODUCT_REGISTRY.firewall.name,
    group: 'protect',
    href: PRODUCT_REGISTRY.firewall.path,
    icon: PRODUCT_REGISTRY.firewall.icon,
    tagline: PRODUCT_REGISTRY.firewall.tagline,
  },
  advisor: {
    id: 'advisor',
    name: 'Advisor',
    group: 'protect',
    href: '/docs/products/network',
    icon: ScanSearch,
    tagline: 'Security and performance insights.',
    comingSoon: true,
  },
}

export function isProductNavItemComingSoon(id: ProductNavItemId): boolean {
  return PRODUCT_NAV_REGISTRY[id].comingSoon === true
}

export const BUILD_NAV_ITEM_IDS = PRODUCT_NAV_ITEM_IDS.filter(
  (id) => PRODUCT_NAV_REGISTRY[id].group === 'build',
)

export const DEPLOY_NAV_ITEM_IDS = PRODUCT_NAV_ITEM_IDS.filter(
  (id) => PRODUCT_NAV_REGISTRY[id].group === 'deploy',
)

export const PROTECT_NAV_ITEM_IDS = PRODUCT_NAV_ITEM_IDS.filter(
  (id) => PRODUCT_NAV_REGISTRY[id].group === 'protect',
)

export type ProductNavCategory = {
  id: ProductNavItem['group']
  label: string
  productIds: readonly ProductNavItemId[]
}

export const PRODUCT_NAV_CATEGORIES = [
  {
    id: 'build',
    label: 'Build',
    productIds: BUILD_NAV_ITEM_IDS,
  },
  {
    id: 'deploy',
    label: 'Deploy',
    productIds: DEPLOY_NAV_ITEM_IDS,
  },
  {
    id: 'protect',
    label: 'Protect',
    productIds: PROTECT_NAV_ITEM_IDS,
  },
] as const satisfies readonly ProductNavCategory[]

export const MARKETING_PRODUCT_NAV_CATEGORIES = PRODUCT_NAV_CATEGORIES.map(
  (category) => ({
    ...category,
    productIds: category.productIds.filter((id) => !isProductNavItemComingSoon(id)),
  }),
)

export function isMarketingProductMenuNavItem(
  id: ProductNavItemId,
  options: { agent: boolean },
): boolean {
  if (isProductNavItemComingSoon(id)) return false
  if (id === 'agent') return options.agent
  return true
}

export function getMarketingProductMenuCategories(options: {
  agent: boolean
}): ProductNavCategory[] {
  return MARKETING_PRODUCT_NAV_CATEGORIES.map((category) => ({
    ...category,
    productIds: category.productIds.filter((id) =>
      isMarketingProductMenuNavItem(id, options),
    ),
  })).filter((category) => category.productIds.length > 0)
}

/** Product pages plus Domains for the “Explore Appwrite” grid on /products/*. */
export const PRODUCT_PAGE_EXPLORE_NAV_ITEM_IDS = [
  'auth',
  'databases',
  'postgres',
  'storage',
  'functions',
  'messaging',
  'realtime',
  'sites',
  'domains',
  'firewall',
] as const satisfies readonly ProductNavItemId[]

export function isProductNavItemId(value: string): value is ProductNavItemId {
  return (PRODUCT_NAV_ITEM_IDS as readonly string[]).includes(value)
}

export function isProductId(value: string): value is ProductId {
  return (PRODUCT_IDS as readonly string[]).includes(value)
}

export function getProductPath(id: ProductId): `/products/${ProductId}` {
  return PRODUCT_REGISTRY[id].path
}
