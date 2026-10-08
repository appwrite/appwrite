import type { LucideIcon } from 'lucide-react'
import {
  BarChart3,
  CreditCard,
  HardDrive,
  MessageSquare,
  Shield,
  Sparkles,
  Wrench,
} from 'lucide-react'

export type MarketplaceAppCategory =
  | 'auth'
  | 'storage'
  | 'analytics'
  | 'payments'
  | 'ai'
  | 'devtools'
  | 'messaging'

export type MarketplaceAppStatus = 'published' | 'draft'

export type MarketplaceApp = {
  $id: string
  name: string
  slug: string
  description: string
  shortDescription: string
  category: MarketplaceAppCategory
  author: string
  featured: boolean
  /** Curated position from a `rank:N` tag; lower ranks list first, unranked apps last. */
  rank?: number
  /** Published by Appwrite. */
  isOfficial: boolean
  /** Reviewed and trusted by Appwrite. */
  isVerified: boolean
  /** Curated into the Explore "Suggested" section by Appwrite. */
  isSuggested: boolean
  isOwned: boolean
  status: MarketplaceAppStatus
  tags: string[]
  $createdAt: string
  $updatedAt?: string
  logoUri?: string
  clientUri?: string
  redirectUris?: string[]
  postLogoutRedirectUris?: string[]
  privacyPolicyUrl?: string
  termsUrl?: string
  supportUrl?: string
  dataDeletionUrl?: string
  images?: string[]
  contacts?: string[]
  type?: string
  deviceFlow?: boolean
  teamId?: string
  /** Empty when the app does not allow installation. */
  installationScopes: string[]
}

export const MARKETPLACE_CATEGORY_LABELS: Record<
  MarketplaceAppCategory,
  string
> = {
  auth: 'Authentication',
  storage: 'Storage',
  analytics: 'Analytics',
  payments: 'Payments',
  ai: 'AI & ML',
  devtools: 'Developer tools',
  messaging: 'Messaging',
}

export const MARKETPLACE_CATEGORY_ICONS: Record<
  MarketplaceAppCategory,
  LucideIcon
> = {
  auth: Shield,
  storage: HardDrive,
  analytics: BarChart3,
  payments: CreditCard,
  ai: Sparkles,
  devtools: Wrench,
  messaging: MessageSquare,
}

/** Appwrite list APIs cap `total` at 5000. */
export const MARKETPLACE_TOTAL_CAP = 5000

/** Display form of a list total: caps at "5000+". */
export function formatMarketplaceCount(total: number): string {
  return total >= MARKETPLACE_TOTAL_CAP
    ? `${MARKETPLACE_TOTAL_CAP}+`
    : String(total)
}

export const MARKETPLACE_CATEGORY_ORDER: MarketplaceAppCategory[] = [
  'auth',
  'storage',
  'analytics',
  'payments',
  'ai',
  'messaging',
  'devtools',
]

const CATEGORY_TAG_SET = new Set<string>(MARKETPLACE_CATEGORY_ORDER)

export function buildMarketplaceAppTags(
  category: MarketplaceAppCategory,
  existingTags: string[],
): string[] {
  const preserved = existingTags.filter((tag) => {
    const normalized = tag.trim().toLowerCase()
    return !CATEGORY_TAG_SET.has(normalized)
  })
  return [category, ...preserved]
}
