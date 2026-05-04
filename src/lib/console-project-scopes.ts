import { getBaseEndpoint } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import type { LucideIcon } from 'lucide-react'
import {
  Users,
  Database,
  Zap,
  Folder,
  MessageSquare,
  Globe,
  Boxes,
  Globe2,
  MoreHorizontal,
} from 'lucide-react'

export function isCloudEnvironment(): boolean {
  try {
    return getBaseEndpoint().includes('cloud.appwrite.io')
  } catch {
    return false
  }
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

/** Map legacy scope ids to their modern equivalents (for display / toggling). */
export const LEGACY_SCOPE_MAP: Record<string, string> = {
  'collections.read': 'tables.read',
  'collections.write': 'tables.write',
  'attributes.read': 'columns.read',
  'attributes.write': 'columns.write',
  'documents.read': 'rows.read',
  'documents.write': 'rows.write',
  /** Older API keys used singular `execution.*`; catalog uses `executions.*`. */
  'execution.read': 'executions.read',
  'execution.write': 'executions.write',
}

/** For a modern scope id, all API key values that should toggle together with it. */
export const LEGACY_SCOPE_ALIASES: Record<string, string[]> = {
  'tables.read': ['collections.read'],
  'tables.write': ['collections.write'],
  'columns.read': ['attributes.read'],
  'columns.write': ['attributes.write'],
  'rows.read': ['documents.read'],
  'rows.write': ['documents.write'],
  'executions.read': ['execution.read'],
  'executions.write': ['execution.write'],
}

export function normalizeScopeForDisplay(scope: string): string {
  return LEGACY_SCOPE_MAP[scope] || scope
}

export function shouldDisplayScope(scope: string): boolean {
  return !Object.keys(LEGACY_SCOPE_MAP).includes(scope)
}

export function getScopeVariants(primaryScope: string): string[] {
  return [primaryScope, ...(LEGACY_SCOPE_ALIASES[primaryScope] ?? [])]
}

/**
 * Whether to show the Deprecated badge for a catalog row. Uses the API
 * `deprecated` flag on that scope only (legacy rows like collections.* are
 * hidden from the list but stay false on tables.* / rows.*).
 */
export function scopeRowDeprecated(
  selfDeprecated: boolean | undefined,
  apiCategoryIsDeprecatedGroup: boolean,
): boolean {
  return Boolean(selfDeprecated) || apiCategoryIsDeprecatedGroup
}

/** Sort / badge: API deprecated or key still stores legacy names for this row. */
export function scopeEditorRowIsDeprioritized(
  row: Pick<ScopeEditorRow, 'deprecated' | 'legacyAliasesOnKey'>,
): boolean {
  return (
    Boolean(row.deprecated) || (row.legacyAliasesOnKey?.length ?? 0) > 0
  )
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
  if (/^(functions|executions)\./.test(id)) return 'Functions'
  if (/^(files|buckets|tokens)\./.test(id)) return 'Storage'
  if (/^(messages|topics|subscribers|targets|providers)\./.test(id)) {
    return 'Messaging'
  }
  if (/^(sites|log)\./.test(id)) return 'Sites'
  if (
    /^(projects|platforms|keys|webhooks|mocks|templates|oauth2|events|policies)\./.test(
      id,
    )
  ) {
    return 'Project'
  }
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
  'Domains',
  'Project',
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
    if (/^(functions|executions)\./.test(id)) return Zap
    if (/^(files|buckets|tokens)\./.test(id)) return Folder
    if (
      /^(messages|topics|subscribers|targets|providers)\./.test(id)
    ) {
      return MessageSquare
    }
    if (/^(sites|log)\./.test(id)) return Globe
  }
  return MoreHorizontal
}

export function buildAllAvailableScopeIds(
  list: Models.ConsoleKeyScopeList | undefined,
  isCloud: boolean,
): string[] {
  if (!list?.scopes?.length) return []
  const out = new Set<string>()
  for (const s of list.scopes) {
    if (s.deprecated) continue
    if (CLOUD_ONLY_SCOPE_IDS.has(s.$id) && !isCloud) continue
    getScopeVariants(s.$id).forEach((v) => out.add(v))
  }
  return Array.from(out)
}

export type ScopeEditorRow = {
  scope: string
  description: string
  category: string
  icon: LucideIcon
  /** When true, show the Deprecated badge (from API `deprecated` on this row). */
  deprecated?: boolean
  /**
   * Legacy scope ids still present on the API key (e.g. `collections.read`) that
   * map to this catalog row. Shown so deprecated names on the key are visible.
   */
  legacyAliasesOnKey?: string[]
}

/** Within a category: non-deprecated first, then alphabetical by scope id. */
export function compareScopeRowsDeprecatedLast(
  a: Pick<ScopeEditorRow, 'scope' | 'deprecated' | 'legacyAliasesOnKey'>,
  b: Pick<ScopeEditorRow, 'scope' | 'deprecated' | 'legacyAliasesOnKey'>,
): number {
  const dep =
    Number(scopeEditorRowIsDeprioritized(a)) -
    Number(scopeEditorRowIsDeprioritized(b))
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

export function consoleKeyScopesToEditorRows(
  list: Models.ConsoleKeyScopeList | undefined,
  opts: { isCloud: boolean },
): ScopeEditorRow[] {
  if (!list?.scopes?.length) return []
  return list.scopes
    .filter(
      (s) => !CLOUD_ONLY_SCOPE_IDS.has(s.$id) || opts.isCloud,
    )
    .filter((s) => shouldDisplayScope(s.$id))
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
