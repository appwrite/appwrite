/**
 * Central catalog of console preference keys (account + team).
 *
 * All managed prefs should be registered here so the debug menu can show a
 * structured known/unknown view. Key constants stay in their domain modules;
 * this file is the single enumeration used for discovery and classification.
 *
 * When adding a new preference:
 * 1. Define the key constant (and parse/build helpers) in the domain module.
 * 2. Add an entry to PREFS_CATALOG below.
 */

import { USER_PREFS_KEY_COVER_GENERATIONS } from '@/lib/cover-generator/cover-generation-prefs'
import { USER_PREFS_KEY_DIAGRAM_GENERATIONS } from '@/lib/diagram-generator/generation-prefs'
import { USER_PREFS_KEY_API_REFERENCE_UI } from '@/lib/docs/references/api-reference-ui-prefs'
import { INIT_TICKET_PREFS_KEY_PREFIX } from '@/lib/init/ticket-prefs'
import { USER_PREFS_KEY_REALTIME_DEBUGGER_PREFIX } from '@/lib/realtime/debugger-prefs'
import { TEAM_PREFS_KEY_PINNED_PROJECT_IDS } from '@/lib/team-prefs-keys'
import {
  USER_PREFS_KEY_AI_CHAT_ACTIVE_CONVERSATION_ID,
  USER_PREFS_KEY_AI_CHAT_CONVERSATIONS_WIDTH_PX,
  USER_PREFS_KEY_AI_CHAT_EXPANDED,
  USER_PREFS_KEY_AI_CHAT_PANEL_OPEN,
  USER_PREFS_KEY_AI_CHAT_PANEL_WIDTH_PX,
  USER_PREFS_KEY_AI_CHAT_PINNED_CONVERSATION_IDS,
  USER_PREFS_KEY_API_EXPLORER_COLUMNS_LAYOUT,
  USER_PREFS_KEY_API_EXPLORER_EXPANDED_PRODUCT_GROUP,
  USER_PREFS_KEY_API_EXPLORER_RESPONSE_SPLIT_LAYOUT,
  USER_PREFS_KEY_AUTH_PASSWORD_STRENGTH_COMPLIANCE_OPEN,
  USER_PREFS_KEY_BUILD_NOTIFICATIONS_OPTED_OUT,
  USER_PREFS_KEY_CLI_SHELL_HEIGHT_PX,
  USER_PREFS_KEY_CLI_SHELL_HISTORY_PREFIX,
  USER_PREFS_KEY_CLI_SHELL_OPEN,
  USER_PREFS_KEY_CLI_SHELL_SESSIONS_PREFIX,
  USER_PREFS_KEY_CLI_SHELL_SESSIONS_SIDEBAR_WIDTH_PX,
  USER_PREFS_KEY_COMMUNITY_SUPPORT,
  USER_PREFS_KEY_CONNECT_PROJECT_TAB,
  USER_PREFS_KEY_CONSOLE_IMPERSONATION_RECENT,
  USER_PREFS_KEY_COVER_GENERATOR_COLUMNS_LAYOUT,
  USER_PREFS_KEY_DATABASE_TABLE_ROW_COLUMN_WIDTHS,
  USER_PREFS_KEY_DATABASES_SIDEBAR_WIDTH,
  USER_PREFS_KEY_DIAGRAM_GENERATOR_PROPERTIES_SPLIT_LAYOUT,
  USER_PREFS_KEY_FEATURE_NOTIFICATIONS,
  USER_PREFS_KEY_FUNCTIONS_LIST_VIEW_MODE,
  USER_PREFS_KEY_GENERATOR_PANEL_VISIBILITY,
  USER_PREFS_KEY_IMAGE_TRANSFORM_PRESETS,
  USER_PREFS_KEY_ORG_PROJECTS_LIST_VIEW_MODE,
  USER_PREFS_KEY_ORGANIZATION,
  USER_PREFS_KEY_POSTGRES_QUERY_HISTORY_PREFIX,
  USER_PREFS_KEY_POSTGRES_SAVED_QUERIES_PREFIX,
  USER_PREFS_KEY_POSTGRES_SAVED_QUERIES_SCOPE_PREFIX,
  USER_PREFS_KEY_POSTGRES_SAVED_QUERIES_SORT_PREFIX,
  USER_PREFS_KEY_POSTGRES_SELECTED_SCHEMA_PREFIX,
  USER_PREFS_KEY_POSTGRES_SIDEBAR_PANEL_PREFIX,
  USER_PREFS_KEY_POSTGRES_SIDEBAR_TABLES_SORT_PREFIX,
  USER_PREFS_KEY_POSTGRES_SQL_EDITOR_HEIGHT,
  USER_PREFS_KEY_POSTGRES_SQL_EDITOR_STATE_PREFIX,
  USER_PREFS_KEY_MYSQL_QUERY_HISTORY_PREFIX,
  USER_PREFS_KEY_MYSQL_SAVED_QUERIES_PREFIX,
  USER_PREFS_KEY_MYSQL_SAVED_QUERIES_SCOPE_PREFIX,
  USER_PREFS_KEY_MYSQL_SAVED_QUERIES_SORT_PREFIX,
  USER_PREFS_KEY_MYSQL_SELECTED_SCHEMA_PREFIX,
  USER_PREFS_KEY_MYSQL_SIDEBAR_PANEL_PREFIX,
  USER_PREFS_KEY_MYSQL_SIDEBAR_TABLES_SORT_PREFIX,
  USER_PREFS_KEY_MYSQL_SQL_EDITOR_HEIGHT,
  USER_PREFS_KEY_MYSQL_SQL_EDITOR_STATE_PREFIX,
  USER_PREFS_KEY_RIGHT_PANE_WIDTH_PX,
  USER_PREFS_KEY_SAVED_FILTERS_PREFIX,
  USER_PREFS_KEY_SIDEBAR_COLLAPSED,
  USER_PREFS_KEY_SITES_LIST_VIEW_MODE,
  USER_PREFS_KEY_STORAGE_FILES_LIST_COLUMN_WIDTHS,
  USER_PREFS_KEY_STORAGE_FILES_TABLE_PANE_WIDTH_PX,
  USER_PREFS_KEY_STORAGE_SIDEBAR_WIDTH,
  USER_PREFS_KEY_STORES_LIST_VIEW_MODE,
  USER_PREFS_KEY_TABLESDB_ROWS_LIST_COLUMNS_PREFIX,
  USER_PREFS_KEY_USAGE_CHART_DATE_RANGE,
  USER_PREFS_KEY_USAGE_CHART_INTERVAL,
} from '@/lib/user-prefs-keys'

