import type { Models } from '@appwrite.io/console'
import {
  MARKETPLACE_CATEGORY_ORDER,
  type MarketplaceApp,
  type MarketplaceAppCategory,
} from './types'

const CATEGORY_SET = new Set<string>(MARKETPLACE_CATEGORY_ORDER)

function normalizeTag(tag: string): string {
  return tag.trim().toLowerCase()
}

function hasTag(tags: string[], value: string): boolean {
  const needle = normalizeTag(value)
  return tags.some((tag) => normalizeTag(tag) === needle)
}

/** Appwrite-curated marketplace catalog entries (Explore / Catalog). */
export function isOfficialMarketplaceApp(
  app: Models.App | null | undefined,
): boolean {
  if (!app) return false
  return hasTag(app.labels ?? [], 'official')
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

export function mapAppToMarketplaceApp(
  app: Models.App,
  options: {
    organizationId: string
    teamNamesById?: Record<string, string>
    authorOverride?: string
  },
): MarketplaceApp {
  const tags = app.tags ?? []
  // Curation markers (official/verified/featured/rank) live in labels, which
  // only Appwrite can set — tags are editable by the app owner.
  const labels = app.labels ?? []
  const isOwned = app.teamId === options.organizationId
  const isOfficial = hasTag(labels, 'official')
  const author =
    options.authorOverride ??
    (isOfficial
      ? 'Appwrite'
      : app.teamId
        ? (options.teamNamesById?.[app.teamId] ?? 'Community')
        : 'Community')

  return {
    $id: app.$id,
    name: app.name,
    slug: app.$id,
    description: app.description?.trim() || app.tagline?.trim() || app.name,
    shortDescription: app.tagline?.trim() || app.description?.trim() || app.name,
    category: resolveCategory(tags),
    author,
    featured: hasTag(labels, 'featured'),
    rank: resolveRank(labels),
    isOfficial,
    isVerified: hasTag(labels, 'verified'),
    isSuggested: hasTag(labels, 'suggested'),
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
    installationScopes: app.installationScopes ?? [],
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
