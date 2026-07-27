import { getBaseEndpoint } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import type { LucideIcon } from 'lucide-react'
import {
  Users,
  UsersRound,
  Database,
  Zap,
  Folder,
  MessageSquare,
  Globe,
  Boxes,
  Globe2,
  MoreHorizontal,
  ScanSearch,
  Network,
  Plug2,
} from 'lucide-react'

export function isCloudEnvironment(): boolean {
  try {
    return getBaseEndpoint().includes('cloud.appwrite.io')
  } catch {
    return false
  }
}

/** Project OAuth2 client app scopes (Auth → OAuth2 server → Apps). */
export function isOAuth2AppsCatalogScope(
  scopeId: string,
  apiCategory?: string,
): boolean {
  if (scopeId.toLowerCase().startsWith('apps.')) return true
  return (apiCategory ?? '').trim().toLowerCase() === 'apps'
}

/** Scopes only available on Appwrite Cloud (e.g. backups). */
export const CLOUD_ONLY_SCOPE_IDS = new Set([
  'policies.read',
  'policies.write',
  'archives.read',
  'archives.write',
  'restorations.read',
  'restorations.write',
])

/**
 * Renamed legacy scope ids omitted from the catalog unless the API key already
 * includes them. There is no mapping to modern scope ids - the key stores and
 * toggles the exact scope string.
 */
export const LEGACY_CATALOG_ONLY_WHEN_ON_KEY = new Set([
  'collections.read',
  'collections.write',
  'attributes.read',
  'attributes.write',
  'documents.read',
  'documents.write',
  'execution.read',
  'execution.write',
])

/**
 * Whether to show the Deprecated badge for a catalog row (API `deprecated` or
 * deprecated category group from the server).
 */
export function scopeRowDeprecated(
  selfDeprecated: boolean | undefined,
  apiCategoryIsDeprecatedGroup: boolean,
): boolean {
  return Boolean(selfDeprecated) || apiCategoryIsDeprecatedGroup
}

/**
 * Accordion grouping category for the scope editor. Deprecated scopes stay in
 * their service group (e.g. Database); API category "Deprecated" is mapped from
 * the scope id. Empty/missing API category falls back to the same inference.
 */
function inferAccordionCategoryFromScopeId(scopeId: string): string {
  const id = scopeId.toLowerCase()
  if (/^(users|teams|sessions)\./.test(id)) return 'Auth'
  if (/^domains\./.test(id)) return 'Domains'
  if (
    /^(databases|tables|columns|rows|indexes|collections|attributes|documents|archives|restorations)\./.test(
      id,
    )
  ) {
    return 'Databases'
  }
  if (/^(functions|executions|execution)\./.test(id)) return 'Functions'
  if (/^(files|buckets|tokens)\./.test(id)) return 'Storage'
  if (/^(messages|topics|subscribers|targets|providers)\./.test(id)) {
    return 'Messaging'
  }
  if (/^(sites|log)\./.test(id)) return 'Sites'
  if (/^presences\./.test(id)) return 'Presences'
  if (/^apps\./.test(id)) return 'Apps'
  if (
    /^(projects|platforms|keys|webhooks|mocks|templates|oauth2|events|policies)\./.test(
      id,
    )
  ) {
    return 'Project'
  }
  if (/^advisor\./.test(id)) return 'Advisor'
  if (/^proxy\./.test(id)) return 'Proxy'
  return 'General'
}

export function scopeEditorCategory(
  scopeId: string,
  apiCategory: string | undefined,
): string {
  const raw = (apiCategory ?? '').trim()
  if (raw.toLowerCase() === 'deprecated') {
    return inferAccordionCategoryFromScopeId(scopeId)
  }
  if (raw) return raw
  return inferAccordionCategoryFromScopeId(scopeId)
}

const KNOWN_CATEGORY_ORDER = [
  'Auth',
  'Database',
  'Databases',
  'Functions',
  'Storage',
  'Messaging',
  'Sites',
  'Presences',
  'Domains',
  'Project',
  'Advisor',
  'Proxy',
  'General',
]

const KNOWN_CATEGORY_ORDER_SET = new Set<string>(KNOWN_CATEGORY_ORDER)

export function sortScopeCategories(categories: Iterable<string>): string[] {
  const set = new Set(categories)
  const ranked = KNOWN_CATEGORY_ORDER.filter((c) => set.has(c))
  const rest = Array.from(set)
    .filter(
      (c) => !KNOWN_CATEGORY_ORDER_SET.has(c) && c !== 'Other',
    )
    .sort((a, b) => a.localeCompare(b))
  return set.has('Other') ? [...ranked, ...rest, 'Other'] : [...ranked, ...rest]
}

