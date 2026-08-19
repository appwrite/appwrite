export const MYSQL_DB_KIND = 'mysql' as const

export type MysqlNavParams = {
  projectId: string
  databaseId: string
  tableId?: string
}

export type MysqlTableTab =
  | 'rows'
  | 'columns'
  | 'indexes'
  | 'security'
  | 'settings'

/** Database-level views (sidebar nav below schemas/tables). */
export type MysqlDatabaseTab =
  | 'sql'
  | 'visualizer'
  | 'monitor'
  | 'backups'
  | 'connections'
  | 'roles'
  | 'settings'

export const MYSQL_DATABASE_TAB_LABELS: Record<
  MysqlDatabaseTab,
  string
> = {
  sql: 'SQL editor',
  visualizer: 'Visualizer',
  monitor: 'Monitor',
  backups: 'Backups',
  connections: 'Connections',
  roles: 'Roles',
  settings: 'Settings',
}

export function mysqlTableId(schema: string, table: string): string {
  return `${schema}.${table}`
}

export function parseMysqlTableId(tableId: string): {
  schema: string
  table: string
} {
  const dot = tableId.indexOf('.')
  if (dot === -1) {
    return { schema: 'public', table: tableId }
  }
  return {
    schema: tableId.slice(0, dot),
    table: tableId.slice(dot + 1),
  }
}

/** Normalize table ids from URL params and sidebar selection to the same string. */
export function normalizeMysqlTableRouteId(tableId: string): string {
  try {
    return decodeURIComponent(tableId)
  } catch {
    return tableId
  }
}

export function quoteMysqlIdentifier(identifier: string): string {
  return `\`${identifier.replace(/`/g, '``')}\``
}

type MysqlNavBase = {
  projectId: string
  databaseId: string
}

function mysqlNavBase(params: MysqlNavBase) {
  return {
    projectId: params.projectId,
    databaseId: params.databaseId,
  }
}

function mysqlDatabaseTabRoute<const T extends string>(
  to: T,
  params: ReturnType<typeof mysqlNavBase>,
) {
  return {
    to,
    params,
    search: {} as Record<string, never>,
  }
}

/** Typed route helpers for dedicated MySQL databases. */
export function mysqlNav(params: MysqlNavBase) {
  const base = mysqlNavBase(params)

  return {
    table(p: { tableId: string }) {
      const tableBase = { ...base, tableId: p.tableId }
      return {
        rows() {
          return {
            to: '/projects/$projectId/databases/mysql/$databaseId/tables/$tableId/rows' as const,
            params: tableBase,
          }
        },
        columns() {
          return {
            to: '/projects/$projectId/databases/mysql/$databaseId/tables/$tableId/columns' as const,
            params: tableBase,
          }
        },
        indexes() {
          return {
            to: '/projects/$projectId/databases/mysql/$databaseId/tables/$tableId/indexes' as const,
            params: tableBase,
          }
        },
        security() {
          return {
            to: '/projects/$projectId/databases/mysql/$databaseId/tables/$tableId/security' as const,
            params: tableBase,
          }
        },
        settings() {
          return {
            to: '/projects/$projectId/databases/mysql/$databaseId/tables/$tableId/settings' as const,
            params: tableBase,
          }
        },
      }
    },
    tables(p: { tableId?: string }) {
      return this.table({ tableId: p.tableId ?? '-' }).rows()
    },
    sql() {
      return mysqlDatabaseTabRoute(
        '/projects/$projectId/databases/mysql/$databaseId/sql' as const,
        base,
      )
    },
    editor() {
      return this.sql()
    },
    visualizer() {
      return mysqlDatabaseTabRoute(
        '/projects/$projectId/databases/mysql/$databaseId/visualizer' as const,
        base,
      )
    },
    monitor() {
      return mysqlDatabaseTabRoute(
        '/projects/$projectId/databases/mysql/$databaseId/monitor' as const,
        base,
      )
    },
    backups() {
      return mysqlDatabaseTabRoute(
        '/projects/$projectId/databases/mysql/$databaseId/backups' as const,
        base,
      )
    },
    connections() {
      return mysqlDatabaseTabRoute(
        '/projects/$projectId/databases/mysql/$databaseId/connections' as const,
        base,
      )
    },
    roles() {
      return mysqlDatabaseTabRoute(
        '/projects/$projectId/databases/mysql/$databaseId/roles' as const,
        base,
      )
    },
    settings() {
      return mysqlDatabaseTabRoute(
        '/projects/$projectId/databases/mysql/$databaseId/settings' as const,
        base,
      )
    },
    computeSettings() {
      return mysqlDatabaseTabRoute(
        '/projects/$projectId/databases/mysql/$databaseId/settings/compute' as const,
        base,
      )
    },
  }
}

export function mysqlDatabaseHome(params: MysqlNavParams) {
  if (!params.tableId || params.tableId === '-') {
    return mysqlNav(params).sql()
  }
  return mysqlNav(params).table({ tableId: params.tableId }).rows()
}

export function mysqlTableRows(params: MysqlNavParams & { tableId: string }) {
  return mysqlNav(params).table({ tableId: params.tableId }).rows()
}

const MYSQL_DATABASE_TAB_SEGMENTS: MysqlDatabaseTab[] = [
  'sql',
  'visualizer',
  'monitor',
  'backups',
  'connections',
  'roles',
  'settings',
]

export function isMysqlDatabaseTabSegment(
  segment: string,
): segment is MysqlDatabaseTab {
  return MYSQL_DATABASE_TAB_SEGMENTS.includes(segment as MysqlDatabaseTab)
}

export function mysqlDatabaseTabLink(
  projectId: string,
  databaseId: string,
  tab: MysqlDatabaseTab,
) {
  const nav = mysqlNav({ projectId, databaseId })
  switch (tab) {
    case 'sql':
      return nav.sql()
    case 'visualizer':
      return nav.visualizer()
    case 'monitor':
      return nav.monitor()
    case 'backups':
      return nav.backups()
    case 'connections':
      return nav.connections()
    case 'roles':
      return nav.roles()
    case 'settings':
      return nav.settings()
  }
}

export function parseMysqlDatabaseTabFromPathname(
  pathname: string,
): MysqlDatabaseTab | undefined {
  const segments = pathname.split('/').filter(Boolean)
  const mysqlIndex = segments.indexOf('mysql')
  if (mysqlIndex === -1) return undefined

  const segment = segments[mysqlIndex + 2]
  if (!segment) return undefined
  if (segment === 'tables') return undefined

  return MYSQL_DATABASE_TAB_SEGMENTS.find((tab) => tab === segment)
}

export function parseMysqlTableTabFromPathname(
  pathname: string,
): MysqlTableTab | null {
  if (!pathname.includes('/tables/')) return null
  if (pathname.endsWith('/columns')) return 'columns'
  if (pathname.endsWith('/indexes')) return 'indexes'
  if (pathname.endsWith('/settings')) return 'settings'
  if (pathname.endsWith('/security')) return 'security'
  if (pathname.endsWith('/rows')) return 'rows'
  return null
}

export function parseMysqlShellRouteState(args: {
  pathname: string
  tableId?: string
}): {
  databaseTab?: MysqlDatabaseTab
  tableId?: string
} {
  const tableTab = parseMysqlTableTabFromPathname(args.pathname)
  if (tableTab) {
    return {
      tableId: args.tableId
        ? normalizeMysqlTableRouteId(args.tableId)
        : undefined,
    }
  }

  return {
    databaseTab: parseMysqlDatabaseTabFromPathname(args.pathname),
  }
}

