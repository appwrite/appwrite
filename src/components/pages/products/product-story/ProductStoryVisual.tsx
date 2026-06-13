import type { ComponentType } from 'react'
import { AuthStory } from '@/components/pages/products/product-story/stories/AuthStory'
import { DatabasesStory } from '@/components/pages/products/product-story/stories/DatabasesStory'
import { FunctionsStory } from '@/components/pages/products/product-story/stories/FunctionsStory'
import { MessagingStory } from '@/components/pages/products/product-story/stories/MessagingStory'
import { SitesStory } from '@/components/pages/products/product-story/stories/SitesStory'
import { StorageStory } from '@/components/pages/products/product-story/stories/StorageStory'
import type { ProductId } from '@/lib/products/types'

const STORY_BY_PRODUCT: Record<ProductId, ComponentType> = {
  auth: AuthStory,
  databases: DatabasesStory,
  storage: StorageStory,
  functions: FunctionsStory,
  messaging: MessagingStory,
  sites: SitesStory,
}

type ProductStoryVisualProps = {
  productId: ProductId
}

export function ProductStoryVisual({ productId }: ProductStoryVisualProps) {
  const Story = STORY_BY_PRODUCT[productId]
  return <Story />
}