export type PrefsCatalogScope = 'account' | 'team' | 'both'

export type PrefsCatalogEntry = {
  /** Stable id for React keys / docs. */
  id: string
  /** Where this key is read/written. */
  scope: PrefsCatalogScope
  /** Exact preference key. Mutually exclusive with `prefix`. */
  key?: string
  /**
   * Dynamic key prefix. Matches `prefix` or `prefix.<suffix>`.
   * Mutually exclusive with `key`.
   */
  prefix?: string
  /** Short human description for the debug structured view. */
  description: string
  /** Grouping label in the structured view. */
  category: string
  /** Known but deprecated / migrated key. Still treated as known (green). */
  legacy?: boolean
}

/**
 * Canonical list of preferences this console manages.
 * Add new prefs here when introducing parse/build helpers elsewhere.
 */
export const PREFS_CATALOG: readonly PrefsCatalogEntry[] = [
  // Account: identity / routing
  {
    id: 'organization',
    scope: 'account',
    key: USER_PREFS_KEY_ORGANIZATION,
    description: 'Preferred organization ID for redirects and console context.',
    category: 'Account',
  },
  {
    id: 'featureNotifications',
    scope: 'account',
    key: USER_PREFS_KEY_FEATURE_NOTIFICATIONS,
    description: 'Dismissed coming-soon feature IDs (comma-separated).',
    category: 'Account',
  },

  // Layout / chrome
  {
    id: 'sidebarCollapsed',
    scope: 'account',
    key: USER_PREFS_KEY_SIDEBAR_COLLAPSED,
    description: 'Main navigation sidebar collapsed state.',
    category: 'Layout',
  },
  {
    id: 'connectProjectTab',
    scope: 'account',
    key: USER_PREFS_KEY_CONNECT_PROJECT_TAB,
    description: 'Last selected tab in the Connect project dialog.',
    category: 'Layout',
  },
  {
    id: 'sidebarCollapsedLegacy',
    scope: 'account',
    key: 'sidebarCollapsed',
    description: 'Legacy sidebar collapsed key (migrated to console.sidebarCollapsed).',
    category: 'Layout',
    legacy: true,
  },
  {
    id: 'rightPaneWidth',
    scope: 'account',
    key: USER_PREFS_KEY_RIGHT_PANE_WIDTH_PX,
    description: 'Shared right pane width in pixels.',
    category: 'Layout',
  },
  {
    id: 'aiChatPanelOpen',
    scope: 'account',
    key: USER_PREFS_KEY_AI_CHAT_PANEL_OPEN,
    description: 'AI assistant panel open state.',
    category: 'Layout',
  },
  {
    id: 'aiChatExpanded',
    scope: 'account',
    key: USER_PREFS_KEY_AI_CHAT_EXPANDED,
    description: 'AI assistant fullscreen (expanded) state.',
    category: 'Layout',
  },
  {
    id: 'aiChatActiveConversationId',
    scope: 'account',
    key: USER_PREFS_KEY_AI_CHAT_ACTIVE_CONVERSATION_ID,
    description: 'Last viewed AI assistant conversation id.',
    category: 'Layout',
  },
  {
    id: 'aiChatPinnedConversationIds',
    scope: 'account',
    key: USER_PREFS_KEY_AI_CHAT_PINNED_CONVERSATION_IDS,
    description:
      'Pinned AI assistant conversation ids (JSON array; order is sort order).',
    category: 'Layout',
  },
  {
    id: 'aiChatConversationsWidth',
    scope: 'account',
    key: USER_PREFS_KEY_AI_CHAT_CONVERSATIONS_WIDTH_PX,
    description: 'AI assistant conversations sidebar width in pixels.',
    category: 'Layout',
  },
  {
    id: 'aiChatPanelWidth',
    scope: 'account',
    key: USER_PREFS_KEY_AI_CHAT_PANEL_WIDTH_PX,
    description: 'AI assistant panel width in pixels.',
    category: 'Layout',
  },
  {
    id: 'cliShellOpen',
    scope: 'account',
    key: USER_PREFS_KEY_CLI_SHELL_OPEN,
    description: 'Browser CLI shell expanded state.',
    category: 'CLI',
  },
  {
    id: 'cliShellHeight',
    scope: 'account',
    key: USER_PREFS_KEY_CLI_SHELL_HEIGHT_PX,
    description: 'Browser CLI shell height in pixels.',
    category: 'CLI',
  },
  {
    id: 'cliShellSessionsSidebarWidth',
    scope: 'account',
    key: USER_PREFS_KEY_CLI_SHELL_SESSIONS_SIDEBAR_WIDTH_PX,
    description: 'CLI sessions list sidebar width in pixels.',
    category: 'CLI',
  },
  {
    id: 'cliShellHistory',
    scope: 'account',
    prefix: USER_PREFS_KEY_CLI_SHELL_HISTORY_PREFIX,
    description: 'Per-project CLI command history (JSON string array).',
    category: 'CLI',
  },
  {
    id: 'cliShellSessions',
    scope: 'account',
    prefix: USER_PREFS_KEY_CLI_SHELL_SESSIONS_PREFIX,
    description: 'Per-project CLI session layout (JSON).',
    category: 'CLI',
  },

  // Filters & list modes
  {
    id: 'savedFilters',
    scope: 'both',
    prefix: USER_PREFS_KEY_SAVED_FILTERS_PREFIX,
    description: 'Saved filter presets per list view scope (JSON SavedFilter[]).',
    category: 'Filters',
  },
  {
    id: 'functionsListViewMode',
    scope: 'account',
    key: USER_PREFS_KEY_FUNCTIONS_LIST_VIEW_MODE,
    description: 'Functions list view mode (list or grid).',
    category: 'List views',
  },
  {
    id: 'sitesListViewMode',
    scope: 'account',
    key: USER_PREFS_KEY_SITES_LIST_VIEW_MODE,
    description: 'Sites list view mode (list or grid).',
    category: 'List views',
  },
  {
    id: 'orgProjectsListViewMode',
    scope: 'account',
    key: USER_PREFS_KEY_ORG_PROJECTS_LIST_VIEW_MODE,
    description: 'Organization projects list view mode (list or grid).',
    category: 'List views',
  },
  {
    id: 'storesListViewMode',
    scope: 'account',
    key: USER_PREFS_KEY_STORES_LIST_VIEW_MODE,
    description: 'Stores list view mode (list or grid).',
    category: 'List views',
  },

  // Storage
  {
    id: 'imageTransformPresets',
    scope: 'both',
    key: USER_PREFS_KEY_IMAGE_TRANSFORM_PRESETS,
    description: 'Saved image transform wizard presets (JSON).',
    category: 'Storage',
  },
  {
    id: 'storageSidebarWidth',
    scope: 'account',
    key: USER_PREFS_KEY_STORAGE_SIDEBAR_WIDTH,
    description: 'Storage buckets sidebar width in pixels.',
    category: 'Storage',
  },
  {
    id: 'storageFilesListColumnWidths',
    scope: 'account',
    key: USER_PREFS_KEY_STORAGE_FILES_LIST_COLUMN_WIDTHS,
    description: 'Storage files table column widths (JSON).',
    category: 'Storage',
  },
  {
    id: 'storageFilesTablePaneWidth',
    scope: 'account',
    key: USER_PREFS_KEY_STORAGE_FILES_TABLE_PANE_WIDTH_PX,
    description: 'Storage files split-pane table width in pixels.',
    category: 'Storage',
  },

  // Databases
  {
    id: 'databasesSidebarWidth',
    scope: 'account',
    key: USER_PREFS_KEY_DATABASES_SIDEBAR_WIDTH,
    description: 'Databases sidebar width in pixels.',
    category: 'Databases',
  },
  {
    id: 'databaseTableRowColumnWidths',
    scope: 'account',
    key: USER_PREFS_KEY_DATABASE_TABLE_ROW_COLUMN_WIDTHS,
    description: 'TablesDB / DocumentsDB row column widths (JSON).',
    category: 'Databases',
  },
  {
    id: 'tablesDbRowsListColumns',
    scope: 'account',
    prefix: USER_PREFS_KEY_TABLESDB_ROWS_LIST_COLUMNS_PREFIX,
    description: 'Per-table visible columns for TablesDB rows list (JSON).',
    category: 'Databases',
  },
  {
    id: 'postgresSavedQueries',
    scope: 'both',
    prefix: USER_PREFS_KEY_POSTGRES_SAVED_QUERIES_PREFIX,
    description: 'Per-database saved PostgreSQL queries (JSON).',
    category: 'PostgreSQL',
  },
  {
    id: 'postgresQueryHistory',
    scope: 'account',
    prefix: USER_PREFS_KEY_POSTGRES_QUERY_HISTORY_PREFIX,
    description: 'Per-database PostgreSQL query history (JSON).',
    category: 'PostgreSQL',
  },
  {
    id: 'postgresSavedQueriesScope',
    scope: 'both',
    prefix: USER_PREFS_KEY_POSTGRES_SAVED_QUERIES_SCOPE_PREFIX,
    description: 'Per-database saved queries scope (user or team).',
    category: 'PostgreSQL',
  },
  {
    id: 'postgresSelectedSchema',
    scope: 'both',
    prefix: USER_PREFS_KEY_POSTGRES_SELECTED_SCHEMA_PREFIX,
    description: 'Per-database selected PostgreSQL schema.',
    category: 'PostgreSQL',
  },
  {
    id: 'postgresSavedQueriesSort',
    scope: 'both',
    prefix: USER_PREFS_KEY_POSTGRES_SAVED_QUERIES_SORT_PREFIX,
    description: 'Per-database saved queries sort order.',
    category: 'PostgreSQL',
  },
  {
    id: 'postgresSidebarTablesSort',
    scope: 'both',
    prefix: USER_PREFS_KEY_POSTGRES_SIDEBAR_TABLES_SORT_PREFIX,
    description: 'Per-database sidebar tables sort order.',
    category: 'PostgreSQL',
  },
  {
    id: 'postgresSidebarPanel',
    scope: 'both',
    prefix: USER_PREFS_KEY_POSTGRES_SIDEBAR_PANEL_PREFIX,
    description: 'Per-database sidebar panel state (JSON).',
    category: 'PostgreSQL',
  },
  {
    id: 'postgresSqlEditorState',
    scope: 'both',
    prefix: USER_PREFS_KEY_POSTGRES_SQL_EDITOR_STATE_PREFIX,
    description: 'Per-database SQL editor draft state (JSON).',
    category: 'PostgreSQL',
  },
  {
    id: 'postgresSqlEditorHeight',
    scope: 'account',
    key: USER_PREFS_KEY_POSTGRES_SQL_EDITOR_HEIGHT,
    description: 'PostgreSQL SQL editor height in pixels.',
    category: 'PostgreSQL',
  },
  {
    id: 'mysqlSavedQueries',
    scope: 'both',
    prefix: USER_PREFS_KEY_MYSQL_SAVED_QUERIES_PREFIX,
    description: 'Per-database saved MySQL queries (JSON).',
    category: 'MySQL',
  },
  {
    id: 'mysqlQueryHistory',
    scope: 'account',
    prefix: USER_PREFS_KEY_MYSQL_QUERY_HISTORY_PREFIX,
    description: 'Per-database MySQL query history (JSON).',
    category: 'MySQL',
  },
  {
    id: 'mysqlSavedQueriesScope',
    scope: 'both',
    prefix: USER_PREFS_KEY_MYSQL_SAVED_QUERIES_SCOPE_PREFIX,
    description: 'Per-database saved queries scope (user or team).',
    category: 'MySQL',
  },
  {
    id: 'mysqlSelectedSchema',
    scope: 'both',
    prefix: USER_PREFS_KEY_MYSQL_SELECTED_SCHEMA_PREFIX,
    description: 'Per-database selected MySQL schema.',
    category: 'MySQL',
  },
  {
    id: 'mysqlSavedQueriesSort',
    scope: 'both',
    prefix: USER_PREFS_KEY_MYSQL_SAVED_QUERIES_SORT_PREFIX,
    description: 'Per-database saved queries sort order.',
    category: 'MySQL',
  },
  {
    id: 'mysqlSidebarTablesSort',
    scope: 'both',
    prefix: USER_PREFS_KEY_MYSQL_SIDEBAR_TABLES_SORT_PREFIX,
    description: 'Per-database sidebar tables sort order.',
    category: 'MySQL',
  },
  {
    id: 'mysqlSidebarPanel',
    scope: 'both',
    prefix: USER_PREFS_KEY_MYSQL_SIDEBAR_PANEL_PREFIX,
    description: 'Per-database sidebar panel state (JSON).',
    category: 'MySQL',
  },
  {
    id: 'mysqlSqlEditorState',
    scope: 'both',
    prefix: USER_PREFS_KEY_MYSQL_SQL_EDITOR_STATE_PREFIX,
    description: 'Per-database SQL editor draft state (JSON).',
    category: 'MySQL',
  },
  {
    id: 'mysqlSqlEditorHeight',
    scope: 'account',
    key: USER_PREFS_KEY_MYSQL_SQL_EDITOR_HEIGHT,
    description: 'MySQL SQL editor height in pixels.',
    category: 'MySQL',
  },

  // Auth
  {
    id: 'authPasswordStrengthComplianceOpen',
    scope: 'account',
    key: USER_PREFS_KEY_AUTH_PASSWORD_STRENGTH_COMPLIANCE_OPEN,
    description: 'Auth password strength compliance section expanded.',
    category: 'Auth',
  },

  // Usage
  {
    id: 'usageChartDateRange',
    scope: 'account',
    key: USER_PREFS_KEY_USAGE_CHART_DATE_RANGE,
    description: 'Usage chart date range selection (JSON).',
    category: 'Usage',
  },
  {
    id: 'usageChartInterval',
    scope: 'account',
    key: USER_PREFS_KEY_USAGE_CHART_INTERVAL,
    description: 'Usage chart interval (15m, 1h, or 1d).',
    category: 'Usage',
  },

  // Generators / explorer
  {
    id: 'coverGeneratorColumnsLayout',
    scope: 'account',
    key: USER_PREFS_KEY_COVER_GENERATOR_COLUMNS_LAYOUT,
    description: 'Cover generator column split percentages (JSON).',
    category: 'Generators',
  },
  {
    id: 'coverGeneratorGenerations',
    scope: 'account',
    key: USER_PREFS_KEY_COVER_GENERATIONS,
    description: 'Saved cover generator generations (JSON).',
    category: 'Generators',
  },
  {
    id: 'diagramGeneratorPropertiesSplit',
    scope: 'account',
    key: USER_PREFS_KEY_DIAGRAM_GENERATOR_PROPERTIES_SPLIT_LAYOUT,
    description: 'Diagram generator properties/layers split (JSON).',
    category: 'Generators',
  },
  {
    id: 'diagramGeneratorGenerations',
    scope: 'account',
    key: USER_PREFS_KEY_DIAGRAM_GENERATIONS,
    description: 'Saved diagram generator generations (JSON).',
    category: 'Generators',
  },
  {
    id: 'generatorPanelVisibility',
    scope: 'account',
    key: USER_PREFS_KEY_GENERATOR_PANEL_VISIBILITY,
    description: 'Generator left/right panel visibility (JSON).',
    category: 'Generators',
  },
  {
    id: 'apiExplorerColumnsLayout',
    scope: 'account',
    key: USER_PREFS_KEY_API_EXPLORER_COLUMNS_LAYOUT,
    description: 'API Explorer column split percentages (JSON).',
    category: 'API Explorer',
  },
  {
    id: 'apiExplorerResponseSplit',
    scope: 'account',
    key: USER_PREFS_KEY_API_EXPLORER_RESPONSE_SPLIT_LAYOUT,
    description: 'API Explorer request/response split (JSON).',
    category: 'API Explorer',
  },
  {
    id: 'apiExplorerExpandedProductGroup',
    scope: 'account',
    key: USER_PREFS_KEY_API_EXPLORER_EXPANDED_PRODUCT_GROUP,
    description: 'Expanded product group id in API Explorer services list.',
    category: 'API Explorer',
  },
  {
    id: 'apiReferenceUi',
    scope: 'account',
    key: USER_PREFS_KEY_API_REFERENCE_UI,
    description: 'Docs API reference explorer UI state (JSON).',
    category: 'API Explorer',
  },

  // Realtime / notifications / community
  {
    id: 'realtimeDebugger',
    scope: 'account',
    prefix: USER_PREFS_KEY_REALTIME_DEBUGGER_PREFIX,
    description: 'Per-project Realtime debugger subscriptions (JSON).',
    category: 'Realtime',
  },
  {
    id: 'buildNotificationsOptedOut',
    scope: 'account',
    key: USER_PREFS_KEY_BUILD_NOTIFICATIONS_OPTED_OUT,
    description: 'User dismissed the build completion notifications prompt.',
    category: 'Notifications',
  },
  {
    id: 'communitySupport',
    scope: 'account',
    key: USER_PREFS_KEY_COMMUNITY_SUPPORT,
    description: 'Community support wizard state (JSON).',
    category: 'Community',
  },

  // Impersonation
  {
    id: 'impersonationRecentUsers',
    scope: 'account',
    key: USER_PREFS_KEY_CONSOLE_IMPERSONATION_RECENT,
    description:
      'Recent impersonation target user IDs only (JSON string[]). Display labels live in localStorage.',
    category: 'Impersonation',
  },

  // Init
  {
    id: 'initTicket',
    scope: 'account',
    prefix: INIT_TICKET_PREFS_KEY_PREFIX,
    description: 'Per-event Init ticket prefs (JSON).',
    category: 'Init',
  },

  // Team-only
  {
    id: 'pinnedProjectIds',
    scope: 'team',
    key: TEAM_PREFS_KEY_PINNED_PROJECT_IDS,
    description: 'Pinned project IDs for the organization (JSON string[]).',
    category: 'Organization',
  },
] as const

