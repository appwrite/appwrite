export const POSTGRES_DB_KIND = 'postgres' as const

export type PostgresNavParams = {
  projectId: string
  databaseId: string
  tableId?: string
}

export type PostgresTableTab = 'rows' | 'columns' | 'indexes' | 'settings'

/** Database-level views (sidebar nav below schemas/tables). */
export type PostgresDatabaseTab =
  | 'sql'
  | 'visualizer'
  | 'monitor'
  | 'insights'
  | 'backups'
  | 'connections'
  | 'connect'
  | 'settings'

export const POSTGRES_DATABASE_TAB_LABELS: Record<
  PostgresDatabaseTab,
  string
> = {
  sql: 'SQL editor',
  visualizer: 'Visualizer',
  monitor: 'Monitor',
  insights: 'Insights',
  backups: 'Backups',
  connections: 'Connections',
  connect: 'Connect',
  settings: 'Settings',
}

export function postgresTableId(schema: string, table: string): string {
  return `${schema}.${table}`
}

export function parsePostgresTableId(tableId: string): {
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

export function quotePostgresIdentifier(identifier: string): string {
  return `"${identifier.replace(/"/g, '""')}"`
}

type PostgresNavBase = {
  projectId: string
  databaseId: string
}

function postgresNavBase(params: PostgresNavBase) {
  return {
    projectId: params.projectId,
    databaseId: params.databaseId,
  }
}

/** Typed route helpers for dedicated PostgreSQL databases. */
export function postgresNav(params: PostgresNavBase) {
  const base = postgresNavBase(params)

  return {
    table(p: { tableId: string }) {
      const tableBase = { ...base, tableId: p.tableId }
      return {
        rows() {
          return {
            to: '/projects/$projectId/databases/postgres/$databaseId/tables/$tableId/rows' as const,
            params: tableBase,
          }
        },
        columns() {
          return {
            to: '/projects/$projectId/databases/postgres/$databaseId/tables/$tableId/columns' as const,
            params: tableBase,
          }
        },
        indexes() {
          return {
            to: '/projects/$projectId/databases/postgres/$databaseId/tables/$tableId/indexes' as const,
            params: tableBase,
          }
        },
        settings() {
          return {
            to: '/projects/$projectId/databases/postgres/$databaseId/tables/$tableId/settings' as const,
            params: tableBase,
          }
        },
      }
    },
    tables(p: { tableId?: string }) {
      return this.table({ tableId: p.tableId ?? '-' }).rows()
    },
    sql() {
      return {
        to: '/projects/$projectId/databases/postgres/$databaseId/sql' as const,
        params: base,
      }
    },
    editor() {
      return this.sql()
    },
    visualizer() {
      return {
        to: '/projects/$projectId/databases/postgres/$databaseId/visualizer' as const,
        params: base,
      }
    },
    monitor() {
      return {
        to: '/projects/$projectId/databases/postgres/$databaseId/monitor' as const,
        params: base,
      }
    },
    insights() {
      return {
        to: '/projects/$projectId/databases/postgres/$databaseId/insights' as const,
        params: base,
      }
    },
    backups() {
      return {
        to: '/projects/$projectId/databases/postgres/$databaseId/backups' as const,
        params: base,
      }
    },
    connections() {
      return {
        to: '/projects/$projectId/databases/postgres/$databaseId/connections' as const,
        params: base,
      }
    },
    connect() {
      return {
        to: '/projects/$projectId/databases/postgres/$databaseId/connect' as const,
        params: base,
      }
    },
    settings() {
      return {
        to: '/projects/$projectId/databases/postgres/$databaseId/settings' as const,
        params: base,
      }
    },
  }
}

export function postgresDatabaseHome(params: PostgresNavParams) {
  if (!params.tableId || params.tableId === '-') {
    return postgresNav(params).sql()
  }
  return postgresNav(params).table({ tableId: params.tableId }).rows()
}

export function postgresTableRows(params: PostgresNavParams & { tableId: string }) {
  return postgresNav(params).table({ tableId: params.tableId }).rows()
}

export type PostgresSqlWorkbenchRoute =
  | { mode: 'sql' }
  | { mode: 'table-rows'; tableId: string }

/** Matches `/sql` and `/tables/$tableId/rows` so the SQL workbench can stay mounted. */
export function parsePostgresSqlWorkbenchRoute(
  pathname: string,
): PostgresSqlWorkbenchRoute | null {
  if (
    /\/projects\/[^/]+\/databases\/postgres\/[^/]+\/sql\/?$/.test(pathname)
  ) {
    return { mode: 'sql' }
  }

  const rowsMatch = pathname.match(
    /\/projects\/[^/]+\/databases\/postgres\/[^/]+\/tables\/([^/]+)\/rows\/?$/,
  )
  if (rowsMatch) {
    return {
      mode: 'table-rows',
      tableId: decodeURIComponent(rowsMatch[1]),
    }
  }

  return null
}
