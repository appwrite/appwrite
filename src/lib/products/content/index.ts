import { authProductContent } from '@/lib/products/content/auth'
import { databasesProductContent } from '@/lib/products/content/databases'
import { firewallProductContent } from '@/lib/products/content/firewall'
import { functionsProductContent } from '@/lib/products/content/functions'
import { messagingProductContent } from '@/lib/products/content/messaging'
import { sitesProductContent } from '@/lib/products/content/sites'
import { storageProductContent } from '@/lib/products/content/storage'
import type { ProductId, ProductPageContent } from '@/lib/products/types'

const PRODUCT_CONTENT: Record<ProductId, ProductPageContent> = {
  auth: authProductContent,
  databases: databasesProductContent,
  storage: storageProductContent,
  functions: functionsProductContent,
  messaging: messagingProductContent,
  sites: sitesProductContent,
  firewall: firewallProductContent,
}

export function getProductContent(id: ProductId): ProductPageContent {
  return PRODUCT_CONTENT[id]
}