export type PrefsRuntimeScope = 'account' | 'team'

function entryAppliesToScope(
  entry: PrefsCatalogEntry,
  scope: PrefsRuntimeScope,
): boolean {
  return entry.scope === scope || entry.scope === 'both'
}

function matchesEntry(key: string, entry: PrefsCatalogEntry): boolean {
  if (entry.key != null) return key === entry.key
  if (entry.prefix != null) {
    return key === entry.prefix || key.startsWith(`${entry.prefix}.`)
  }
  return false
}

/** Resolve the catalog entry for a stored preference key, if known. */
export function matchKnownPref(
  key: string,
  scope: PrefsRuntimeScope,
): PrefsCatalogEntry | null {
  const candidates = PREFS_CATALOG.filter((entry) =>
    entryAppliesToScope(entry, scope),
  )
  const exact = candidates.find((entry) => entry.key != null && entry.key === key)
  if (exact) return exact
  return (
    candidates.find(
      (entry) => entry.prefix != null && matchesEntry(key, entry),
    ) ?? null
  )
}

export function isKnownPrefKey(key: string, scope: PrefsRuntimeScope): boolean {
  return matchKnownPref(key, scope) != null
}

export type ClassifiedPrefEntry = {
  key: string
  value: unknown
  known: boolean
  catalog: PrefsCatalogEntry | null
  preview: string
}

