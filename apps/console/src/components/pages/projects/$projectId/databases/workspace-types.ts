import type { ReactNode } from 'react'

/**
 * Route-level workspace props and database-level tab ids shared by all
 * database products. No product implementation lives here - only URL/tab
 * vocabulary so routes and `Workspace.tsx` stay typed.
 */
export type OverviewContentTab =
  | 'tables'
  | 'backups'
  | 'export-import'
  | 'security'
  | 'settings'
  | 'visualizer'
  | 'monitor'
  | 'browser'

/** Database-level tab when embedded in the table workspace */
export type DatabaseTabId =
  | 'visualizer'
  | 'monitor'
  | 'backups'
  | 'export-import'
  | 'db-security'
  | 'settings'
  | 'browser'

export interface WorkspaceProps {
  databaseId: string
  tableId: string
  activeTab:
    | 'rows'
    | 'documents'
    | 'columns'
    | 'indexes'
    | 'security'
    | 'settings'
  databaseTab?: DatabaseTabId
  /** Nested settings routes render their shell here instead of Overview. */
  children?: ReactNode
}

export const DATABASE_TAB_TO_OVERVIEW: Record<
  DatabaseTabId,
  OverviewContentTab
> = {
  visualizer: 'visualizer',
  monitor: 'monitor',
  backups: 'backups',
  'export-import': 'export-import',
  'db-security': 'security',
  settings: 'settings',
  browser: 'browser',
}

export const DATABASE_TAB_LABELS: Record<DatabaseTabId, string> = {
  visualizer: 'Visualizer',
  monitor: 'Monitor',
  backups: 'Backups',
  'export-import': 'Export / Import',
  'db-security': 'Security',
  settings: 'Settings',
  browser: 'Browser',
}
