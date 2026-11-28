import type { ComponentType } from 'react'
import { AnalyticsHeroArt } from '@/components/pages/products/hero-art/AnalyticsHeroArt'
import { AuthHeroArt } from '@/components/pages/products/hero-art/AuthHeroArt'
import { BeepHeroArt } from '@/components/pages/products/hero-art/BeepHeroArt'
import { DatabasesHeroArt } from '@/components/pages/products/hero-art/DatabasesHeroArt'
import { FirewallHeroArt } from '@/components/pages/products/hero-art/FirewallHeroArt'
import { FunctionsHeroArt } from '@/components/pages/products/hero-art/FunctionsHeroArt'
import { FunctionsHeroRuntimes } from '@/components/pages/products/hero-art/FunctionsHeroRuntimes'
import { MessagingHeroArt } from '@/components/pages/products/hero-art/MessagingHeroArt'
import { MonitorsHeroArt } from '@/components/pages/products/hero-art/MonitorsHeroArt'
import { PostgresHeroArt } from '@/components/pages/products/hero-art/PostgresHeroArt'
import { RealtimeHeroArt } from '@/components/pages/products/hero-art/RealtimeHeroArt'
import { ReviewsHeroArt } from '@/components/pages/products/hero-art/ReviewsHeroArt'
import { SitesHeroArt } from '@/components/pages/products/hero-art/SitesHeroArt'
import { StorageHeroArt } from '@/components/pages/products/hero-art/StorageHeroArt'
import { VideosHeroArt } from '@/components/pages/products/hero-art/VideosHeroArt'
import type { ProductId } from '@/lib/products/types'

/** Animated centerpiece for each product hero (beside the copy or below it, per `PRODUCT_THEMES`). */
export const PRODUCT_HERO_ART: Record<ProductId, ComponentType> = {
  auth: AuthHeroArt,
  databases: DatabasesHeroArt,
  postgres: PostgresHeroArt,
  storage: StorageHeroArt,
  functions: FunctionsHeroArt,
  messaging: MessagingHeroArt,
  videos: VideosHeroArt,
  realtime: RealtimeHeroArt,
  sites: SitesHeroArt,
  analytics: AnalyticsHeroArt,
  monitors: MonitorsHeroArt,
  beep: BeepHeroArt,
  firewall: FirewallHeroArt,
  reviews: ReviewsHeroArt,
}

/** Decorative layer scattered around the hero copy and art on large screens. */
export const PRODUCT_HERO_SCATTER: Partial<Record<ProductId, ComponentType>> = {
  functions: FunctionsHeroRuntimes,
}