function previewPrefValue(value: unknown): string {
  if (typeof value === 'string') {
    return value.length > 120 ? `${value.slice(0, 120)}…` : value
  }
  try {
    const raw = JSON.stringify(value)
    return raw.length > 120 ? `${raw.slice(0, 120)}…` : raw
  } catch {
    return String(value)
  }
}

/**
 * Classify stored prefs against the catalog.
 * Unknown keys first (debug priority), then known keys by category then key.
 */
export function classifyPrefs(
  prefs: Record<string, unknown> | null | undefined,
  scope: PrefsRuntimeScope,
): ClassifiedPrefEntry[] {
  const entries = Object.entries(prefs ?? {}).map(([key, value]) => {
    const catalog = matchKnownPref(key, scope)
    return {
      key,
      value,
      known: catalog != null,
      catalog,
      preview: previewPrefValue(value),
    }
  })

  return entries.sort((a, b) => {
    // Unknown first so orphan keys are obvious in the debug structured view.
    if (a.known !== b.known) return a.known ? 1 : -1
    const catA = a.catalog?.category ?? ''
    const catB = b.catalog?.category ?? ''
    if (catA !== catB) return catA.localeCompare(catB)
    return a.key.localeCompare(b.key)
  })
}

export function summarizePrefsClassification(
  entries: ClassifiedPrefEntry[],
): { known: number; unknown: number; total: number } {
  let known = 0
  let unknown = 0
  for (const entry of entries) {
    if (entry.known) known += 1
    else unknown += 1
  }
  return { known, unknown, total: entries.length }
}
