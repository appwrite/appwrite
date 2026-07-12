import type { Models } from '@appwrite.io/console'

export type PostgresExtensionCatalogEntry = Pick<
  Models.PostgresExtension,
  'key' | 'name' | 'description' | 'category'
>

/** Known PostgreSQL extensions for display metadata when the API returns keys only. */
export const POSTGRES_EXTENSION_CATALOG: PostgresExtensionCatalogEntry[] = [
  {
    key: 'pgvector',
    name: 'pgvector',
    description: 'Vector similarity search for embeddings and AI workloads.',
    category: 'AI',
  },
  {
    key: 'postgis',
    name: 'PostGIS',
    description: 'Geospatial types, indexes, and functions for location data.',
    category: 'Geospatial',
  },
  {
    key: 'uuid-ossp',
    name: 'uuid-ossp',
    description: 'Generate universally unique identifiers (UUIDs).',
    category: 'Utility',
  },
  {
    key: 'pg_trgm',
    name: 'pg_trgm',
    description: 'Trigram-based text similarity and fuzzy search.',
    category: 'Search',
  },
  {
    key: 'citext',
    name: 'citext',
    description: 'Case-insensitive character string type.',
    category: 'Data types',
  },
  {
    key: 'hstore',
    name: 'hstore',
    description: 'Key-value store inside a single column.',
    category: 'Data types',
  },
  {
    key: 'pgcrypto',
    name: 'pgcrypto',
    description: 'Cryptographic functions for hashing and encryption.',
    category: 'Security',
  },
  {
    key: 'btree_gin',
    name: 'btree_gin',
    description: 'B-tree equivalent operators for GIN indexes.',
    category: 'Indexing',
  },
  {
    key: 'btree_gist',
    name: 'btree_gist',
    description: 'B-tree equivalent operators for GiST indexes.',
    category: 'Indexing',
  },
  {
    key: 'pg_stat_statements',
    name: 'pg_stat_statements',
    description: 'Track planning and execution statistics for SQL statements.',
    category: 'Monitoring',
  },
  {
    key: 'pg_cron',
    name: 'pg_cron',
    description: 'Schedule PostgreSQL commands using cron syntax.',
    category: 'Scheduling',
  },
  {
    key: 'timescaledb',
    name: 'TimescaleDB',
    description: 'Time-series data hypertables and analytics.',
    category: 'Time series',
  },
  {
    key: 'plpgsql',
    name: 'plpgsql',
    description: 'Procedural language for PostgreSQL functions and triggers.',
    category: 'Language',
  },
  {
    key: 'fuzzystrmatch',
    name: 'fuzzystrmatch',
    description: 'String distance and phonetic matching functions.',
    category: 'Search',
  },
  {
    key: 'unaccent',
    name: 'unaccent',
    description: 'Remove accents from text for search and matching.',
    category: 'Search',
  },
]

const catalogByKey = new Map(
  POSTGRES_EXTENSION_CATALOG.map((entry) => [entry.key.toLowerCase(), entry]),
)

export function resolvePostgresExtensionInfo(
  key: string,
  apiMetadata?: Models.PostgresExtension[] | null,
): PostgresExtensionCatalogEntry {
  const normalized = key.trim().toLowerCase()

  const fromApi = apiMetadata?.find(
    (entry) => entry.key.trim().toLowerCase() === normalized,
  )
  if (fromApi) {
    return {
      key: fromApi.key,
      name: fromApi.name || fromApi.key,
      description: fromApi.description || '',
      category: fromApi.category || 'Extension',
    }
  }

  const catalog = catalogByKey.get(normalized)
  if (catalog) return catalog

  return {
    key,
    name: key,
    description: '',
    category: 'Extension',
  }
}

export type ExtensionSortColumn = 'key' | 'category' | 'status' | 'description'

export type ExtensionSortOrder = 'asc' | 'desc'

