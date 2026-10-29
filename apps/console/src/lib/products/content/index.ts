import { analyticsProductContent } from '@/lib/products/content/analytics'
import { authProductContent } from '@/lib/products/content/auth'
import { databasesProductContent } from '@/lib/products/content/databases'
import { firewallProductContent } from '@/lib/products/content/firewall'
import { functionsProductContent } from '@/lib/products/content/functions'
import { messagingProductContent } from '@/lib/products/content/messaging'
import { postgresProductContent } from '@/lib/products/content/postgres'
import { realtimeProductContent } from '@/lib/products/content/realtime'
import { sitesProductContent } from '@/lib/products/content/sites'
import { storageProductContent } from '@/lib/products/content/storage'
import type { ProductId, ProductPageContent } from '@/lib/products/types'

const PRODUCT_CONTENT: Record<ProductId, ProductPageContent> = {
  auth: authProductContent,
  databases: databasesProductContent,
  postgres: postgresProductContent,
  storage: storageProductContent,
  functions: functionsProductContent,
  messaging: messagingProductContent,
  realtime: realtimeProductContent,
  sites: sitesProductContent,
  analytics: analyticsProductContent,
  firewall: firewallProductContent,
}

export function getProductContent(id: ProductId): ProductPageContent {
  return PRODUCT_CONTENT[id]
}
