export const POSTGRES_DB_KIND = 'postgres' as const

export type PostgresNavParams = {
  projectId: string
  databaseId: string
  tableId?: string
}

/** Database-level views (sidebar nav below schemas/tables). */
export type PostgresDatabaseTab =
  | 'visualizer'
  | 'monitor'
  | 'db-security'
  | 'insights'
  | 'backups'
  | 'connections'
  | 'settings'

export const POSTGRES_DATABASE_TAB_LABELS: Record<
  PostgresDatabaseTab,
  string
> = {
  visualizer: 'Visualizer',
  monitor: 'Monitor',
  'db-security': 'Security',
  insights: 'Insights',
  backups: 'Backups',
  connections: 'Connections',
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
    tables(p: { tableId?: string }) {
      return {
        to: '/projects/$projectId/databases/postgres/$databaseId/tables/$tableId/rows' as const,
        params: {
          ...base,
          tableId: p.tableId ?? '-',
        },
      }
    },
    editor() {
      return this.tables({ tableId: '-' })
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
    dbSecurity() {
      return {
        to: '/projects/$projectId/databases/postgres/$databaseId/db-security' as const,
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
    settings() {
      return {
        to: '/projects/$projectId/databases/postgres/$databaseId/settings' as const,
        params: base,
      }
    },
  }
}

export function postgresDatabaseHome(params: PostgresNavParams) {
  return postgresNav(params).tables({ tableId: params.tableId })
}

export function postgresTableRows(params: PostgresNavParams & { tableId: string }) {
  return postgresDatabaseHome(params)
}
