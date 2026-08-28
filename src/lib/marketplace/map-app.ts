import type { Models } from '@appwrite.io/console'
import {
  MARKETPLACE_CATEGORY_ORDER,
  type MarketplaceApp,
  type MarketplaceAppCategory,
  type MarketplaceAppCreator,
} from './types'

const CATEGORY_SET = new Set<string>(MARKETPLACE_CATEGORY_ORDER)

function normalizeTag(tag: string): string {
  return tag.trim().toLowerCase()
}

function hasTag(tags: string[], value: string): boolean {
  const needle = normalizeTag(value)
  return tags.some((tag) => normalizeTag(tag) === needle)
}

function resolveCategory(tags: string[]): MarketplaceAppCategory {
  for (const tag of tags) {
    const normalized = normalizeTag(tag)
    if (CATEGORY_SET.has(normalized)) {
      return normalized as MarketplaceAppCategory
    }
  }
  return 'devtools'
}

const RANK_TAG_PATTERN = /^rank:(\d+)$/

function resolveRank(tags: string[]): number | undefined {
  for (const tag of tags) {
    const match = normalizeTag(tag).match(RANK_TAG_PATTERN)
    if (match) return Number(match[1])
  }
  return undefined
}

function resolveCreators(contacts: string[]): MarketplaceAppCreator[] {
  if (!contacts.length) return []
  return contacts.map((contact) => {
    const trimmed = contact.trim()
    if (!trimmed) return { name: 'Contact' }
    if (trimmed.includes('@')) {
      const local = trimmed.split('@')[0] ?? trimmed
      const name = local.replace(/[._-]+/g, ' ').trim() || trimmed
      return { name, role: 'Contact' }
    }
    return { name: trimmed }
  })
}

export function mapAppToMarketplaceApp(
  app: Models.App,
  options: {
    organizationId: string
    teamNamesById?: Record<string, string>
    authorOverride?: string
  },
): MarketplaceApp {
  const tags = app.tags ?? []
  const isOwned = app.teamId === options.organizationId
  const author =
    options.authorOverride ??
    (app.teamId
      ? (options.teamNamesById?.[app.teamId] ?? 'Community')
      : 'Community')

  return {
    $id: app.$id,
    name: app.name,
    slug: app.$id,
    description: app.description?.trim() || app.tagline?.trim() || app.name,
    shortDescription: app.tagline?.trim() || app.description?.trim() || app.name,
    category: resolveCategory(tags),
    author: isOwned ? 'Your organization' : author,
    creators: resolveCreators(app.contacts ?? []),
    featured: hasTag(tags, 'featured'),
    rank: resolveRank(tags),
    isOfficial: hasTag(tags, 'official'),
    isVerified: hasTag(tags, 'verified'),
    isOwned,
    status: app.enabled ? 'published' : 'draft',
    tags,
    $createdAt: app.$createdAt,
    $updatedAt: app.$updatedAt,
    logoUri: app.logoUri || undefined,
    clientUri: app.clientUri || undefined,
    redirectUris: app.redirectUris ?? [],
    postLogoutRedirectUris: app.postLogoutRedirectUris ?? [],
    privacyPolicyUrl: app.privacyPolicyUrl || undefined,
    termsUrl: app.termsUrl || undefined,
    supportUrl: app.supportUrl || undefined,
    dataDeletionUrl: app.dataDeletionUrl || undefined,
    images: app.images ?? [],
    contacts: app.contacts ?? [],
    type: app.type || undefined,
    deviceFlow: app.deviceFlow,
    teamId: app.teamId || undefined,
  }
}

/**
 * Curated catalog order: `rank:N`-tagged apps first (ascending), unranked
 * apps after, alphabetical within ties. Sections filtered from a sorted
 * list (featured, categories, search) keep this order.
 */
export function sortMarketplaceApps(apps: MarketplaceApp[]): MarketplaceApp[] {
  return [...apps].sort((a, b) => {
    const rankA = a.rank ?? Number.POSITIVE_INFINITY
    const rankB = b.rank ?? Number.POSITIVE_INFINITY
    if (rankA !== rankB) return rankA - rankB
    return a.name.localeCompare(b.name)
  })
}

export function mapAppsToMarketplaceApps(
  apps: Models.App[],
  options: {
    organizationId: string
    teamNamesById?: Record<string, string>
  },
): MarketplaceApp[] {
  return apps.map((app) => mapAppToMarketplaceApp(app, options))
}