export type ExtensionStatusFilter =
  | 'all'
  | 'installed'
  | 'in_progress'

const EXTENSION_STATUS_SORT_RANK: Record<PostgresExtensionRowStatus, number> = {
  installed: 0,
  uninstalling: 1,
  installing: 2,
  available: 3,
}

function compareStrings(a: string, b: string, order: ExtensionSortOrder): number {
  const result = a.localeCompare(b, undefined, { sensitivity: 'base' })
  return order === 'asc' ? result : -result
}

export function sortPostgresExtensionRows(
  rows: PostgresExtensionRow[],
  sortBy: ExtensionSortColumn,
  sortOrder: ExtensionSortOrder,
): PostgresExtensionRow[] {
  const sorted = [...rows]
  sorted.sort((a, b) => {
    let primary = 0
    switch (sortBy) {
      case 'key':
        primary = compareStrings(a.key, b.key, sortOrder)
        break
      case 'category':
        primary = compareStrings(a.category || '', b.category || '', sortOrder)
        break
      case 'status':
        primary =
          (EXTENSION_STATUS_SORT_RANK[a.status] -
            EXTENSION_STATUS_SORT_RANK[b.status]) *
          (sortOrder === 'asc' ? 1 : -1)
        break
      case 'description':
        primary = compareStrings(a.description || '', b.description || '', sortOrder)
        break
    }

    if (primary !== 0) return primary

    return compareStrings(a.key, b.key, 'asc')
  })
  return sorted
}

export function matchesExtensionStatusFilter(
  status: PostgresExtensionRowStatus,
  filter: ExtensionStatusFilter,
): boolean {
  switch (filter) {
    case 'all':
      return true
    case 'installed':
      return status === 'installed' || status === 'uninstalling'
    case 'in_progress':
      return status === 'installing' || status === 'uninstalling'
  }
}

export function matchesExtensionCategoryFilter(
  category: string,
  selectedCategories: ReadonlySet<string>,
): boolean {
  if (selectedCategories.size === 0) return true
  return selectedCategories.has(category || 'Extension')
}

export function getPostgresExtensionCategories(
  rows: PostgresExtensionRow[],
): string[] {
  const categories = new Set<string>()
  for (const row of rows) {
    categories.add(row.category || 'Extension')
  }
  return Array.from(categories).sort((a, b) => a.localeCompare(b))
}

export type PostgresExtensionRowStatus =
  | 'installed'
  | 'available'
  | 'installing'
  | 'uninstalling'

export type PostgresExtensionRow = PostgresExtensionCatalogEntry & {
  status: PostgresExtensionRowStatus
}

export function buildPostgresExtensionRows(args: {
  installed: string[]
  available: string[]
  pendingInstalls: ReadonlySet<string>
  pendingUninstalls: ReadonlySet<string>
  metadata?: Models.PostgresExtension[] | null
}): PostgresExtensionRow[] {
  const {
    installed,
    available,
    pendingInstalls,
    pendingUninstalls,
    metadata,
  } = args
  const installedSet = new Set(installed)
  const availableSet = new Set(available)
  const keys = new Set<string>()

  for (const key of installed) keys.add(key)
  for (const key of available) keys.add(key)
  for (const key of pendingInstalls) keys.add(key)
  for (const key of pendingUninstalls) keys.add(key)

  const rows: PostgresExtensionRow[] = []

  for (const key of keys) {
    const info = resolvePostgresExtensionInfo(key, metadata)
    let status: PostgresExtensionRowStatus = 'available'

    if (pendingInstalls.has(key)) {
      status = 'installing'
    } else if (pendingUninstalls.has(key)) {
      status = 'uninstalling'
    } else if (installedSet.has(key)) {
      status = 'installed'
    } else if (availableSet.has(key)) {
      status = 'available'
    }

    rows.push({ ...info, status })
  }

  return sortPostgresExtensionRows(rows, 'status', 'asc')
}