export function getScopeCategoryIcon(
  category: string,
  scopeId?: string,
): LucideIcon {
  const c = category.toLowerCase()
  if (
    c.includes('auth') ||
    c.includes('user') ||
    c.includes('session') ||
    c.includes('team')
  ) {
    return Users
  }
  if (c.includes('domain')) {
    return Globe2
  }
  if (c.includes('project')) {
    return Boxes
  }
  if (
    c.includes('database') ||
    c.includes('table') ||
    c.includes('column') ||
    c.includes('row') ||
    c.includes('index') ||
    c.includes('document') ||
    c.includes('collection') ||
    c.includes('attribute') ||
    c.includes('backup') ||
    c.includes('policy') ||
    c.includes('archive') ||
    c.includes('restoration')
  ) {
    return Database
  }
  if (c.includes('function') || c.includes('execution')) {
    return Zap
  }
  if (
    c.includes('storage') ||
    c.includes('bucket') ||
    c.includes('file') ||
    c.includes('token')
  ) {
    return Folder
  }
  if (c.includes('message') || c.includes('topic') || c.includes('provider')) {
    return MessageSquare
  }
  if (c.includes('site') || c.includes('log')) {
    return Globe
  }
  if (c.includes('presence')) {
    return UsersRound
  }
  if (c === 'apps') {
    return Plug2
  }
  if (c.includes('advisor')) {
    return ScanSearch
  }
  if (c.includes('proxy')) {
    return Network
  }
  const id = scopeId?.toLowerCase() ?? ''
  if (id) {
    if (/^(users|teams|sessions)\./.test(id)) return Users
    if (/^domains\./.test(id)) return Globe2
    if (
      /^(projects|platforms|keys|webhooks|mocks|templates|oauth2|events|policies)\./.test(
        id,
      )
    ) {
      return Boxes
    }
    if (
      /^(databases|tables|columns|rows|indexes|collections|attributes|documents|archives|restorations)\./.test(
        id,
      )
    ) {
      return Database
    }
    if (/^(functions|executions|execution)\./.test(id)) return Zap
    if (/^(files|buckets|tokens)\./.test(id)) return Folder
    if (
      /^(messages|topics|subscribers|targets|providers)\./.test(id)
    ) {
      return MessageSquare
    }
    if (/^(sites|log)\./.test(id)) return Globe
    if (/^presences\./.test(id)) return UsersRound
    if (/^apps\./.test(id)) return Plug2
    if (/^advisor\./.test(id)) return ScanSearch
    if (/^proxy\./.test(id)) return Network
  }
  return MoreHorizontal
}

export function buildAllAvailableScopeIds(
  list: Models.ConsoleKeyScopeList | undefined,
  opts: { isCloud: boolean; oauth2Server: boolean },
): string[] {
  if (!list?.scopes?.length) return []
  const out: string[] = []
  for (const s of list.scopes) {
    if (s.deprecated) continue
    if (CLOUD_ONLY_SCOPE_IDS.has(s.$id) && !opts.isCloud) continue
    if (isOAuth2AppsCatalogScope(s.$id, s.category) && !opts.oauth2Server) continue
    out.push(s.$id)
  }
  return out
}

export type ScopeEditorRow = {
  scope: string
  description: string
  category: string
  icon: LucideIcon
  /** When true, show the Deprecated badge (catalog `deprecated` only). */
  deprecated?: boolean
}

export function isOAuth2AppsScopeEditorRow(
  row: Pick<ScopeEditorRow, 'scope' | 'category'>,
): boolean {
  return row.category === 'Apps' || isOAuth2AppsCatalogScope(row.scope)
}

/** Within a category: non-deprecated first, then alphabetical by scope id. */
export function compareScopeRowsDeprecatedLast(
  a: Pick<ScopeEditorRow, 'scope' | 'deprecated'>,
  b: Pick<ScopeEditorRow, 'scope' | 'deprecated'>,
): number {
  const dep = Number(Boolean(a.deprecated)) - Number(Boolean(b.deprecated))
  if (dep !== 0) return dep
  return a.scope.localeCompare(b.scope)
}

export function compareScopeEditorRowsForDisplay(
  a: ScopeEditorRow,
  b: ScopeEditorRow,
): number {
  const cat = a.category.localeCompare(b.category)
  if (cat !== 0) return cat
  return compareScopeRowsDeprecatedLast(a, b)
}

export function scopeEditorRowMatchesQuery(
  row: Pick<ScopeEditorRow, 'scope' | 'description' | 'category'>,
  query: string,
): boolean {
  const normalized = query.trim().toLowerCase()
  if (!normalized) return true

  return (
    row.scope.toLowerCase().includes(normalized) ||
    row.description.toLowerCase().includes(normalized) ||
    row.category.toLowerCase().includes(normalized)
  )
}

export function filterScopeEditorRows(
  rows: ScopeEditorRow[],
  query: string,
): ScopeEditorRow[] {
  const normalized = query.trim()
  if (!normalized) return rows
  return rows.filter((row) => scopeEditorRowMatchesQuery(row, normalized))
}

export function consoleKeyScopesToEditorRows(
  list: Models.ConsoleKeyScopeList | undefined,
  opts: {
    isCloud: boolean
    oauth2Server: boolean
    selectedScopeIds?: readonly string[]
  },
): ScopeEditorRow[] {
  if (!list?.scopes?.length) return []
  const selected = new Set(opts.selectedScopeIds ?? [])
  return list.scopes
    .filter(
      (s) => !CLOUD_ONLY_SCOPE_IDS.has(s.$id) || opts.isCloud,
    )
    .filter(
      (s) =>
        !isOAuth2AppsCatalogScope(s.$id, s.category) || opts.oauth2Server,
    )
    .filter((s) => {
      if (!LEGACY_CATALOG_ONLY_WHEN_ON_KEY.has(s.$id)) return true
      return selected.has(s.$id)
    })
    .map((s) => {
      const sourceCategory = s.category || 'Other'
      const categoryLooksDeprecated =
        sourceCategory.trim().toLowerCase() === 'deprecated'
      const deprecatedBadge = scopeRowDeprecated(
        s.deprecated,
        categoryLooksDeprecated,
      )
      const accordionCategory = scopeEditorCategory(s.$id, s.category)
      return {
        scope: s.$id,
        description: s.description,
        category: accordionCategory,
        icon: getScopeCategoryIcon(accordionCategory, s.$id),
        deprecated: deprecatedBadge,
      }
    })
    .sort(compareScopeEditorRowsForDisplay)
}
