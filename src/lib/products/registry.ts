import {
  Database,
  Folder,
  Globe,
  MessageSquare,
  Users,
  Zap,
} from 'lucide-react'
import type { ProductId, ProductRegistryItem } from '@/lib/products/types'

export const PRODUCT_IDS = [
  'auth',
  'databases',
  'storage',
  'functions',
  'messaging',
  'sites',
] as const satisfies readonly ProductId[]

export const PRODUCT_REGISTRY: Record<ProductId, ProductRegistryItem> = {
  auth: {
    id: 'auth',
    name: 'Auth',
    group: 'build',
    path: '/products/auth',
    icon: Users,
    tagline: 'Secure sign-in with email, OAuth, SMS, and more.',
    docsPath: '/docs/products/auth',
  },
  databases: {
    id: 'databases',
    name: 'Databases',
    group: 'build',
    path: '/products/databases',
    icon: Database,
    tagline: 'Structured data with TablesDB, Postgres, and MySQL.',
    docsPath: '/docs/products/databases',
  },
  storage: {
    id: 'storage',
    name: 'Storage',
    group: 'build',
    path: '/products/storage',
    icon: Folder,
    tagline: 'Store, transform, and deliver files at scale.',
    docsPath: '/docs/products/storage',
  },
  functions: {
    id: 'functions',
    name: 'Functions',
    group: 'build',
    path: '/products/functions',
    icon: Zap,
    tagline: 'Serverless logic with isolated runtimes and events.',
    docsPath: '/docs/products/functions',
  },
  messaging: {
    id: 'messaging',
    name: 'Messaging',
    group: 'build',
    path: '/products/messaging',
    icon: MessageSquare,
    tagline: 'Email, SMS, and push from one unified API.',
    docsPath: '/docs/products/messaging',
  },
  sites: {
    id: 'sites',
    name: 'Sites',
    group: 'deploy',
    path: '/products/sites',
    icon: Globe,
    tagline: 'Deploy static, SSR, and CSR apps from Git.',
    docsPath: '/docs/products/sites',
  },
}

export const BUILD_PRODUCT_IDS = PRODUCT_IDS.filter(
  (id) => PRODUCT_REGISTRY[id].group === 'build',
)

export const DEPLOY_PRODUCT_IDS = PRODUCT_IDS.filter(
  (id) => PRODUCT_REGISTRY[id].group === 'deploy',
)

export function isProductId(value: string): value is ProductId {
  return (PRODUCT_IDS as readonly string[]).includes(value)
}

export function getProductPath(id: ProductId): `/products/${ProductId}` {
  return PRODUCT_REGISTRY[id].path
}
